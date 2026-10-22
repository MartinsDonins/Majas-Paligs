<?php

declare(strict_types=1);

namespace App\Services\Memberships;

use RuntimeException;

/**
 * A refused "Piesaistīt mani dzīvoklim" / "Kļūt par administratoru" (FIRSTRUN-001). The message is a reason code
 * (`house_closed`, `already_member`, `apartment_not_found`, `already_admin`); the UI translates it with
 * `start.refused.<code>`. Never carries personal data.
 */
final class SelfLinkRefused extends RuntimeException
{
    public function __construct(public readonly string $reason)
    {
        parent::__construct($reason);
    }

    public function label(): string
    {
        return __('start.refused.'.$this->reason);
    }
}
