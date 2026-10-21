<?php

declare(strict_types=1);

namespace App\Services\Import;

use App\Enums\ImportKind;
use App\Models\ImportRun;
use App\Support\Keys;
use SodiumException;

/**
 * ImportVault (DB-003, ROUND2-SPEC §4.6): the uploaded CSV lives only as XChaCha20-Poly1305-IETF ciphertext in
 * import_runs.payload_ciphertext — nonce(24) ‖ AEAD — with a key derived from the kind's dedicated master
 * (permits: PLATE_ENC_KEY, roster: CONTACT_ENC_KEY; never APP_KEY) and associated data
 * `mp:import:v{n}|b:{building}|r:{run_id}|k:{kind}`, so a payload copied to another run, building or kind does not
 * open. The temp upload is read once, sealed and deleted in the same request.
 */
final class ImportVault
{
    public const VERSION = 1;

    public function seal(ImportRun $run, string $plain): string
    {
        $nonce = random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);

        return $nonce.sodium_crypto_aead_xchacha20poly1305_ietf_encrypt($plain, $this->ad($run), $nonce, $this->key($run->kind));
    }

    /**
     * @throws ImportFailed payload_gone
     */
    public function open(ImportRun $run): string
    {
        $cipher = $run->payload_ciphertext;
        $n = SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES;

        if ($cipher === null || strlen($cipher) <= $n) {
            throw new ImportFailed('payload_gone');
        }

        try {
            $plain = sodium_crypto_aead_xchacha20poly1305_ietf_decrypt(substr($cipher, $n), $this->ad($run), substr($cipher, 0, $n), $this->key($run->kind));
        } catch (SodiumException) {
            $plain = false;
        }

        if (! is_string($plain)) {
            throw new ImportFailed('payload_gone');
        }

        return $plain;
    }

    private function ad(ImportRun $run): string
    {
        return 'mp:import:v'.self::VERSION.'|b:'.$run->building_id.'|r:'.$run->id.'|k:'.$run->kind->value;
    }

    private function key(ImportKind $kind): string
    {
        $master = match ($kind) {
            ImportKind::Permits => Keys::plateEncryption(),
            // CONTACT_ENC_KEY arrives with SEC-011 (ContactVault); until then the roster kind cannot seal anything.
            // @phpstan-ignore function.impossibleType (Keys::contactEncryption() is added by SEC-011)
            ImportKind::Roster => method_exists(Keys::class, 'contactEncryption')
                ? Keys::contactEncryption()
                : throw new ImportFailed('kind_unavailable'),
        };

        return hash_hkdf('sha256', $master, SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES, 'mp:import-enc:v'.self::VERSION);
    }
}
