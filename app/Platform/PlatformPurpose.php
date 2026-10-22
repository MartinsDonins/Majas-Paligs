<?php

declare(strict_types=1);

namespace App\Platform;

/**
 * Why the platform panel / console enters a building context (PLANNING §4.2 item 7).
 */
enum PlatformPurpose: string
{
    case Generate = 'generate';
    case StructureEdit = 'structure_edit';

    /** The generator reads a building's stored stairwells to show them and to check a new input against them. */
    case StructureView = 'structure_view';
    case Seed = 'seed';

    /** Bootstrap the first building_admin of a building (MVP-SPEC A.9 "platform_admin bootstrap"). */
    case RoleBootstrap = 'role_bootstrap';

    /** Record the Art. 28 DPA (date, ref, scope) — CoreDigify is the processor, so only the platform sets it. */
    case DpaRecord = 'dpa_record';

    /** "Atvēršanas pieprasījumi": read a house's sent DPA / annex (PDF preview) and record it (ROUND2-SPEC §3.6). */
    case OpeningReview = 'opening_review';

    /**
     * The platform house page (/admin/houses/{slug}, first-run): one house's opening status, structure counts and the
     * platform admin's OWN role / membership there — no resident data (FIRSTRUN-001).
     */
    case HouseOverview = 'house_overview';

    /**
     * "Piesaistīt mani dzīvoklim": a platform_admin links HIMSELF to an apartment, approved at once under a strong
     * step-up (documented exception to "never self-approve", docs/SECURITY.md; FIRSTRUN-001).
     */
    case SelfLink = 'self_link';
}
