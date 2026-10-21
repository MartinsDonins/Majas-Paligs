<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\DocumentKind;
use App\Models\BuildingDocument;
use App\Models\User;
use App\Policies\Concerns\ResolvesBuildingRoles;
use App\Tenancy\CurrentBuilding;

/**
 * House documents (ROUND2-SPEC §3.8): building_admin views and edits (DocumentService adds the fresh second factor);
 * a parking_officer reads the DPIA-lite and the parking decision template; record → platform_admin only, from the
 * platform panel inside PlatformBuildingContext (the document must belong to the current building context).
 */
final class BuildingDocumentPolicy
{
    use ResolvesBuildingRoles;

    private const OFFICER_READS = [DocumentKind::DpiaLite, DocumentKind::ParkingDecision];

    public function viewAny(User $user): bool
    {
        return $this->isAdmin($user) || $this->isParkingOfficer($user);
    }

    public function view(User $user, BuildingDocument $document): bool
    {
        return $this->inContext($document)
            && ($this->isAdmin($user) || ($this->isParkingOfficer($user) && in_array($document->kind, self::OFFICER_READS, true)));
    }

    public function create(User $user): bool
    {
        return $this->isAdmin($user);
    }

    public function update(User $user, BuildingDocument $document): bool
    {
        return $this->inContext($document) && $this->isAdmin($user);
    }

    public function finalize(User $user, BuildingDocument $document): bool
    {
        return $this->update($user, $document);
    }

    public function send(User $user, BuildingDocument $document): bool
    {
        return $this->update($user, $document) && $document->kind->isAgreement();
    }

    public function record(User $user, BuildingDocument $document): bool
    {
        return $this->inContext($document) && ! $user->isTombstone() && $user->isPlatformAdmin();
    }

    private function inContext(BuildingDocument $document): bool
    {
        return CurrentBuilding::has() && (int) $document->building_id === CurrentBuilding::id();
    }
}
