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

    protected static function labelPrefix(): string
    {
        return 'login_token_purpose';
    }
}
