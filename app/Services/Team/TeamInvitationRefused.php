<?php

declare(strict_types=1);

namespace App\Services\Team;

use RuntimeException;

/**
 * A team invitation action was refused for a known reason (ROUND2-SPEC §2.2): role_already_held, invite_pending,
 * self_invite, team_invite_quota, resend_limit, invalid_section, not_open, wrong_email. The message is the translated,
 * person-facing text (never an e-mail address).
 */
final class TeamInvitationRefused extends RuntimeException
{
    public function __construct(public readonly string $reason)
    {
        parent::__construct(__('admin.team.error.'.$reason));
    }
}
