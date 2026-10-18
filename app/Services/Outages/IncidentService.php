<?php

declare(strict_types=1);

namespace App\Services\Outages;

use App\Audit\AuditLogger;
use App\Enums\IncidentKind;
use App\Enums\IncidentOrigin;
use App\Enums\IncidentStatus;
use App\Enums\ReportSource;
use App\Gdpr\CoreGate;
use App\Models\Apartment;
use App\Models\Incident;
use App\Models\IncidentConfirmation;
use App\Models\Riser;
use App\Models\Section;
use App\Models\User;
use App\Tenancy\CurrentBuilding;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Outages (HEAT-004, MVP-SPEC B.2a): "Nav ūdens" reports join the open incident covering the apartment — a notice
 * first, otherwise the resident incident with the same de-dup key — or create it with INSERT … ON CONFLICT DO
 * NOTHING (two simultaneous first reports end in ONE incident). "Man arī" = one confirmation per apartment.
 * Elders (own stairwell) and building_admin post notices; building_admin updates status and the manager reply.
 */
final class IncidentService
{
    public function __construct(private readonly OutageScopeResolver $resolver) {}

    /**
     * Report an outage for an apartment ("Nav ūdens"). $answer is the room question answer when several risers of
     * the kind serve the apartment (see OutageScope).
     */
    public function report(
        User $user,
        Apartment $apartment,
        IncidentKind $kind,
        ?string $answer = null,
        string $since = 'now',
        ReportSource $source = ReportSource::Self,
    ): OutageResult {
        Gate::forUser($user)->authorize('report', [Incident::class, $apartment]);

        $scope = $this->resolver->resolve($apartment, $kind);
        if ($scope->needsChoice() && $answer === null) {
            throw ValidationException::withMessages(['answer' => __('outage.report.choose_where')]);
        }

        try {
            $targets = $scope->choose($answer);
        } catch (\InvalidArgumentException) {
            throw ValidationException::withMessages(['answer' => __('outage.report.choose_where')]);
        }

        $building = CurrentBuilding::get();
        CoreGate::assertOpen($building);
        $footprint = ApartmentFootprint::of($apartment);
        $startedAt = $this->since($since);

        $incidents = DB::transaction(function () use ($targets, $kind, $footprint, $startedAt, $user, $source): Collection {
            $joined = collect();
            $notices = Incident::query()->open()->where('kind', $kind->value)->where('origin', IncidentOrigin::Notice->value)->get();

            foreach ($targets as $target) {
                $incident = $notices->first(fn (Incident $n): bool => $footprint->coveredBy($n))
                    ?? $this->residentIncident($kind, $target, $startedAt, $user);

                if ($joined->contains(fn (Incident $i): bool => $i->id === $incident->id)) {
                    continue;
                }
                $this->confirm($incident, $footprint->apartmentId, $user, $source);
                $joined->push($incident->refresh());
            }

            return $joined;
        });

        return new OutageResult($incidents->values(), $this->apartmentsCount($incidents));
    }

    /**
     * "Man arī" — idempotent per apartment.
     */
    public function meToo(User $user, Incident $incident): OutageResult
    {
        Gate::forUser($user)->authorize('confirm', $incident);

        $membership = $user->approvedMembershipFor(CurrentBuilding::get());
        abort_if($membership === null, 403);

        DB::transaction(fn () => $this->confirm($incident, (int) $membership->apartment_id, $user, ReportSource::Self));

        return new OutageResult(collect([$incident->refresh()]), $this->apartmentsCount(collect([$incident])));
    }

