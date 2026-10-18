<?php

declare(strict_types=1);

namespace App\Services\Heating;

use App\Audit\AuditLogger;
use App\Enums\DisputeStatus;
use App\Enums\RiserCheckResult;
use App\Enums\RiserConfidence;
use App\Jobs\RecomputeRiserStatus;
use App\Models\Riser;
use App\Models\RiserCheck;
use App\Models\RiserDispute;
use App\Models\RiserLink;
use App\Models\User;
use App\Tenancy\CurrentBuilding;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Riser checks and disputes (HEAT-007, MVP-SPEC B.2 rules 5–6):
 * - a resident disputes a riser linked to their apartment ("Mans radiators nav šajā stāvadā") → the column is
 *   frozen as "Pārbaudāms" until building_admin resolves (links corrected) or rejects (links right → confidence
 *   upgraded to resident_confirmed when still a hypothesis);
 * - building_admin records a check ("Pārbaudīts": normal / air vented / fixed / escalated), optionally upgrading
 *   the riser's confidence to manager_confirmed.
 */
final class RiserReviewService
{
    public function dispute(User $user, Riser $riser): RiserDispute
    {
        Gate::forUser($user)->authorize('open', [RiserDispute::class, $riser]);

        $membership = $user->approvedMembershipFor(CurrentBuilding::get());
        abort_if($membership === null, 403);

        $roomId = RiserLink::query()->where('riser_id', $riser->id)->where('apartment_id', $membership->apartment_id)->value('room_id');

        $dispute = RiserDispute::query()->open()->where('riser_id', $riser->id)->where('apartment_id', $membership->apartment_id)->first()
            ?? RiserDispute::query()->create([
                'apartment_id' => $membership->apartment_id,
                'room_id' => $roomId,
                'riser_id' => $riser->id,
                'created_by' => $user->id,
            ]);

        RecomputeRiserStatus::debounce(CurrentBuilding::id());

        return $dispute;
    }

    public function decide(User $admin, RiserDispute $dispute, DisputeStatus $decision): void
    {
        Gate::forUser($admin)->authorize('resolve', $dispute);
        abort_unless(in_array($decision, [DisputeStatus::Resolved, DisputeStatus::Rejected], true), 422);

        DB::transaction(function () use ($admin, $dispute, $decision): void {
            $dispute->forceFill(['status' => $decision, 'resolved_by' => $admin->id, 'resolved_at' => now()])->save();

            $riser = $dispute->riser;
            if ($decision === DisputeStatus::Rejected && $riser->confidence === RiserConfidence::Hypothesis) {
                $riser->forceFill(['confidence' => RiserConfidence::ResidentConfirmed])->save();
            }

            AuditLogger::record('riser.dispute_'.$decision->value, $dispute, data: ['riser' => $riser->code]);
        });

        RecomputeRiserStatus::debounce(CurrentBuilding::id());
    }

    public function check(User $admin, Riser $riser, RiserCheckResult $result, ?string $note = null, bool $confirmLinks = false): RiserCheck
    {
        Gate::forUser($admin)->authorize('record', [RiserCheck::class, $riser]);

        $check = DB::transaction(function () use ($admin, $riser, $result, $note, $confirmLinks): RiserCheck {
            $check = RiserCheck::query()->create([
                'riser_id' => $riser->id,
                'result' => $result,
                'checked_at' => now(),
                'recorded_by' => $admin->id,
                'note_private' => $note !== null && trim($note) !== '' ? mb_substr(trim($note), 0, 500) : null,
            ]);

            if ($confirmLinks && in_array($riser->confidence, [RiserConfidence::Hypothesis, RiserConfidence::ResidentConfirmed], true)) {
                $riser->forceFill(['confidence' => RiserConfidence::ManagerConfirmed])->save();
            }

            AuditLogger::record('riser.check', $check, data: ['riser' => $riser->code, 'result' => $result->value, 'confirm_links' => $confirmLinks]);

            return $check;
        });

        RecomputeRiserStatus::debounce(CurrentBuilding::id());

        return $check;
    }
}
