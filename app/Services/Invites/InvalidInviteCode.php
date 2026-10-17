<?php

declare(strict_types=1);

namespace App\Services\Invites;

use InvalidArgumentException;

/**
 * A typed invite code that cannot be valid. `reason` drives the friendly LV/RU hint:
 * ambiguous (O/0/I/1/L typed), charset (other characters), length (not 10 characters).
 */
final class InvalidInviteCode extends InvalidArgumentException
{
    private function __construct(public readonly string $reason)
    {
        parent::__construct("Invalid invite code ({$reason}).");
    }

    public static function ambiguous(): self
    {
        return new self('ambiguous');
    }

    public static function charset(): self
    {
        return new self('charset');
    }

    public static function length(): self
    {
        return new self('length');
    }
}
