<?php

declare(strict_types=1);

namespace App\Gdpr;

use App\Models\Building;
use App\Models\BuildingPrivacyNotice;
use App\Tenancy\CurrentBuilding;
use App\Tenancy\Exceptions\MissingBuildingContext;

/**
 * Parking two-level gate (GOV-001, PLANNING §4.7, MVP-SPEC C.0) — a pure function of buildings.settings +
 * building_privacy_notices, like CoreGate:
 *
 * - L1 processing: gate L-core open AND dpa_scope ∋ parking → any write to vehicles, permits, declarations,
 *   sightings, plate_lookups; the permit import page; officer/admin Filament resources.
 * - L2 resident-facing: L1 + dpia_completed_at + the current published notice covers parking + mandate_ref →
 *   modules.parking may be switched on (lookup, sightings, declarations, Manas caurlaides).
 *
 * Enforced three times: policy before(), model guards (assertLevel) and routes/UI (EnsureModule, nav, tiles).
 */
final class ParkingGate
{
    public const L1_CONDITIONS = ['core_gate', 'dpa_scope_parking'];

    public const L2_CONDITIONS = ['dpia_completed_at', 'privacy_notice_parking', 'mandate_ref'];

    public static function status(Building $building): ParkingGateStatus
    {
        if (! CurrentBuilding::has() || CurrentBuilding::id() !== (int) $building->id) {
            throw new MissingBuildingContext('ParkingGate::status() requires the context of the building it checks.');
        }

        $s = $building->settings;
        $missingL1 = [];
        $missingL2 = [];

        if (! CoreGate::status($building)->open) {
            $missingL1[] = 'core_gate';
        }
        if (! in_array('parking', $s->dpaScope, true)) {
            $missingL1[] = 'dpa_scope_parking';
        }

        if ($s->dpiaCompletedAt === null) {
            $missingL2[] = 'dpia_completed_at';
        }
        $notice = BuildingPrivacyNotice::current();
        if ($notice === null || ! $notice->covers(['parking'])) {
            $missingL2[] = 'privacy_notice_parking';
        }
        if ($s->mandateRef === null) {
            $missingL2[] = 'mandate_ref';
        }

        $level = $missingL1 !== [] ? 0 : ($missingL2 !== [] ? 1 : 2);

        return new ParkingGateStatus($level, $missingL1, $missingL2, $s->moduleEnabled('parking'));
    }

    /**
     * Status of the CURRENT building (null without a context).
     */
    public static function current(): ?ParkingGateStatus
    {
        $building = CurrentBuilding::find();

        return $building === null ? null : self::status($building);
    }

    /**
     * Model-guard helper: throws ParkingGateClosed when the current building is below $level.
     */
    public static function assertLevel(int $level = 1): void
    {
        $status = self::status(CurrentBuilding::get());

        if ($status->level < $level) {
            throw new ParkingGateClosed($level, $status->level);
        }
    }

    public static function residentEnabled(?Building $building = null): bool
    {
        $building ??= CurrentBuilding::find();

        return $building !== null && CurrentBuilding::has() && CurrentBuilding::id() === (int) $building->id
            && self::status($building)->residentEnabled();
    }
}
