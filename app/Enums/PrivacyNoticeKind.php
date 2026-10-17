<?php

declare(strict_types=1);

namespace App\Enums;

enum PrivacyNoticeKind: string
{
    use HasLabel;

    case PlatformAccount = 'platform_account';
    case BuildingTemplate = 'building_template';

    protected static function labelPrefix(): string
    {
        return 'privacy_notice_kind';
    }
}
