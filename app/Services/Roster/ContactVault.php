<?php

declare(strict_types=1);

namespace App\Services\Roster;

use App\Models\ResidentContact;
use App\Support\Keys;
use InvalidArgumentException;
use SodiumException;

/**
 * ContactVault (SEC-011, ROUND2-SPEC §5.2) — same construction as PlateVault, with its own dedicated keys:
 *
 * - ciphertext = nonce(24) ‖ XChaCha20-Poly1305-IETF(plaintext) with a key derived from CONTACT_ENC_KEY for the row's
 *   key version. The associated data binds key version + building + table + row id + FIELD
 *   (`mp:contact:v{ver}|b:{building}|t:resident_contacts|r:{row}|f:{field}`), so a ciphertext copied to another
 *   column, row, table or building does not open.
 * - blind index = HMAC-SHA256(HKDF(CONTACT_INDEX_KEY, info "contact-bidx:" ‖ field, salt building_id), normalised
 *   value): exact-match lookups inside ONE building (dedup, account linking, GDPR export / erasure matching).
 *
 * Rotation: `contacts:rekey` moves a building to key version + 1 in one transaction; while a master is replaced the old
 * one is configured as CONTACT_ENC_KEY_PREVIOUS and open() falls back to it. open() is called only from RosterViewer,
 * RosterExporter, GdprExporter and ContactRekeyService (Arch/VaultCallersTest).
 */
final class ContactVault
{
    public const FIELDS = ['name', 'phones', 'email', 'comment'];

    public const INDEXED = ['phone', 'email'];

    public function seal(string $plaintext, int $buildingId, string $rowId, string $field, int $keyVersion): string
    {
        $nonce = random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);

        return $nonce.sodium_crypto_aead_xchacha20poly1305_ietf_encrypt(
            $plaintext,
            self::associatedData($buildingId, $rowId, $field, $keyVersion),
            $nonce,
            self::encryptionKey(Keys::contactEncryption(), $keyVersion),
        );
    }

    /**
     * @throws ContactVaultException
     */
    public function open(string $ciphertext, int $buildingId, string $rowId, string $field, int $keyVersion): string
    {
        $nonceBytes = SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES;

        if (strlen($ciphertext) <= $nonceBytes) {
            throw new ContactVaultException('Ciphertext too short.');
        }

        $nonce = substr($ciphertext, 0, $nonceBytes);
        $body = substr($ciphertext, $nonceBytes);

        foreach (self::encryptionMasters() as $master) {
            try {
                $plain = sodium_crypto_aead_xchacha20poly1305_ietf_decrypt(
                    $body,
                    self::associatedData($buildingId, $rowId, $field, $keyVersion),
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

        throw new ContactVaultException('Contact ciphertext does not open in this context.');
    }

    /**
     * Open one field of a contact row (null column → null).
     *
     * @throws ContactVaultException
     */
    public function openField(ResidentContact $contact, string $field): ?string
    {
        $ciphertext = $contact->getAttribute($field.'_ciphertext');

        if (! is_string($ciphertext) || $ciphertext === '') {
            return null;
        }

        return $this->open($ciphertext, (int) $contact->building_id, (string) $contact->id, $field, (int) $contact->key_version);
    }

    /**
     * Blind index of a normalised phone (E.164) or e-mail (lower-case) in a building.
     */
    public function bidx(int $buildingId, string $field, string $normalized): string
    {
        if (! in_array($field, self::INDEXED, true)) {
            throw new InvalidArgumentException("ContactVault: no blind index for [{$field}].");
        }

        $key = hash_hkdf('sha256', Keys::contactIndex(), 32, 'contact-bidx:'.$field, (string) $buildingId);

        return hash_hmac('sha256', $normalized, $key, true);
    }

    public static function normalizeEmail(string $email): string
    {
        return mb_strtolower(trim($email));
    }

    private static function associatedData(int $buildingId, string $rowId, string $field, int $keyVersion): string
    {
        if (! in_array($field, self::FIELDS, true)) {
            throw new InvalidArgumentException("ContactVault: unknown field [{$field}].");
        }

        return "mp:contact:v{$keyVersion}|b:{$buildingId}|t:resident_contacts|r:{$rowId}|f:{$field}";
    }

    private static function encryptionKey(string $master, int $keyVersion): string
    {
        return hash_hkdf('sha256', $master, SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES, 'mp:contact-enc:v'.$keyVersion);
    }

    /**
     * @return list<string>
     */
    private static function encryptionMasters(): array
    {
        $masters = [Keys::contactEncryption()];
        $previous = Keys::contactEncryptionPrevious();

        if ($previous !== null) {
            $masters[] = $previous;
        }

        return $masters;
    }
}
