<?php

declare(strict_types=1);

namespace App\Gdpr;

use App\Enums\ExportStatus;
use App\Enums\LookupReason;
use App\Models\Apartment;
use App\Models\ApartmentMembership;
use App\Models\Building;
use App\Models\BuildingRole;
use App\Models\DataExport;
use App\Models\HeatingReport;
use App\Models\IncidentConfirmation;
use App\Models\Passkey;
use App\Models\Permit;
use App\Models\PlateLookup;
use App\Models\Section;
use App\Models\Sighting;
use App\Models\TrustedDevice;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\Parking\SightingService;
use App\Services\Parking\VehicleRegistry;
use App\Tenancy\Data\AuditEntryData;
use App\Tenancy\UserScopedQuery;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;
use ZipArchive;

/**
 * Self-service GDPR export (SEC-002, AC 10): a ZIP (JSON + CSV) of the user's own data across ALL their
 * buildings, built through UserScopedQuery::eachBuilding (normal scope inside each building). The job holds only
 * the export id; the user is derived from that row, so a tampered payload cannot re-point it to another user.
 */
final class GdprExporter implements ShouldBeEncrypted, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    /** Lookup reasons whose yes/no the user saw on screen — the only ones whose result is exported. */
    private const SHOWN_RESULT = [LookupReason::Blocking, LookupReason::UnknownCar, LookupReason::OwnCheck, LookupReason::Other];

    public int $tries = 2;

    public function __construct(public readonly string $exportId) {}

    public function handle(): void
    {
        $export = DataExport::query()->findOrFail($this->exportId);
        /** @var User $user */
        $user = User::query()->findOrFail($export->user_id);

        try {
            $payload = $this->collect($user);
            $path = 'exports/users/'.$export->id.'.zip';
            $this->writeZip($path, $payload);

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
    public function collect(User $user): array
    {
        $scoped = UserScopedQuery::for($user);
        $buildings = [];

        $scoped->eachBuilding(static function (Building $building) use ($user, &$buildings): void {
            $memberships = ApartmentMembership::query()->where('user_id', $user->id)->orderBy('id')->get();
            $apartments = Apartment::query()->whereIn('id', $memberships->pluck('apartment_id'))->get()->keyBy('id');
            $sections = Section::query()->whereIn('id', $apartments->pluck('section_id'))->get()->keyBy('id');

            $buildings[] = [
                'building' => ['name' => $building->name, 'address' => $building->address_line.', '.$building->city],
                'controller' => $building->settings->controller['name'],
                'memberships' => $memberships->map(static fn (ApartmentMembership $m): array => [
                    'apartment' => $apartments->get($m->apartment_id)?->number,
                    'stairwell' => $sections->get($apartments->get($m->apartment_id)?->section_id)?->number,
                    'role' => $m->role->value,
                    'status' => $m->status->value,
                    'requested_at' => $m->created_at?->toIso8601String(),
                    'approved_at' => $m->approved_at?->toIso8601String(),
                    'ended_at' => $m->ended_at?->toIso8601String(),
                ])->values()->all(),
                'roles' => BuildingRole::query()->where('user_id', $user->id)->orderBy('id')->get()->map(static fn (BuildingRole $r): array => [
                    'role' => $r->role->value,
                    'stairwell_id' => $r->section_id,
                    'granted_at' => $r->granted_at->toIso8601String(),
                    'expires_at' => $r->expires_at->toIso8601String(),
                    'revoked_at' => $r->revoked_at?->toIso8601String(),
                ])->values()->all(),
                // Package B: the user's own heating reports and outage confirmations (Package C below).
                'heating_reports' => HeatingReport::query()->where('reported_by_user_id', $user->id)->with(['room:id,code', 'apartment:id,number'])
                    ->orderBy('reported_at')->get()->map(static fn (HeatingReport $r): array => [
                        'apartment' => $r->apartment->number,
                        'room' => $r->room->code,
                        'status' => $r->status->value,
                        'temperature_c' => $r->temperature_c,
                        'triage' => $r->triage?->value,
                        'private_note' => $r->note_private,
                        'source' => $r->source->value,
                        'reported_at' => $r->reported_at->toIso8601String(),
                        'superseded' => $r->superseded_at !== null,
                    ])->values()->all(),
                'outage_confirmations' => IncidentConfirmation::query()->where('user_id', $user->id)->with('incident:id,kind,started_at')
                    ->orderBy('created_at')->get()->map(static fn (IncidentConfirmation $c): array => [
                        'kind' => $c->incident->kind->value,
                        'source' => $c->source->value,
                        'confirmed_at' => $c->created_at->toIso8601String(),
                    ])->values()->all(),
                // Package C: vehicles the user declared (own plate in clear — it is the user's own data), permits bound
                // to them, the user's lookups (reason + yes/no, never the index) and reported sightings (own plate input).
                'vehicles' => Vehicle::query()->where('declared_by_user_id', $user->id)->orderBy('created_at')->get()
                    ->map(static fn (Vehicle $v): array => [
                        'plate' => app(VehicleRegistry::class)->plateOf($v),
                        'country' => $v->country,
                        'company_car' => $v->is_company_car,
                        'status' => $v->status->value,
                        'declared_at' => $v->created_at->toIso8601String(),
                        'permits' => Permit::query()->where('vehicle_id', $v->id)->orderBy('valid_from')->get()->map(static fn (Permit $p): array => [
                            'number' => $p->number, 'type' => $p->type->value, 'status' => $p->status->value,
                            'valid_from' => $p->valid_from->toDateString(), 'valid_to' => $p->valid_to?->toDateString(),
                        ])->values()->all(),
                    ])->values()->all(),
                // `result` only where the user was SHOWN the answer (resident lookup chips). Declarations, sightings and
                // disputes always export null — they are stored as NULL too — so the export is not a registration
                // oracle (MVP-SPEC C.5 / C.7 AC 16; RegistrationOracleTest).
                'plate_lookups' => PlateLookup::query()->where('user_id', $user->id)->orderBy('created_at')->get()
                    ->map(static fn (PlateLookup $l): array => [
                        'reason' => $l->reason_code->value,
                        'result' => in_array($l->reason_code, self::SHOWN_RESULT, true) ? $l->result : null,
                        'at' => $l->created_at->toIso8601String(),
                    ])->values()->all(),
                'sightings_reported' => Sighting::query()->where('reporter_user_id', $user->id)->with('lot:id,name')->orderBy('seen_at')->get()
                    ->map(static fn (Sighting $s): array => [
                        'plate' => app(SightingService::class)->plateOf($s),
                        'lot' => $s->lot->name,
                        'seen_at' => $s->seen_at->toIso8601String(),
                        'status' => $s->reporterState(),
                        'photo_kept' => $s->hasPhoto(),
                    ])->values()->all(),
            ];
        }, includeEnded: true);

        return [
            'generated_at' => now()->toIso8601String(),
            'account' => [
                'email' => $user->email,
                'login_handle' => $user->login_handle,
                'locale' => $user->locale->value,
                'text_size' => $user->text_size->value,
                'theme' => $user->theme->value,
                'created_at' => $user->created_at?->toIso8601String(),
                'last_login_at' => $user->last_login_at?->toIso8601String(),
                'adult_confirmed_at' => $user->is_adult_confirmed_at?->toIso8601String(),
                'privacy_notice_version' => $user->notice_version_accepted,
                'has_pin' => $user->pin_hash !== null,
                'has_totp' => $user->hasTotp(),
                // Passkeys hold only public material; the export lists what the account can sign in with.
                'passkeys' => $user->passkeys()->orderBy('id')->get()->map(static fn (Passkey $p): array => [
                    'name' => $p->name,
                    'created_at' => $p->created_at?->toIso8601String(),
                    'last_used_at' => $p->last_used_at?->toIso8601String(),
                ])->values()->all(),
                'trusted_devices' => TrustedDevice::query()->validFor($user)->orderBy('id')->get()->map(static fn (TrustedDevice $d): array => [
                    'device' => $d->label,
                    'created_at' => $d->created_at?->toIso8601String(),
                    'expires_at' => $d->expires_at->toIso8601String(),
                ])->values()->all(),
            ],
            'buildings' => $buildings,
            'who_viewed_my_data' => $scoped->auditAboutMe(limit: 1000)->map(static fn (AuditEntryData $e): array => [
                'date' => $e->createdAt->toIso8601String(),
                'building' => $e->buildingName,
                'action' => $e->action,
                'actor_role' => $e->actorRole,
                'reason' => $e->reasonCode,
            ])->values()->all(),
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function writeZip(string $path, array $payload): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'mpx');
        if ($tmp === false) {
            throw new RuntimeException('Cannot create a temporary file.');
        }

        $zip = new ZipArchive;
        $zip->open($tmp, ZipArchive::OVERWRITE);
        $zip->addFromString('my-data.json', (string) json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

        $rows = [['building', 'apartment', 'stairwell', 'role', 'status', 'requested_at', 'approved_at', 'ended_at']];
        foreach ($payload['buildings'] as $b) {
            foreach ($b['memberships'] as $m) {
                $rows[] = [$b['building']['name'], $m['apartment'], $m['stairwell'], $m['role'], $m['status'], $m['requested_at'], $m['approved_at'], $m['ended_at']];
            }
        }
        $zip->addFromString('memberships.csv', CsvWriter::toString($rows));
        $zip->addFromString('README.txt', __('privacy.export.readme'));
        $zip->close();

        Storage::disk((string) config('majas.exports.disk', 'local'))->put($path, (string) file_get_contents($tmp));
        @unlink($tmp);
    }
}
