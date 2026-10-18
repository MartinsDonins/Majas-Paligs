<?php

declare(strict_types=1);

namespace App\Services\Heating;

use App\Enums\AggregateStatus;
use App\Enums\HeatingStatus;
use App\Enums\RiserKind;
use App\Models\ColumnStatus;
use App\Models\HeatingReport;
use App\Models\RiserStatus;
use App\Models\Section;
use App\Models\User;
use App\Services\Heating\Views\ColumnView;
use App\Services\Heating\Views\HeatingSectionView;
use App\Services\Heating\Views\ResidentRiserView;
use App\Services\Heating\Views\StaffRiserView;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

/**
 * Heating grid read model (AC 9: ≤ 3 queries from the projection tables).
 *
 * - residentSection(): riser_status (explicit resident column list — never floors_reported / top_floor_only)
 *   + column_status. Two queries.
 * - staffSection(): authorises HeatingReportPolicy::viewApartments first, then adds the restricted riser fields
 *   and the per-apartment worst state from heating_reports. Three queries.
 */
final class HeatingGridQuery
{
    /** Resident columns of riser_status (restricted fields deliberately absent). */
    public const RESIDENT_RISER_COLUMNS = [
        'riser_status.riser_id', 'riser_status.section_id', 'riser_status.status', 'riser_status.confirmed',
        'riser_status.distinct_apartments', 'riser_status.floors_covered', 'riser_status.status_counts',
        'riser_status.coverage_total', 'riser_status.conflict', 'riser_status.checked_state', 'riser_status.disputed',
        'risers.code', 'risers.kind', 'risers.position',
    ];

    /** Restricted columns (staff only). */
    public const STAFF_RISER_COLUMNS = ['riser_status.floors_reported', 'riser_status.top_floor_only', 'riser_status.whole_riser_under'];

    public static function normalizeRoundKey(?string $key): string
    {
        return $key !== null && preg_match('/^(rolling|round:\d+)$/', $key) === 1 ? $key : 'rolling';
    }

    public function residentSection(Section $section, string $roundKey = 'rolling'): HeatingSectionView
    {
        [$risers] = $this->risers($section, $roundKey, staff: false);

        return new HeatingSectionView(
            sectionId: (int) $section->id,
            sectionNumber: $section->number,
            floors: $section->floors,
            risers: $risers,
            columns: $this->columns([(int) $section->id], $roundKey, [(int) $section->id => $section->floors])[(int) $section->id] ?? [],
        );
    }

    public function staffSection(User $user, Section $section, string $roundKey = 'rolling'): HeatingSectionView
    {
        Gate::forUser($user)->authorize('viewApartments', [HeatingReport::class, $section]);

        [$risers, $staff] = $this->risers($section, $roundKey, staff: true);

        return new HeatingSectionView(
            sectionId: (int) $section->id,
            sectionNumber: $section->number,
            floors: $section->floors,
            risers: $risers,
            columns: $this->columns([(int) $section->id], $roundKey, [(int) $section->id => $section->floors])[(int) $section->id] ?? [],
            staff: true,
            staffRisers: $staff,
            apartments: $this->apartmentStates($section, $roundKey),
        );
    }

    /**
     * Column aggregates of the whole building (mini map) in one query: section id => position => column.
     *
     * @param  array<int, int>  $floorsBySection  section id => floors
     * @return array<int, array<int, ColumnView>>
     */
    public function buildingColumns(array $floorsBySection, string $roundKey = 'rolling'): array
    {
        return $this->columns(array_keys($floorsBySection), $roundKey, $floorsBySection);
    }

