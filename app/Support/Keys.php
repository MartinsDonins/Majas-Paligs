<?php

declare(strict_types=1);

namespace App\Support;

use RuntimeException;

/**
 * Access to the dedicated keys (PLANNING §5 A04). Each key is 32 random bytes, configured as "base64:…".
 * APP_KEY is never used for invites, plates or IP/e-mail HMACs.
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
