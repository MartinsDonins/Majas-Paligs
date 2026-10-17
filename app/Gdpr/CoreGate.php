<?php

declare(strict_types=1);

namespace App\Gdpr;

use App\Models\Building;
use App\Models\BuildingPrivacyNotice;
use App\Tenancy\CurrentBuilding;
use App\Tenancy\Exceptions\MissingBuildingContext;

/**
 * Core processing gate L-core (PLANNING §5, MVP-SPEC A.5, GOV-008). Pure function of buildings.settings +
 * building_privacy_notices: open iff controller name + contact, DPA signed + ref, dpa_scope ⊇ {core, heating,
 * outages} and a current (published, not withdrawn) building notice covering the same modules.
 *
 * Requires the building context of $building (callers outside a context use CurrentBuilding::runFor via an
 * allow-listed class, e.g. InviteResolver).
 */
final class CoreGate
{
    public const REQUIRED_MODULES = ['core', 'heating', 'outages'];

    public const CONDITIONS = [
        'controller_name', 'controller_contact', 'dpa_signed_at', 'dpa_ref', 'dpa_scope', 'privacy_notice',
    ];

    public static function status(Building $building): CoreGateStatus
    {
        if (! CurrentBuilding::has() || CurrentBuilding::id() !== (int) $building->id) {
            throw new MissingBuildingContext('CoreGate::status() requires the context of the building it checks.');
        }

        $s = $building->settings;
        $missing = [];

        if ($s->controller['name'] === null) {
            $missing[] = 'controller_name';
        }
        if ($s->controller['contact'] === null) {
            $missing[] = 'controller_contact';
        }
        if ($s->dpaSignedAt === null) {
            $missing[] = 'dpa_signed_at';
        }
        if ($s->dpaRef === null) {
            $missing[] = 'dpa_ref';
        }
        if (array_diff(self::REQUIRED_MODULES, $s->dpaScope) !== []) {
            $missing[] = 'dpa_scope';
        }

        $notice = BuildingPrivacyNotice::current();
        if ($notice === null || ! $notice->covers(self::REQUIRED_MODULES)) {
            $missing[] = 'privacy_notice';
        }

        return new CoreGateStatus(open: $missing === [], missing: $missing, noticeId: $notice?->id);
    }

    /**
     * Model-guard helper: throws CoreGateClosed when the gate is closed.
     */
    public static function assertOpen(Building $building): void
    {
        $status = self::status($building);

        if (! $status->open) {
            throw new CoreGateClosed($status->missing);
        }
    }
}
