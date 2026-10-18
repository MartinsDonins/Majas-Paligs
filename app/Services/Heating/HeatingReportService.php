<?php

declare(strict_types=1);

namespace App\Services\Heating;

use App\Audit\AuditLogger;
use App\Enums\HeatingStatus;
use App\Enums\HeatingTriage;
use App\Enums\ReportSource;
use App\Jobs\RecomputeRiserStatus;
use App\Models\Apartment;
use App\Models\HeatingReport;
use App\Models\RiserLink;
use App\Models\Room;
use App\Models\SurveyRound;
use App\Models\User;
use App\Tenancy\CurrentBuilding;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Radiator reports (HEAT-002): one submission = one row per room; each row supersedes the apartment's active
 * report of that room in the same round (or ad-hoc). Ad-hoc rows stay valid for `adhoc_report_valid_days` (7).
 * The report attaches to the open survey round covering the apartment's stairwell, if any. Undo within a short
 * window restores the superseded rows. Every write triggers a debounced projection recompute.
 *
 * Authorisation (HeatingReportPolicy) is checked here too: `create` for the resident's own apartment, `proxy`
 * for an elder/volunteer of that stairwell (source proxy|phone, audit-logged).
 */
final class HeatingReportService
{
    public const UNDO_SECONDS = 120;

    /**
     * @param  array<string, string>  $states  room code => heating status value
     */
    public function submit(
        User $user,
        Apartment $apartment,
        array $states,
        ?string $temperature = null,
        ?string $triage = null,
        ?string $note = null,
        ReportSource $source = ReportSource::Self,
    ): HeatingSubmission {
        Gate::forUser($user)->authorize($source === ReportSource::Self ? 'create' : 'proxy', [HeatingReport::class, $apartment]);

        $rooms = Room::query()->where('apartment_id', $apartment->id)->get()->keyBy('code');
        $clean = [];
        foreach ($states as $code => $status) {
            $room = $rooms->get((string) $code);
            $state = HeatingStatus::tryFrom((string) $status);
            if ($room === null || $state === null) {
                throw ValidationException::withMessages(['states' => __('heating.report.invalid')]);
            }
            $clean[$room->id] = $state;
        }
        if ($clean === []) {
            throw ValidationException::withMessages(['states' => __('heating.report.choose_one')]);
        }

        $temp = $this->temperature($temperature);
        $triageCase = $triage !== null && $triage !== '' ? HeatingTriage::tryFrom($triage) : null;
        if ($triage !== null && $triage !== '' && $triageCase === null) {
            throw ValidationException::withMessages(['triage' => __('heating.report.invalid')]);
        }
        $note = $note !== null ? trim($note) : null;
        if ($note !== null && mb_strlen($note) > 280) {
            throw ValidationException::withMessages(['note' => __('heating.report.note_too_long')]);
        }

        $building = CurrentBuilding::get();
        $round = SurveyRound::openFor($apartment->section_id);
        $submission = (string) Str::uuid();
        $now = now();
        $validUntil = $now->addDays($building->settings->adhocReportValidDays);

        DB::transaction(function () use ($clean, $apartment, $round, $submission, $now, $validUntil, $temp, $triageCase, $note, $source, $user): void {
            HeatingReport::query()
                ->active()
                ->whereIn('room_id', array_keys($clean))
                ->where(fn ($q) => $round !== null ? $q->where('survey_round_id', $round->id) : $q->whereNull('survey_round_id'))
                ->update(['superseded_at' => $now, 'superseded_by' => $submission, 'updated_at' => $now]);

            foreach ($clean as $roomId => $state) {
                HeatingReport::query()->create([
                    'apartment_id' => $apartment->id,
                    'room_id' => $roomId,
                    'survey_round_id' => $round?->id,
                    'submission_id' => $submission,
                    'status' => $state,
                    'temperature_c' => $temp,
                    'triage' => $triageCase,
                    'note_private' => $note !== '' ? $note : null,
                    'source' => $source,
                    'reported_by_user_id' => $user->id,
                    'reported_at' => $now,
                    'valid_until' => $validUntil,
                ]);
            }

            if ($source !== ReportSource::Self) {
                AuditLogger::record('heating.proxy_report', ['heating_submission', $submission], data: [
                    'apartment' => $apartment->number,
                    'rooms' => count($clean),
                    'source' => $source->value,
                ]);
            }
        });

        RecomputeRiserStatus::debounce((int) $building->id);

        return new HeatingSubmission(
            id: $submission,
            roundId: $round?->id,
            reports: count($clean),
            apartmentsOnMyRiser: $this->apartmentsOnRisersOf($apartment, array_keys($clean), $round),
        );
    }