    /**
     * @return array{0: list<ResidentRiserView>, 1: array<int, StaffRiserView>}
     */
    private function risers(Section $section, string $roundKey, bool $staff): array
    {
        $columns = $staff ? [...self::RESIDENT_RISER_COLUMNS, ...self::STAFF_RISER_COLUMNS] : self::RESIDENT_RISER_COLUMNS;

        $rows = RiserStatus::query()
            ->join('risers', fn ($j) => $j->on('risers.id', '=', 'riser_status.riser_id')->on('risers.building_id', '=', 'riser_status.building_id'))
            ->where('riser_status.section_id', $section->id)
            ->where('riser_status.round_key', $roundKey)
            ->orderBy('risers.code')
            ->get($columns);

        $resident = [];
        $staffViews = [];
        foreach ($rows as $row) {
            $counts = json_decode((string) $row->getAttribute('status_counts'), true) ?: [];
            $view = new ResidentRiserView(
                riserId: (int) $row->getAttribute('riser_id'),
                sectionId: (int) $row->getAttribute('section_id'),
                code: (string) $row->getAttribute('code'),
                kind: RiserKind::from((string) $row->getAttribute('kind')),
                position: $row->getAttribute('position') !== null ? (int) $row->getAttribute('position') : null,
                status: AggregateStatus::from((string) $row->getAttribute('status')),
                confirmed: (bool) $row->getAttribute('confirmed'),
                distinctApartments: (int) $row->getAttribute('distinct_apartments'),
                floorsCovered: (int) $row->getAttribute('floors_covered'),
                floorsTotal: $section->floors,
                statusCounts: [
                    'cold' => (int) ($counts['cold'] ?? 0),
                    'lukewarm' => (int) ($counts['lukewarm'] ?? 0),
                    'warm' => (int) ($counts['warm'] ?? 0),
                    'overheated' => (int) ($counts['overheated'] ?? 0),
                ],
                coverageTotal: (int) $row->getAttribute('coverage_total'),
                conflict: (bool) $row->getAttribute('conflict'),
                checkedState: $row->getAttribute('checked_state'),
                disputed: (bool) $row->getAttribute('disputed'),
            );
            $resident[] = $view;

            if ($staff) {
                $floors = trim((string) $row->getAttribute('floors_reported'), '{}');
                $staffViews[$view->riserId] = new StaffRiserView(
                    aggregate: $view,
                    floorsReported: $floors === '' ? [] : array_map('intval', explode(',', $floors)),
                    topFloorOnly: (bool) $row->getAttribute('top_floor_only'),
                    wholeRiserUnder: (bool) $row->getAttribute('whole_riser_under'),
                );
            }
        }

        usort($resident, static fn (ResidentRiserView $a, ResidentRiserView $b): int => [$b->status->severity(), $b->distinctApartments, $a->code] <=> [$a->status->severity(), $a->distinctApartments, $b->code]);

        return [$resident, $staffViews];
    }

    /**
     * @param  list<int>  $sectionIds
     * @param  array<int, int>  $floorsBySection
     * @return array<int, array<int, ColumnView>>
     */
    private function columns(array $sectionIds, string $roundKey, array $floorsBySection): array
    {
        $out = [];
        foreach (ColumnStatus::query()->whereIn('section_id', $sectionIds)->where('round_key', $roundKey)->get() as $row) {
            $sectionId = (int) $row->getAttribute('section_id');
            $position = (int) $row->getAttribute('position');
            $out[$sectionId][$position] = new ColumnView(
                sectionId: $sectionId,
                position: $position,
                worstConfirmed: AggregateStatus::tryFrom((string) $row->getAttribute('worst_confirmed_status')),
                unconfirmed: AggregateStatus::tryFrom((string) $row->getAttribute('unconfirmed_status')),
                distinctApartments: (int) $row->getAttribute('distinct_apartments'),
                floorsCovered: (int) $row->getAttribute('floors_covered'),
                floorsTotal: $floorsBySection[$sectionId] ?? 9,
                coverageTotal: (int) $row->getAttribute('coverage_total'),
                disputed: (bool) $row->getAttribute('disputed'),
                checked: (bool) $row->getAttribute('checked'),
                conflict: (bool) $row->getAttribute('conflict'),
            );
        }

        return $out;
    }

    /**
     * Staff only: per-apartment worst current state (rooms reported, any temperature, whether a private note exists).
     *
     * @return array<int, array{status: HeatingStatus, rooms: int, temperature: ?string, has_note: bool}>
     */
    private function apartmentStates(Section $section, string $roundKey): array
    {
        $query = HeatingReport::query()
            ->whereIn('apartment_id', fn ($q) => $q->select('id')->from('apartments')->where('building_id', $section->building_id)->where('section_id', $section->id));

        if (Str::startsWith($roundKey, 'round:')) {
            $query->active()->where('survey_round_id', (int) Str::after($roundKey, 'round:'));
        } else {
            $query->valid();
        }

        $out = [];
        foreach ($query->orderBy('reported_at')->get(['apartment_id', 'status', 'temperature_c', 'note_private']) as $r) {
            $id = (int) $r->apartment_id;
            $current = $out[$id] ?? null;
            $worse = $current === null || AggregateStatus::fromHeating($r->status)->severity() > AggregateStatus::fromHeating($current['status'])->severity();
            $out[$id] = [
                'status' => $worse ? $r->status : $current['status'],
                'rooms' => ($current['rooms'] ?? 0) + 1,
                'temperature' => $r->temperature_c ?? ($current['temperature'] ?? null),
                'has_note' => ($current['has_note'] ?? false) || $r->note_private !== null,
            ];
        }

        return $out;
    }
}
