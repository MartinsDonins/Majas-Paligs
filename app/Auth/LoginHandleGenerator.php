<?php

declare(strict_types=1);

namespace App\Auth;

use App\Models\User;
use App\Services\Invites\InviteCodeService;
use RuntimeException;

/**
 * Personal login handle for residents without e-mail: apartment number + 4 chars from the unambiguous
 * alphabet, e.g. "145-K7MQ" (≈923k per apartment prefix), written on the "Mans PIN" card (PLANNING §4.5).
 */
final class LoginHandleGenerator
{
    public function generate(int $apartmentNumber): string
    {
        $alphabet = (string) config('majas.pin.handle_alphabet', InviteCodeService::ALPHABET);
        $length = (int) config('majas.pin.handle_suffix_length', 4);

        for ($i = 0; $i < 20; $i++) {
            $handle = $apartmentNumber.'-'.InviteCodeService::randomString($alphabet, $length);

            if (! User::query()->where('login_handle', $handle)->exists()) {
                return $handle;
            }
        }

        throw new RuntimeException('Could not allocate a unique login handle.');
    }
}
