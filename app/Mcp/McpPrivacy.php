<?php

declare(strict_types=1);

namespace App\Mcp;

use App\Models\RoleInvitation;

/**
 * The MCP privacy rule (docs/MCP.md §Privacy, ADR 0008): tool results carry counts, statuses, ids, apartment and
 * stairwell numbers and MASKED hints only — never a name, e-mail address, phone number, plate, PIN handle, code or free
 * text typed by a person.
 *
 * Tools build their results from whitelisted columns; assertClean() is the backstop over the finished result: a key
 * that names personal data, or a string that looks like an e-mail address or a phone number, withholds the whole result
 * (McpPrivacyViolation → the caller sees "withheld", the audit row says `withheld`).
 */
final class McpPrivacy
{
    /** Result keys that must never appear (whatever their value). */
    private const FORBIDDEN_KEYS = [
        'email', 'e_mail', 'phone', 'tel', 'plate', 'plate_number', 'display_name', 'full_name', 'first_name',
        'last_name', 'login_handle', 'handle', 'pin', 'pin_hash', 'password', 'token', 'secret', 'code', 'invite_code',
        'note', 'note_private', 'comment', 'reason_text', 'ip', 'ip_hmac', 'contact',
    ];

    private const EMAIL = '/[A-Za-z0-9._%+-]+@[A-Za-z0-9-]+(\.[A-Za-z0-9-]+)*\.[A-Za-z]{2,}/u';

    /**
     * 8+ digits, optionally with a leading + and single spaces (Latvian mobiles: 8 digits, +371 2…). Dates and times
     * (dashes, colons), decimals and digit runs inside ids / hashes (letters around them) do not match.
     */
    private const PHONE = '/(?<![\p{L}\d:.\/-])\+?\d(?: ?\d){7,}(?![\p{L}\d:\/-])/u';

    /**
     * @param  array<array-key, mixed>  $result
     *
     * @throws McpPrivacyViolation
     */
    public static function assertClean(array $result): void
    {
        array_walk_recursive($result, static function (mixed $value, int|string $key): void {
            if (is_string($key) && in_array(strtolower($key), self::FORBIDDEN_KEYS, true)) {
                throw new McpPrivacyViolation("key:{$key}");
            }

            if (is_string($value) && (preg_match(self::EMAIL, $value) === 1 || preg_match(self::PHONE, $value) === 1)) {
                throw new McpPrivacyViolation('value:'.(is_string($key) ? $key : 'item'));
            }
        });

        // array_walk_recursive skips keys that hold arrays: check those too.
        self::assertKeys($result);
    }

    public static function isForbiddenKey(int|string $key): bool
    {
        return is_string($key) && in_array(strtolower($key), self::FORBIDDEN_KEYS, true);
    }

    /**
     * Masked e-mail hint (`j•••n@e•••.test`) — the same shape the Komanda page shows.
     */
    public static function maskEmail(?string $email): ?string
    {
        return $email === null || $email === '' ? null : RoleInvitation::hint($email);
    }

    /**
     * @param  array<array-key, mixed>  $data
     */
    private static function assertKeys(array $data): void
    {
        foreach ($data as $key => $value) {
            if (is_string($key) && in_array(strtolower($key), self::FORBIDDEN_KEYS, true)) {
                throw new McpPrivacyViolation("key:{$key}");
            }

            if (is_array($value)) {
                self::assertKeys($value);
            }
        }
    }
}
