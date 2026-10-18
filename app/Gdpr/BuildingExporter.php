<?php

declare(strict_types=1);

namespace App\Gdpr;

use App\Enums\ExportStatus;
use App\Models\Apartment;
use App\Models\BuildingExport;
use App\Models\Incident;
use App\Models\LandParcel;
use App\Models\ManagerReport;
use App\Models\Riser;
use App\Models\RiserStatus;
use App\Models\Section;
use App\Models\SurveyRound;
use App\Tenancy\CurrentBuilding;
use App\Tenancy\Jobs\RestoreBuildingContext;
use App\Tenancy\Jobs\TenantAware;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;
use ZipArchive;

/**
 * House data portability (MVP-SPEC A.8, ADMIN-004, AC 13): a ZIP of JSON + CSV with sections, apartment
 * structure + cells, risers + links + confidence, land parcels, aggregate-only history and settings minus
 * secrets and controller contact. NEVER memberships, users, plates, notes, audit, per-floor report data or
 * apartment numbers inside report aggregates (stripped even when the sharing flags were on).
 */
final class BuildingExporter implements ShouldQueue, TenantAware
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    /** Keys that must never appear in the export (checked recursively before writing). */
    public const FORBIDDEN_KEYS = [
        'user_id', 'user_ids', 'email', 'login_handle', 'membership', 'memberships', 'plate', 'plates', 'note',
        'notes', 'private_note', 'contact', 'floors_reported', 'per_floor', 'apartment_numbers', 'reporter',
        'reporters', 'audit', 'snapshot',
    ];

    public function __construct(
        public readonly int $buildingId,
        public readonly string $exportId,
    ) {}

    public function tenantBuildingId(): int
    {
        return $this->buildingId;
    }

    /**
     * @return list<object>
     */
    public function middleware(): array
    {
        return [new RestoreBuildingContext];
    }

    public function handle(): void
    {
        $export = BuildingExport::query()->findOrFail($this->exportId);

        try {
            $data = self::strip($this->collect());
            $path = 'exports/buildings/'.$export->id.'.zip';
            $this->writeZip($path, $data);

            $export->forceFill([
                'status' => ExportStatus::Ready,
                'path' => $path,
                'expires_at' => now()->addHours((int) config('majas.exports.keep_hours', 24)),
            ])->save();
        } catch (Throwable $e) {
            $export->forceFill(['status' => ExportStatus::Failed])->save();

            throw $e;
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function collect(): array
    {
        $building = CurrentBuilding::get();
        $settings = $building->settings->toArray();
        unset($settings['controller']['contact'], $settings['plate_index_key_version'], $settings['pin_paused_until']);

        $sections = Section::query()->orderBy('number')->get();
        $sectionNo = $sections->pluck('number', 'id');
        $apartments = Apartment::query()->orderBy('number')->get();
        $aptNo = $apartments->pluck('number', 'id');

        return [
            'format' => 'majas-paligs.building-export.v1',
            'generated_at' => now()->toIso8601String(),
            'building' => [
                'name' => $building->name,
                'address' => $building->address_line,
                'postcode' => $building->postcode,
                'city' => $building->city,
                'address_code' => $building->address_code,
                'cadastre_designation' => $building->cadastre_designation,
                'series' => $building->series()->value('code'),
                'floors' => $building->floors,
                'year_built' => $building->year_built,
                'manager_name' => $building->manager_name,
            ],
            'settings' => $settings,
            'land_parcels' => LandParcel::query()->orderBy('cadastre_no')->get()->map(fn (LandParcel $p): array => [
                'cadastre_no' => $p->cadastre_no,
                'verified' => $p->verified,
            ])->all(),
            'sections' => $sections->map(fn (Section $s): array => [
                'number' => $s->number,
                'first_apartment_no' => $s->first_apartment_no,
                'apartments_per_floor' => $s->apartments_per_floor,
                'floors' => $s->floors,
                'layout_code' => $s->layout_code,
            ])->all(),
            'apartments' => $apartments->map(fn (Apartment $a): array => [
                'number' => $a->number,
                'section' => $sectionNo[$a->section_id] ?? null,
                'floor' => $a->floor,
                'position' => $a->position,
                'side' => $a->side?->value,
                'rooms_count' => $a->rooms_count,
                'area_m2' => $a->area_m2,
                'merged' => $a->is_override,
                'retired_numbers' => $a->retired_numbers,
                'archived' => $a->archived_at !== null,
            ])->all(),
            'cells' => DB::table('apartment_cells')->where('building_id', $building->id)->orderBy('section_id')->orderBy('floor')->orderBy('position')
                ->get()->map(fn (object $c): array => [
                    'section' => $sectionNo[$c->section_id] ?? null,
                    'floor' => (int) $c->floor,
                    'position' => (int) $c->position,
                    'apartment' => $aptNo[$c->apartment_id] ?? null,
                    'primary' => (bool) $c->is_primary,
                ])->all(),
            'risers' => Riser::query()->with('links.room')->orderBy('code')->get()->map(fn (Riser $r): array => [
                'code' => $r->code,
                'kind' => $r->kind->value,
                'section' => $sectionNo[$r->section_id] ?? null,
                'position' => $r->position,
                'side' => $r->side?->value,
                'floors_from' => $r->floors_from,
                'floors_to' => $r->floors_to,
                'confidence' => $r->confidence->value,
                'source_ref' => $r->source_ref,
                'links' => $r->links->map(fn ($l): array => [
                    'apartment' => $aptNo[$l->apartment_id] ?? null,
                    'room' => $l->room?->code,
                ])->values()->all(),
            ])->all(),
            // Package B: riser/column-level aggregates only (never per-floor data, apartments or identities).
            'survey_round_aggregates' => $this->roundAggregates($sectionNo->all()),
            'incident_history' => $this->incidentHistory($sectionNo->all()),
            'manager_reports' => $this->managerReports(),
        ];
    }

    /**
     * Survey rounds with their riser projection (resident-safe columns only).
     *
     * @param  array<int, int>  $sectionNo  section id => number
     * @return list<array<string, mixed>>
     */
    private function roundAggregates(array $sectionNo): array
    {
        $out = [];
        foreach (SurveyRound::query()->orderBy('starts_at')->get() as $round) {
            $risers = RiserStatus::query()
                ->join('risers', fn ($j) => $j->on('risers.id', '=', 'riser_status.riser_id')->on('risers.building_id', '=', 'riser_status.building_id'))
                ->where('riser_status.round_key', $round->roundKey())
                ->orderBy('risers.code')
                ->get(['risers.code', 'riser_status.status', 'riser_status.confirmed', 'riser_status.distinct_apartments', 'riser_status.floors_covered', 'riser_status.coverage_total', 'riser_status.status_counts']);

            $out[] = [
                'title' => $round->getTranslations('title'),
                'status' => $round->status->value,
                'starts_at' => $round->starts_at->toIso8601String(),
                'ends_at' => $round->ends_at->toIso8601String(),
                'sections' => $round->section_ids === null ? 'building' : array_values(array_map(fn (int $id) => $sectionNo[$id] ?? null, $round->section_ids)),
                'risers' => $risers->map(fn ($r): array => [
                    'code' => $r->getAttribute('code'),
                    'status' => $r->getAttribute('status'),
                    'confirmed' => (bool) $r->getAttribute('confirmed'),
                    'distinct_apartments' => (int) $r->getAttribute('distinct_apartments'),
                    'floors_covered' => (int) $r->getAttribute('floors_covered'),
                    'coverage_total' => (int) $r->getAttribute('coverage_total'),
                    'status_counts' => json_decode((string) $r->getAttribute('status_counts'), true),
                ])->values()->all(),
            ];
        }

        return $out;
    }

    /**
     * Incident history without reporter / confirmer identities (confirmations as a count).
     *
     * @param  array<int, int>  $sectionNo
     * @return list<array<string, mixed>>
     */
    private function incidentHistory(array $sectionNo): array
    {
        return Incident::query()->withCount('confirmations')->with('riser:id,code')->orderBy('started_at')->get()
            ->map(fn (Incident $i): array => [
                'kind' => $i->kind->value,
                'origin' => $i->origin->value,
                'scope' => explode(':', $i->scope_key)[0],
                'section' => $i->section_id !== null ? ($sectionNo[$i->section_id] ?? null) : null,
                'riser' => $i->riser?->code,
                'column' => $i->column_position,
                'status' => $i->status->value,
                'started_at' => $i->started_at->toIso8601String(),
                'resolved_at' => $i->resolved_at?->toIso8601String(),
                'confirmations' => (int) $i->confirmations_count,
            ])->values()->all();
    }

    /**
     * Manager reports as METADATA + per-riser aggregates only. The stored snapshot is never copied: per-floor lists,
     * per-floor grids and apartment numbers are dropped even if the sharing flags were on.
     *
     * @return list<array<string, mixed>>
     */
    private function managerReports(): array
    {
        $allowed = ['code', 'section', 'status', 'confirmed', 'distinct_apartments', 'floors_covered', 'coverage_total', 'status_counts', 'whole_riser_under', 'confidence'];

        return ManagerReport::query()->orderBy('generated_at')->get()->map(fn (ManagerReport $m): array => [
            'number' => $m->number,
            'kind' => $m->kind->value,
            'generated_at' => $m->generated_at->toIso8601String(),
            'period' => ['from' => $m->params['from'] ?? null, 'to' => $m->params['to'] ?? null],
            'sections' => $m->snapshot['sections'] ?? [],
            'sent_at' => $m->sent_at?->toIso8601String(),
            'response_recorded_at' => $m->response['recorded_at'] ?? null,
            'risers' => array_values(array_map(
                static fn (array $r): array => array_intersect_key($r, array_flip($allowed)),
                (array) ($m->snapshot['risers'] ?? []),
            )),
            'columns' => array_values(array_map(
                static fn (array $c): array => array_intersect_key($c, array_flip(['section', 'position', 'status', 'confirmed'])),
                (array) ($m->snapshot['columns'] ?? []),
            )),
        ])->values()->all();
    }

    /**
     * Recursively drop forbidden keys (defence in depth for future Package B/C sections).
     *
     * @param  array<mixed>  $data
     * @return array<mixed>
     */
    public static function strip(array $data): array
    {
        foreach ($data as $key => $value) {
            if (is_string($key) && in_array($key, self::FORBIDDEN_KEYS, true)) {
                unset($data[$key]);

                continue;
            }

            if (is_array($value)) {
                $data[$key] = self::strip($value);
            }
        }

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function writeZip(string $path, array $data): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'mpb');
        if ($tmp === false) {
            throw new RuntimeException('Cannot create a temporary file.');
        }

        $zip = new ZipArchive;
        $zip->open($tmp, ZipArchive::OVERWRITE);
        $zip->addFromString('building.json', (string) json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

        $apartments = [['number', 'section', 'floor', 'position', 'side', 'rooms_count', 'area_m2', 'merged']];
        foreach ($data['apartments'] as $a) {
            $apartments[] = [$a['number'], $a['section'], $a['floor'], $a['position'], $a['side'], $a['rooms_count'], $a['area_m2'], $a['merged'] ? 'yes' : 'no'];
        }
        $zip->addFromString('apartments.csv', CsvWriter::toString($apartments));

        $risers = [['code', 'kind', 'section', 'position', 'side', 'floors_from', 'floors_to', 'confidence', 'linked_apartments']];
        foreach ($data['risers'] as $r) {
            $risers[] = [$r['code'], $r['kind'], $r['section'], $r['position'], $r['side'], $r['floors_from'], $r['floors_to'], $r['confidence'], count($r['links'])];
        }
        $zip->addFromString('risers.csv', CsvWriter::toString($risers));
        $zip->close();

        Storage::disk((string) config('majas.exports.disk', 'local'))->put($path, (string) file_get_contents($tmp));
        @unlink($tmp);
    }
}