    /**
     * Undo the user's own submission (only shortly after saving): its rows are deleted and the rows it superseded
     * become active again.
     */
    public function undo(User $user, string $submissionId): bool
    {
        if (! Str::isUuid($submissionId)) {
            return false;
        }

        $rows = HeatingReport::query()->where('submission_id', $submissionId)->where('reported_by_user_id', $user->id)->get();
        $first = $rows->first();
        if ($first === null || $first->reported_at->lt(now()->subSeconds(self::UNDO_SECONDS)) || $rows->contains(fn (HeatingReport $r) => $r->superseded_at !== null)) {
            return false;
        }

        DB::transaction(function () use ($submissionId): void {
            HeatingReport::query()->where('submission_id', $submissionId)->delete();
            HeatingReport::query()->where('superseded_by', $submissionId)
                ->update(['superseded_at' => null, 'superseded_by' => null, 'updated_at' => now()]);
        });

        RecomputeRiserStatus::debounce(CurrentBuilding::id());

        return true;
    }

    /**
     * Hide a report (building_admin) — excluded from every aggregate; audit-logged.
     */
    public function hide(User $admin, HeatingReport $report, string $reason): void
    {
        Gate::forUser($admin)->authorize('hide', $report);

        $report->forceFill(['hidden_at' => now(), 'hidden_by' => $admin->id, 'hidden_reason' => mb_substr(trim($reason), 0, 200)])->save();
        AuditLogger::record('heating.report_hide', $report, reasonText: $reason);

        RecomputeRiserStatus::debounce(CurrentBuilding::id());
    }

    /**
     * "Jau ziņojuši N dzīvokļi Jūsu stāvadā": distinct apartments with a valid report on the radiator risers of the
     * rooms just reported (the largest of them). Counts only.
     *
     * @param  list<int>  $roomIds
     */
    public function apartmentsOnRisersOf(Apartment $apartment, array $roomIds, ?SurveyRound $round = null): int
    {
        $riserIds = RiserLink::query()
            ->where('apartment_id', $apartment->id)
            ->where(fn ($q) => $q->whereIn('room_id', $roomIds)->orWhereNull('room_id'))
            ->whereIn('riser_id', DB::table('risers')->where('building_id', CurrentBuilding::id())
                ->whereIn('kind', array_map(static fn ($k) => $k->value, RiserStatusProjector::KINDS))->select('id'))
            ->pluck('riser_id')
            ->unique();

        $best = 0;
        foreach ($riserIds as $riserId) {
            $links = RiserLink::query()->where('riser_id', $riserId);
            $count = HeatingReport::query()
                ->when($round !== null, fn ($q) => $q->active()->where('survey_round_id', $round?->id), fn ($q) => $q->valid())
                ->whereIn('apartment_id', (clone $links)->select('apartment_id'))
                ->where(fn ($q) => $q->whereIn('room_id', (clone $links)->whereNotNull('room_id')->select('room_id'))
                    ->orWhereIn('apartment_id', (clone $links)->whereNull('room_id')->select('apartment_id')))
                ->distinct()
                ->count('apartment_id');
            $best = max($best, $count);
        }

        return $best;
    }

    private function temperature(?string $value): ?string
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        $normalized = str_replace(',', '.', trim($value));
        if (! is_numeric($normalized) || (float) $normalized < 0 || (float) $normalized > 40) {
            throw ValidationException::withMessages(['temperature' => __('heating.report.temperature_range')]);
        }

        return number_format(round((float) $normalized * 2) / 2, 1, '.', '');
    }
}
