<?php

declare(strict_types=1);

namespace App\Auth;

use App\Models\User;

/**
 * Outcome of a PIN login. Every failure kind (unknown handle, wrong PIN, locked handle, building paused) is the
 * single generic `failed` result; `cooldown` is driven ONLY by the IP/device counter, never by the handle.
 */
final readonly class PinLoginResult
{
    private function __construct(
        public string $status,
        public ?User $user = null,
        public bool $mustChangePin = false,
    ) {}

    public static function success(User $user, bool $mustChangePin = false): self
    {
        return new self('success', $user, $mustChangePin);
    }

    public static function failed(): self
    {
        return new self('failed');
    }

    public static function cooldown(): self
    {
        return new self('cooldown');
    }

    public function ok(): bool
    {
        return $this->status === 'success';
    }
}
