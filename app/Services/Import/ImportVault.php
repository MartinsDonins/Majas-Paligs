<?php

declare(strict_types=1);

namespace App\Services\Import;

use RuntimeException;
use SodiumException;

/**
 * Sealed staging of an uploaded CSV (ROUND2-SPEC §4.6): XChaCha20-Poly1305-IETF with a key derived from the kind's
 * master (CONTACT_ENC_KEY for the roster, PLATE_ENC_KEY for permits — ImportHandler::vaultKey) and associated data
 * `mp:import:v1|b:{building}|r:{run_id}|k:{kind}`, so a payload copied to another run, kind or building does not open.
 * The plaintext CSV exists only in memory while a request or the commit job works on it.
 */
final class ImportVault
{
    public const VERSION = 1;

    public function seal(string $csv, string $master, int $buildingId, string $runId, string $kind): string
    {
        $nonce = random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);

        return $nonce.sodium_crypto_aead_xchacha20poly1305_ietf_encrypt(
            $csv,
            self::associatedData($buildingId, $runId, $kind),
            $nonce,
            self::key($master),
        );
    }

    public function open(string $ciphertext, string $master, int $buildingId, string $runId, string $kind): string
    {
        $nonceBytes = SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES;

        try {
            $plain = strlen($ciphertext) > $nonceBytes ? sodium_crypto_aead_xchacha20poly1305_ietf_decrypt(
                substr($ciphertext, $nonceBytes),
                self::associatedData($buildingId, $runId, $kind),
                substr($ciphertext, 0, $nonceBytes),
                self::key($master),
            ) : false;
        } catch (SodiumException) {
            $plain = false;
        }

        if (! is_string($plain)) {
            throw new RuntimeException('Import payload does not open in this context.');
        }

        return $plain;
    }

    private static function associatedData(int $buildingId, string $runId, string $kind): string
    {
        return 'mp:import:v'.self::VERSION."|b:{$buildingId}|r:{$runId}|k:{$kind}";
    }

    private static function key(string $master): string
    {
        return hash_hkdf('sha256', $master, SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES, 'mp:import-enc:v'.self::VERSION);
    }
}
