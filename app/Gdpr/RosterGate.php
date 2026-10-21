<?php

declare(strict_types=1);

namespace App\Gdpr;

use App\Models\Building;
use App\Models\BuildingPrivacyNotice;
use App\Tenancy\CurrentBuilding;
use App\Tenancy\Exceptions\MissingBuildingContext;

/**
 * Resident roster gate L-R (ROUND2-SPEC D2, §3.1, GOV-012) — a pure function of buildings.settings +
 * building_privacy_notices, mirroring ParkingGate: open iff gate L-core is open, the recorded DPA scope contains
 * `roster` and the current published notice covers `roster`.
 *
 * Unlocks every read / write of resident_contacts, the roster import (upload and commit) and the roster export.
 * Enforced three times: policy before(), model guard (GuardedByRosterGate) and the UI (closed card instead of data).
 */
final class RosterGate
{
    public const CONDITIONS = ['core_gate', 'dpa_scope_roster', 'privacy_notice_roster'];

    public static function status(Building $building): RosterGateStatus
    {
        if (! CurrentBuilding::has() || CurrentBuilding::id() !== (int) $building->id) {
            throw new MissingBuildingContext('RosterGate::status() requires the context of the building it checks.');
        }

        $missing = [];

        if (! CoreGate::status($building)->open) {
            $missing[] = 'core_gate';
        }
        if (! in_array('roster', $building->settings->dpaScope, true)) {
            $missing[] = 'dpa_scope_roster';
        }
        $notice = BuildingPrivacyNotice::current();
        if ($notice === null || ! $notice->covers(['roster'])) {
            $missing[] = 'privacy_notice_roster';
        }

        return new RosterGateStatus(open: $missing === [], missing: $missing);
    }

    /**
     * Status of the CURRENT building (null without a context).
     */
    public static function current(): ?RosterGateStatus
    {
        $building = CurrentBuilding::find();

        return $building === null ? null : self::status($building);
    }

    public static function isOpen(): bool
    {
        return self::current()->open ?? false;
    }

    /**
     * Model-guard helper: throws RosterGateClosed when the current building's gate L-R is closed.
     */
    public static function assertOpen(): void
    {
        $status = self::status(CurrentBuilding::get());

        if (! $status->open) {
            throw new RosterGateClosed($status->missing);
        }
    }
}
