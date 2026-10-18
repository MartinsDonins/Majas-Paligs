<?php

declare(strict_types=1);

namespace App\Services\Parking;

use App\Models\Building;
use App\Support\Keys;
use SodiumException;

/**
 * PlateVault (PARK-001, MVP-SPEC C.1, ADR 0004). Plates are never stored in plaintext:
 *
 * - ciphertext = nonce(24) ‖ XChaCha20-Poly1305-IETF(normalized plate) with a key derived from the dedicated
 *   PLATE_ENC_KEY (never APP_KEY) for the row's key version; the associated data binds building + table + row id +
 *   key version, so a ciphertext moved to another row, table or building does not open.
 * - blind index = HMAC-SHA256(HKDF-SHA256(PLATE_INDEX_KEY, info "plate-bidx:v{version}", salt building_id),
 *   normalized plate): exact-match lookups inside ONE building; the same plate has different indexes in two buildings.
 *
 * Key rotation: `plates:rekey` moves a building to version+1 in one transaction (re-seal + new bidx). A master key
 * change is supported by configuring the old masters as PLATE_ENC_KEY_PREVIOUS / PLATE_INDEX_KEY_PREVIOUS while
 * re-keying: open() falls back to the previous encryption master. open() is called only on policy-gated paths
 * (reveal, export, own vehicles, re-key).
 */
final class PlateVault
{
    public function seal(string $normalizedPlate, VaultContext $context, int $keyVersion): SealedPlate
    {
        $nonce = random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);
        $cipher = sodium_crypto_aead_xchacha20poly1305_ietf_encrypt(
            $normalizedPlate,
            $context->associatedData($keyVersion),
            $nonce,
            self::encryptionKey(Keys::plateEncryption(), $keyVersion),
        );

        return new SealedPlate(
            ciphertext: $nonce.$cipher,
            bidx: $this->bidx($context->buildingId, $normalizedPlate, $keyVersion),
            keyVersion: $keyVersion,
        );
    }

    /**
     * @throws PlateVaultException
     */
    public function open(string $ciphertext, VaultContext $context, int $keyVersion): string
    {
        $nonceBytes = SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES;

        if (strlen($ciphertext) <= $nonceBytes) {
            throw new PlateVaultException('Ciphertext too short.');
        }

        $nonce = substr($ciphertext, 0, $nonceBytes);
        $body = substr($ciphertext, $nonceBytes);

        foreach (self::encryptionMasters() as $master) {
            try {
                $plain = sodium_crypto_aead_xchacha20poly1305_ietf_decrypt(
                    $body,
                    $context->associatedData($keyVersion),
                    $nonce,
                    self::encryptionKey($master, $keyVersion),
                );
            } catch (SodiumException) {
                $plain = false;
            }

            if (is_string($plain)) {
                return $plain;
            }
        }

        throw new PlateVaultException('Plate ciphertext does not open in this context.');
    }

    /**
     * Blind index of a normalized plate in a building for a key version (default: the building's current one).
     */
    public function bidx(int $buildingId, string $normalizedPlate, int $keyVersion, ?string $indexMaster = null): string
    {
        $key = hash_hkdf('sha256', $indexMaster ?? Keys::plateIndex(), 32, 'plate-bidx:v'.$keyVersion, (string) $buildingId);

        return hash_hmac('sha256', $normalizedPlate, $key, true);
    }

    /**
     * Blind index with the building's CURRENT key version (lookups, sightings, declarations).
     */
    public function currentBidx(Building $building, string $normalizedPlate): string
    {
        return $this->bidx((int) $building->id, $normalizedPlate, $building->settings->plateIndexKeyVersion);
    }

    private static function encryptionKey(string $master, int $keyVersion): string
    {
        return hash_hkdf('sha256', $master, SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES, 'mp:plate-enc:v'.$keyVersion);
    }

    /**
     * Current encryption master first, then the previous one while a master rotation is in progress.
     *
     * @return list<string>
     */
    private static function encryptionMasters(): array
    {
        $masters = [Keys::plateEncryption()];
        $previous = Keys::plateEncryptionPrevious();

        if ($previous !== null) {
            $masters[] = $previous;
        }

        return $masters;
    }
}