    /**
     * Elder / admin outage notice to a riser, column, stairwell or the whole building (LV + RU reason).
     *
     * @param  array{target: string, section_id?: int|null, position?: int|null, riser_id?: int|null, floors_from?: int|null, floors_to?: int|null, reason_lv: string, reason_ru: string, expected_end_at?: string|null}  $input
     */
    public function postNotice(User $user, IncidentKind $kind, array $input): Incident
    {
        $target = $this->noticeTarget($input);
        Gate::forUser($user)->authorize('createNotice', [Incident::class, $target->sectionId]);

        $reasonLv = trim($input['reason_lv']);
        $reasonRu = trim($input['reason_ru']);
        if ($reasonLv === '' || $reasonRu === '' || mb_strlen($reasonLv) > 300 || mb_strlen($reasonRu) > 300) {
            throw ValidationException::withMessages(['reason_lv' => __('outage.notice.reason_required')]);
        }

        $floors = Incident::floorsLiteral($input['floors_from'] ?? null, $input['floors_to'] ?? null);
        $expected = filled($input['expected_end_at'] ?? null) ? CarbonImmutable::parse((string) $input['expected_end_at'], 'Europe/Riga')->utc() : null;

        return DB::transaction(function () use ($user, $kind, $target, $reasonLv, $reasonRu, $floors, $expected): Incident {
            $incident = Incident::query()->create([
                'kind' => $kind,
                'origin' => IncidentOrigin::Notice,
                'section_id' => $target->sectionId,
                'riser_id' => $target->riserId,
                'column_position' => $target->position,
                'floors' => $floors,
                'scope_key' => $target->key,
                'status' => IncidentStatus::Confirmed,
                'started_at' => now(),
                'expected_end_at' => $expected,
                'reason' => ['lv' => $reasonLv, 'ru' => $reasonRu],
                'opened_by' => $user->id,
            ]);

            AuditLogger::record('incident.notice', $incident, data: ['kind' => $kind->value, 'scope' => $target->key]);

            return $incident;
        });
    }

    /**
     * Close an incident (building_admin any; elder: notices of their own stairwell).
     */
    public function resolve(User $user, Incident $incident, IncidentStatus $status = IncidentStatus::Resolved): void
    {
        Gate::forUser($user)->authorize('resolve', $incident);
        abort_unless(in_array($status, [IncidentStatus::Resolved, IncidentStatus::Rejected], true), 422);

        $incident->forceFill(['status' => $status, 'resolved_at' => now()])->save();
        AuditLogger::record('incident.resolve', $incident, data: ['status' => $status->value]);
    }

    /**
     * Status, manager reference and the manager reply (≤160 chars per language) — building_admin.
     *
     * @param  array{lv?: string|null, ru?: string|null}|null  $reply
     */
    public function update(User $admin, Incident $incident, IncidentStatus $status, ?string $managerRef, ?array $reply): void
    {
        Gate::forUser($admin)->authorize('update', $incident);

        $clean = null;
        if ($reply !== null && (filled($reply['lv'] ?? null) || filled($reply['ru'] ?? null))) {
            $clean = [
                'lv' => mb_substr(trim((string) ($reply['lv'] ?? '')), 0, 160),
                'ru' => mb_substr(trim((string) ($reply['ru'] ?? '')), 0, 160),
            ];
        }

        $incident->forceFill([
            'status' => $status,
            'manager_ref' => $managerRef !== null ? mb_substr(trim($managerRef), 0, 60) : null,
            'manager_reply' => $clean,
            'resolved_at' => $status->isOpen() ? null : ($incident->resolved_at ?? now()),
        ])->save();

        AuditLogger::record('incident.update', $incident, data: ['status' => $status->value, 'has_reply' => $clean !== null]);
    }

    /**
     * Open incidents (any kind) covering an apartment — banner and grid overlay.
     *
     * @return Collection<int, Incident>
     */
    public function openFor(Apartment $apartment): Collection
    {
        $footprint = ApartmentFootprint::of($apartment);

        return Incident::query()->open()->withCount('confirmations')->with(['riser:id,code', 'section:id,number'])->orderByDesc('started_at')->get()
            ->filter(fn (Incident $i): bool => $footprint->coveredBy($i))
            ->values();
    }

