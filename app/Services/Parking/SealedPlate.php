<?php

declare(strict_types=1);

namespace App\Services\Parking;

/**
 * Output of PlateVault::seal(): ciphertext (nonce ‖ AEAD), blind index (32 bytes) and the key version used.
 * Binary strings — store through the Bytea cast.
 */
final readonly class SealedPlate
{
    public function __construct(
        public string $ciphertext,
        public string $bidx,
        public int $keyVersion,
    ) {}
}
