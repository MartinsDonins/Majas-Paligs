<?php

declare(strict_types=1);

namespace App\Support;

use RuntimeException;

/**
 * Access to the dedicated keys (PLANNING §5 A04). Each key is 32 random bytes, configured as "base64:…".
 * APP_KEY is never used for invites, plates, roster contacts or IP/e-mail HMACs.
 */
final class Keys
{
    public static function invite(): string
    {
        return self::decode('invite', 'INVITE_KEY');
    }

    public static function plateEncryption(): string
    {
        return self::decode('plate_enc', 'PLATE_ENC_KEY');
    }

    public static function plateIndex(): string
    {
        return self::decode('plate_index', 'PLATE_INDEX_KEY');
    }

    /**
     * Previous plate encryption master during a master-key rotation (PLATE_ENC_KEY_PREVIOUS), or null.
     */
    public static function plateEncryptionPrevious(): ?string
    {
        $value = (string) config('majas.keys.plate_enc_previous');

        return $value === '' ? null : self::decode('plate_enc_previous', 'PLATE_ENC_KEY_PREVIOUS');
    }

    /**
     * Resident roster encryption master (CONTACT_ENC_KEY, SEC-011) — ContactVault and the roster ImportVault payloads.
     */
    public static function contactEncryption(): string
    {
        return self::decode('contact_enc', 'CONTACT_ENC_KEY');
    }

    /**
     * Previous roster encryption master during a master-key rotation (CONTACT_ENC_KEY_PREVIOUS), or null.
     */
    public static function contactEncryptionPrevious(): ?string
    {
        $value = (string) config('majas.keys.contact_enc_previous');

        return $value === '' ? null : self::decode('contact_enc_previous', 'CONTACT_ENC_KEY_PREVIOUS');
    }

    /**
     * Roster blind-index master (CONTACT_INDEX_KEY, SEC-011): per-building, per-field HKDF-derived HMAC keys.
     */
    public static function contactIndex(): string
    {
        return self::decode('contact_index', 'CONTACT_INDEX_KEY');
    }

    /**
     * Key for pseudonymous HMACs of e-mails and IPs (derived from INVITE_KEY with a separate label so the
     * invite key itself is never used directly for another purpose).
     */
    public static function identifier(): string
    {
        return hash_hkdf('sha256', self::invite(), 32, 'majas:identifier-hmac:v1');
    }

    public static function generate(): string
    {
        return 'base64:'.base64_encode(random_bytes(32));
    }

    private static function decode(string $name, string $env): string
    {
        $value = (string) config("majas.keys.{$name}");

        if (str_starts_with($value, 'base64:')) {
            $value = (string) base64_decode(substr($value, 7), true);
        }

        if (strlen($value) !== 32) {
            throw new RuntimeException("{$env} is missing or not 32 bytes (run php artisan majas:keys).");
        }

        return $value;
    }
}
