<?php

declare(strict_types=1);

namespace App\Support\ErrorLog;

use App\Logging\RedactSecrets;

/**
 * Makes an exception message safe for app_error_events (OPS-001): no personal data, ever.
 *
 * Order matters: SQL bindings and quoted values go first (they carry whatever a person typed), then e-mails, IPs,
 * phone numbers and licence plates, then URL query strings / secret path segments (RedactSecrets). The result is
 * whitespace-collapsed and cut to MAX_LENGTH characters. Over-redaction is accepted: the exception class and
 * file:line identify the bug, the message only has to hint at it.
 */
final class ErrorLogScrubber
{
    public const MAX_LENGTH = 500;

    public static function scrub(string $message): string
    {
        // Illuminate\Database\QueryException appends "(Connection: pgsql, SQL: insert … values (<bindings>))".
        $message = (string) preg_replace('/\s*\(Connection:\s.*$/s', '', $message);
        $message = (string) preg_replace('/,?\s*SQL:\s.*$/s', '', $message);
        // PostgreSQL DETAIL: "Key (email)=(someone@example.test) already exists." / "Failing row contains (…)".
        $message = (string) preg_replace('/=\((?:[^()]|\([^()]*\))*\)/', '=([redacted])', $message);
        $message = (string) preg_replace('/(Failing row contains\s*)\(.*?\)(?=\.|\s|$)/s', '$1([redacted])', $message);

        // Query strings, with or without a URL in front ("… ?t=SECRET"): invite codes, signatures, search terms.
        $message = (string) preg_replace('/\?[\w.\-\[\]%]+=[^\s"\'<>]*/u', '?[redacted]', $message);

        // Quoted values, unless they look like an identifier (array key, config key, column, class name).
        $message = (string) preg_replace_callback(
            '/(["\'`])((?:(?!\1).){1,200})\1/su',
            static fn (array $m): string => self::isIdentifier($m[2]) ? $m[0] : $m[1].'[redacted]'.$m[1],
            $message,
        );

        $message = (string) preg_replace('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/iu', '[email]', $message);
        $message = (string) preg_replace('/\b(?:\d{1,3}\.){3}\d{1,3}\b/', '[ip]', $message);
        $message = (string) preg_replace('/\b(?:[0-9a-f]{1,4}:){2,7}[0-9a-f]{1,4}\b/i', '[ip]', $message);
        // Phone numbers: an optional "+" and at least 8 digits, separated by spaces, dots, dashes or brackets.
        $message = (string) preg_replace('/(?<![\w+])\+?\d(?:[\s().\-]?\d){7,}(?!\w)/u', '[phone]', $message);
        // Licence plates (LV "AB-1234", "EV 123", Cyrillic look-alikes; lower-case input "ab 1234" with 3–4 digits,
        // so "utf-8" or "line 42" survive).
        $message = (string) preg_replace('/(?<![\p{L}\p{N}_\\\\])\p{Lu}{1,3}[\s\-]?\d{1,4}(?![\p{L}\p{N}_])/u', '[plate]', $message);
        $message = (string) preg_replace('/(?<![\p{L}\p{N}_\\\\])\p{Ll}{1,3}[\s\-]?\d{3,4}(?![\p{L}\p{N}_])/u', '[plate]', $message);
        // Long opaque tokens (invite codes, signatures, session ids, hashes).
        $message = (string) preg_replace('/\b(?=[A-Za-z0-9_\-]*\d)(?=[A-Za-z0-9_\-]*[A-Za-z])[A-Za-z0-9_\-]{24,}\b/', '[token]', $message);

        $message = RedactSecrets::scrubString($message);
        $message = trim((string) preg_replace('/\s+/u', ' ', $message));

        return mb_strlen($message) > self::MAX_LENGTH ? mb_substr($message, 0, self::MAX_LENGTH - 1).'…' : $message;
    }

    /**
     * "plate_index", "heating.report.cta", "App\Models\User", "users_email_unique" — never a name, address, phone or
     * e-mail. A single lower-case word ("janis") could be what someone typed, so it needs a separator to count.
     */
    private static function isIdentifier(string $value): bool
    {
        return preg_match('/^[a-z][a-z0-9]*(?:[_.:\-][a-z0-9]+)+$/', $value) === 1
            || preg_match('/^\\\\?[A-Z][A-Za-z0-9_]*(?:\\\\[A-Z][A-Za-z0-9_]*)+$/', $value) === 1;
    }
}
