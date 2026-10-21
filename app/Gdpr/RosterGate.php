<?php

declare(strict_types=1);

namespace App\Gdpr;

use App\Models\Building;
use App\Models\BuildingPrivacyNotice;
use App\Tenancy\CurrentBuilding;
use App\Tenancy\Exceptions\MissingBuildingContext;

/**
 * Resident roster gate L-R (GOV-012, ROUND2-SPEC §3.1, decision D2) — a pure function of buildings.settings +
 * building_privacy_notices, like CoreGate / ParkingGate: open iff gate L-core is open AND dpa_scope ∋ roster AND the
 * current published notice covers `roster`.
 *
 * Unlocks every read and write of resident_contacts, the roster import upload / commit and the roster export.
 * Enforced three times: policy before() (ResidentContactPolicy, ImportRunPolicy), the model guard
 * GuardedByRosterGate and the UI (closed card instead of the list / uploader).
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

        return new RosterGateStatus($missing === [], $missing);
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
        $status = self::current();

        return $status !== null && $status->open;
    }

    /**
     * Model-guard / service helper: throws RosterGateClosed when the current building's roster gate is closed.
     */
    public static function assertOpen(): void
    {
        $status = self::status(CurrentBuilding::get());

        if (! $status->open) {
            throw new RosterGateClosed($status->missing);
        }
    }
}
