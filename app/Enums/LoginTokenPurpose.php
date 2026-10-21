<?php

declare(strict_types=1);

namespace App\Enums;

enum LoginTokenPurpose: string
{
    use HasLabel;

    case Login = 'login';
    case EmailChangeOld = 'email_change_old';
    case EmailChangeNew = 'email_change_new';
    case StepUp = 'step_up';

    /** E-mail code that proves the address of ONE team invitation (login_tokens.role_invitation_id, ROUND2-SPEC §2.2). */
    case TeamInvite = 'team_invite';

    protected static function labelPrefix(): string
    {
        return 'login_token_purpose';
    }
}
