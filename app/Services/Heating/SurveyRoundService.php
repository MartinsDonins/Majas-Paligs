<?php

declare(strict_types=1);

namespace App\Services\Heating;

use App\Audit\AuditLogger;
use App\Enums\SurveyRoundStatus;
use App\Jobs\RecomputeRiserStatus;
use App\Models\SurveyRound;
use App\Models\User;
use App\Tenancy\CurrentBuilding;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Survey rounds (HEAT-003): comparable heating snapshots per stairwell. `section_ids` go through SectionScope
 * (current building, non-empty, unique, sorted; null = whole building, building_admin only) and the DB trigger;
 * SurveyRoundPolicy::scope decides who may use which scope (an elder only their own stairwells).
 */
final class SurveyRoundService
{
    /**
     * @param  array{title_lv: string, title_ru: string, section_ids: array<int|string, mixed>|null, starts_at: string, ends_at: string, status?: string}  $data
     */
    public function save(User $user, array $data, ?SurveyRound $round = null): SurveyRound
    {
        try {
            $scope = SectionScope::fromIds($data['section_ids'], allowWholeBuilding: true);
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['section_ids' => __('heating.round.invalid_sections')]);
        }

        Gate::forUser($user)->authorize('scope', [SurveyRound::class, $scope->ids]);
        if ($round !== null) {
            Gate::forUser($user)->authorize('update', $round);
        } else {
            Gate::forUser($user)->authorize('create', SurveyRound::class);
        }

        $starts = CarbonImmutable::parse($data['starts_at'], 'Europe/Riga')->utc();
        $ends = CarbonImmutable::parse($data['ends_at'], 'Europe/Riga')->utc();
        if (! $ends->greaterThan($starts)) {
            throw ValidationException::withMessages(['ends_at' => __('heating.round.invalid_period')]);
        }

        $status = SurveyRoundStatus::tryFrom((string) ($data['status'] ?? ($round?->status->value ?? 'draft'))) ?? SurveyRoundStatus::Draft;

        $round ??= new SurveyRound(['created_by' => $user->id]);
        $round->fill([
            'title' => ['lv' => mb_substr(trim($data['title_lv']), 0, 120), 'ru' => mb_substr(trim($data['title_ru']), 0, 120)],
            'section_ids' => $scope->ids,
            'starts_at' => $starts,
            'ends_at' => $ends,
            'status' => $status,
        ])->save();

        AuditLogger::record('survey_round.save', $round, data: ['status' => $status->value, 'sections' => $scope->ids ?? 'building']);
        RecomputeRiserStatus::debounce(CurrentBuilding::id());

        return $round;
    }

    public function transition(User $user, SurveyRound $round, SurveyRoundStatus $status): void
    {
        Gate::forUser($user)->authorize('update', $round);

        $round->forceFill(['status' => $status])->save();
        AuditLogger::record('survey_round.status', $round, data: ['status' => $status->value]);
        RecomputeRiserStatus::debounce(CurrentBuilding::id());
    }
}
