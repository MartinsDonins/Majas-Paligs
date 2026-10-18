<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\ParkingExport;
use App\Models\User;
use App\Policies\Concerns\ChecksParkingGate;

/**
 * ParkingExportPolicy (MVP-SPEC C.4): request — building_admin; approve — a DIFFERENT building_admin or the
 * parking_officer (never the requester — also a DB CHECK); download — requester or approver, after approval and
 * before expiry. Everything needs gate L1.
 */
final class ParkingExportPolicy
{
    use ChecksParkingGate;

    public function before(User $user, string $ability): ?bool
    {
        return $this->parkingLevel() < 1 || $user->isTombstone() ? false : null;
    }

    public function viewAny(User $user): bool
    {
        return $this->isParkingStaff($user);
    }

    public function view(User $user, ParkingExport $export): bool
    {
        return $this->isParkingStaff($user);
    }

    public function create(User $user): bool
    {
        return $this->isAdmin($user);
    }

    public function approve(User $user, ParkingExport $export): bool
    {
        return ! $export->isApproved() && ! $export->isExpired()
            && (int) $export->requested_by !== (int) $user->id
            && $this->isParkingStaff($user);
    }

    public function download(User $user, ParkingExport $export): bool
    {
        if (! $export->isApproved() || $export->isExpired() || ! $this->isParkingStaff($user)) {
            return false;
        }

        return in_array((int) $user->id, [(int) $export->requested_by, (int) $export->approved_by], true);
    }

    public function update(User $user, ParkingExport $export): bool
    {
        return false;
    }

    public function delete(User $user, ParkingExport $export): bool
    {
        return false;
    }
}
