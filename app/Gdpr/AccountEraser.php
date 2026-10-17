<?php

declare(strict_types=1);

namespace App\Gdpr;

use App\Audit\AuditLogger;
use App\Enums\ExportStatus;
use App\Enums\MembershipStatus;
use App\Models\ApartmentMembership;
use App\Models\Building;
use App\Models\BuildingRole;
use App\Models\DataExport;
use App\Models\LoginToken;
use App\Models\User;
use App\Tenancy\UserScopedQuery;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Self-service account deletion (SEC-002, AC 10). Per building: end memberships, revoke roles, remove own
 * vehicles/declarations (Package C hooks in via VehicleRemovalService) — heating reports and confirmations stay
 * as house data pointing at the tombstone. Then the users row becomes a TOMBSTONE: identifiers and credentials
 * nulled, anonymised_at + deleted_at set. audit_entries are NEVER touched (actors render as "Dzēsts lietotājs").
 */
final class AccountEraser
{
    public function erase(User $user): void
    {
        $userId = (int) $user->id;

        UserScopedQuery::for($user)->eachBuilding(static function (Building $building) use ($userId): void {
            DB::transaction(static function () use ($userId): void {
                foreach (ApartmentMembership::query()->where('user_id', $userId)->active()->get() as $membership) {
                    $membership->forceFill(['status' => MembershipStatus::Ended, 'ended_at' => now()])->save();
                    AuditLogger::record('membership.end', $membership, [$userId], reasonCode: 'account_deleted', actorRole: 'self', actorUserId: $userId);
                }

                BuildingRole::query()->where('user_id', $userId)->whereNull('revoked_at')->update(['revoked_at' => now()]);

                // Package C: VehicleRemovalService::removeAllFor($userId) + declarations (C.5).
            });
        });

        DB::transaction(static function () use ($user, $userId): void {
            LoginToken::query()->where('user_id', $userId)->delete();

            foreach (DataExport::query()->where('user_id', $userId)->get() as $export) {
                if ($export->path !== null) {
                    Storage::disk((string) config('majas.exports.disk', 'local'))->delete($export->path);
                }
                $export->forceFill(['status' => ExportStatus::Expired, 'path' => null])->save();
            }

            $user->forceFill([
                'email' => null,
                'email_verified_at' => null,
                'login_handle' => null,
                'pin_hash' => null,
                'pin_temp_code_hash' => null,
                'pin_temp_code_expires_at' => null,
                'pin_locked_until' => null,
                'pin_must_change' => false,
                'display_name' => null,
                'platform_role' => null,
                'app_authentication_secret' => null,
                'app_authentication_recovery_codes' => null,
                'remember_token' => null,
                'notice_version_accepted' => null,
                'anonymised_at' => now(),
            ])->save();

            AuditLogger::record('account.erased', ['user', $userId], [$userId], platform: true, actorRole: 'self', actorUserId: $userId);

            $user->delete(); // soft delete → deleted_at; hard delete later by prune:users after the last audit reference
        });
    }
}
