<?php

declare(strict_types=1);

namespace App\Auth;

/**
 * Argon2id hashing for PINs and temporary reset codes (PLANNING §4.5). The dummy verify keeps the timing of an
 * unknown handle identical to a wrong PIN.
 */
class PinHasher
{
    private ?string $dummyHash = null;

    public function hash(#[\SensitiveParameter] string $secret): string
    {
        return password_hash($secret, PASSWORD_ARGON2ID, $this->options());
    }

    public function verify(#[\SensitiveParameter] string $secret, string $hash): bool
    {
        return password_verify($secret, $hash);
    }

    /**
     * Burn the same CPU/memory as a real verification (unknown handle, locked handle).
     */
    public function dummyVerify(#[\SensitiveParameter] string $secret): void
    {
        $this->dummyHash ??= password_hash('dummy-'.bin2hex(random_bytes(8)), PASSWORD_ARGON2ID, $this->options());
        password_verify($secret, $this->dummyHash);
    }

    /**
     * @return array{memory_cost: int, time_cost: int, threads: int}
     */
    private function options(): array
    {
        return [
            'memory_cost' => (int) config('majas.pin.argon.memory_cost', 65536),
            'time_cost' => (int) config('majas.pin.argon.time_cost', 4),
            'threads' => 1,
        ];
    }
}
