<?php

declare(strict_types=1);

namespace App\Gdpr;

use App\Enums\ExportStatus;
use App\Models\Apartment;
use App\Models\ApartmentMembership;
use App\Models\Building;
use App\Models\BuildingRole;
use App\Models\DataExport;
use App\Models\HeatingReport;
use App\Models\IncidentConfirmation;
use App\Models\Section;
use App\Models\User;
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
                // Package B: the user's own heating reports and outage confirmations (Package C adds vehicles/permits).
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