    /**
     * Open the resident incident for (building, kind, scope) unless one is already open — INSERT … ON CONFLICT DO
     * NOTHING against the partial UNIQUE de-dup index, so concurrent first reports end in ONE incident (B.2a rule 4).
     */
    public function openResidentIncident(int $buildingId, IncidentKind $kind, ScopeTarget $target, CarbonImmutable $startedAt, ?int $userId): void
    {
        $now = now();

        DB::statement(
            "INSERT INTO incidents (id, building_id, kind, origin, section_id, riser_id, column_position, scope_key, status, started_at, opened_by, created_at, updated_at)
             VALUES (?, ?, ?, 'resident', ?, ?, ?, ?, 'reported', ?, ?, ?, ?)
             ON CONFLICT (building_id, kind, scope_key) WHERE is_open AND origin = 'resident' DO NOTHING",
            [strtolower((string) Str::ulid()), $buildingId, $kind->value, $target->sectionId, $target->riserId, $target->position, $target->key, $startedAt, $userId, $now, $now],
        );
    }

    private function residentIncident(IncidentKind $kind, ScopeTarget $target, CarbonImmutable $startedAt, User $user): Incident
    {
        $this->openResidentIncident(CurrentBuilding::id(), $kind, $target, $startedAt, (int) $user->id);

        return Incident::query()->open()
            ->where('origin', IncidentOrigin::Resident->value)
            ->where('kind', $kind->value)
            ->where('scope_key', $target->key)
            ->firstOrFail();
    }

    private function confirm(Incident $incident, int $apartmentId, User $user, ReportSource $source): void
    {
        CoreGate::assertOpen(CurrentBuilding::get());

        IncidentConfirmation::query()->insertOrIgnore([
            'building_id' => CurrentBuilding::id(),
            'incident_id' => $incident->id,
            'apartment_id' => $apartmentId,
            'user_id' => $user->id,
            'source' => $source->value,
            'created_at' => now(),
        ]);

        if ($incident->origin === IncidentOrigin::Resident && $incident->status === IncidentStatus::Reported) {
            $count = IncidentConfirmation::query()->where('incident_id', $incident->id)->count();
            if ($count >= CurrentBuilding::get()->settings->confirmMinApartments) {
                Incident::query()->whereKey($incident->id)->where('status', IncidentStatus::Reported->value)
                    ->update(['status' => IncidentStatus::Confirmed->value, 'updated_at' => now()]);
            }
        }
    }

    /**
     * @param  Collection<int, Incident>  $incidents
     */
    private function apartmentsCount(Collection $incidents): int
    {
        if ($incidents->isEmpty()) {
            return 0;
        }

        return (int) IncidentConfirmation::query()
            ->whereIn('incident_id', $incidents->pluck('id'))
            ->selectRaw('count(DISTINCT apartment_id) AS c, incident_id')
            ->groupBy('incident_id')
            ->get()
            ->max('c');
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function noticeTarget(array $input): ScopeTarget
    {
        $sectionId = isset($input['section_id']) ? (int) $input['section_id'] : null;

        return match ($input['target'] ?? null) {
            'building' => ScopeTarget::building(),
            'section' => ScopeTarget::section((int) Section::query()->findOrFail($sectionId)->id),
            'column' => (function () use ($sectionId, $input): ScopeTarget {
                $section = Section::query()->findOrFail($sectionId);
                $position = (int) ($input['position'] ?? 0);
                if ($position < 1 || $position > $section->apartments_per_floor) {
                    throw ValidationException::withMessages(['position' => __('outage.notice.invalid_target')]);
                }

                return ScopeTarget::column((int) $section->id, $position);
            })(),
            'riser' => (function () use ($input): ScopeTarget {
                $riser = Riser::query()->findOrFail((int) ($input['riser_id'] ?? 0));

                return ScopeTarget::riser((int) $riser->id, (int) $riser->section_id);
            })(),
            default => throw ValidationException::withMessages(['target' => __('outage.notice.invalid_target')]),
        };
    }

    private function since(string $since): CarbonImmutable
    {
        $now = CarbonImmutable::now();
        $morning = $now->setTimezone('Europe/Riga')->setTime(7, 0)->utc();

        return match ($since) {
            'hour' => $now->subHour(),
            'morning' => $morning->lessThan($now) ? $morning : $now->subHour(),
            default => $now,
        };
    }
}
