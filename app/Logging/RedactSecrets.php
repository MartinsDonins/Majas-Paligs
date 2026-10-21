<?php

declare(strict_types=1);

namespace App\Logging;

use Illuminate\Log\Logger;
use Monolog\LogRecord;

/**
 * Monolog tap (MVP-SPEC A.6a): redacts secret keys from log context/extra and strips query strings and
 * fragments from any URL-looking value, so no invite code, login token or signature reaches a log file.
 */
final class RedactSecrets
{
    public const KEYS = [
        'c', 't', 'token', 'code', 'pin', 'new_pin', 'temp_code', 'signature', 'password', 'secret', 'remember_token', 'app_authentication_secret',
        // Round 2 (AC-R2-19): CSV import cells — the paste box, staged payloads — never reach a log or GlitchTip.
        'paste', 'csv', 'payload', 'payload_ciphertext', 'cells',
    ];

    public function __invoke(Logger $logger): void
    {
        $monolog = $logger->getLogger();

        if (! method_exists($monolog, 'pushProcessor')) {
            return;
        }

        $monolog->pushProcessor(static function (LogRecord $record): LogRecord {
            return $record->with(
                message: self::scrubString($record->message),
                context: self::scrub($record->context),
                extra: self::scrub($record->extra),
            );
        });
    }

    /**
     * @param  array<mixed>  $data
     * @return array<mixed>
     */
    public static function scrub(array $data): array
    {
        foreach ($data as $key => $value) {
            if (is_string($key) && in_array(strtolower($key), self::KEYS, true)) {
                $data[$key] = '[redacted]';

                continue;
            }

            if (is_array($value)) {
                $data[$key] = self::scrub($value);
            } elseif (is_string($value)) {
                $data[$key] = self::scrubString($value);
            }
        }

        return $data;
    }

    /**
     * Remove query strings/fragments from URLs and redact secret path segments.
     */
    public static function scrubString(string $value): string
    {
        $value = (string) preg_replace('~(https?://[^\s?#"\']+|/[A-Za-z0-9_\-./]*)[?#][^\s"\']*~', '$1', $value);

        return (string) preg_replace('~(/(?:login/confirm|me/exports|files)/)[^\s/"\'?#]+~', '$1[redacted]', $value);
    }
}
