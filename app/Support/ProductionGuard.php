<?php

declare(strict_types=1);

namespace App\Support;

use RuntimeException;

/**
 * Fail-closed boot checks for production (review fix, drošība): Redis holds sessions (auth user id, step-up
 * freshness, the pending TOTP secret), every rate limiter, the PIN pause and the TOTP replay markers — so it must
 * require AUTH; the PostgreSQL connections must not allow a TLS downgrade. The dedicated keys (round 2, SEC-011) must
 * be distinct: a roster key equal to a plate / invite key (or APP_KEY) would let one leaked secret open two registers.
 */
final class ProductionGuard
{
    /**
     * @param  array<string, mixed>  $config  session/cache/queue drivers + redis password
     * @param  array<string, string|null>  $sslModes  sslmode per PostgreSQL connection name
     * @param  array<string, string|null>  $keys  dedicated key values by env name (empty / null = not set)
     * @return list<string> problems (empty = OK)
     */
    public static function problems(array $config, array $sslModes, array $keys = []): array
    {
        $problems = [];
        $usesRedis = in_array('redis', [$config['session.driver'] ?? null, $config['cache.default'] ?? null, $config['queue.default'] ?? null], true);
        $password = $config['database.redis.default.password'] ?? null;

        if ($usesRedis && (! is_string($password) || $password === '' || strtolower($password) === 'null')) {
            $problems[] = 'REDIS_PASSWORD must be set (Redis AUTH) in production.';
        }

        foreach ($sslModes as $connection => $mode) {
            if (in_array($mode, ['disable', 'allow', 'prefer'], true)) {
                $problems[] = "DB_SSLMODE for {$connection} must be require / verify-ca / verify-full in production.";
            }
        }

        $set = array_filter($keys, static fn (?string $v): bool => $v !== null && $v !== '');
        foreach (['CONTACT_ENC_KEY', 'CONTACT_INDEX_KEY', 'CONTACT_ENC_KEY_PREVIOUS'] as $name) {
            if (! isset($set[$name])) {
                continue;
            }
            $value = str_starts_with($set[$name], 'base64:') ? (string) base64_decode(substr($set[$name], 7), true) : $set[$name];
            if (strlen($value) !== 32) {
                $problems[] = "{$name} must be 32 random bytes (php artisan majas:keys).";
            }
            foreach ($set as $other => $otherValue) {
                if ($other !== $name && $otherValue === $set[$name]) {
                    $problems[] = "{$name} must not reuse {$other}.";
                }
            }
        }

        return $problems;
    }

    /**
     * @throws RuntimeException
     */
    public static function assert(): void
    {
        $keys = ['session.driver', 'cache.default', 'queue.default', 'database.redis.default.password'];
        $config = array_combine($keys, array_map(static fn (string $k): mixed => config($k), $keys));

        $sslModes = [];
        foreach ((array) config('database.connections') as $name => $connection) {
            if (is_array($connection) && ($connection['driver'] ?? null) === 'pgsql') {
                $sslModes[(string) $name] = isset($connection['sslmode']) ? (string) $connection['sslmode'] : null;
            }
        }

        $keys = [
            'APP_KEY' => config('app.key'),
            'INVITE_KEY' => config('majas.keys.invite'),
            'PLATE_ENC_KEY' => config('majas.keys.plate_enc'),
            'PLATE_INDEX_KEY' => config('majas.keys.plate_index'),
            'CONTACT_ENC_KEY' => config('majas.keys.contact_enc'),
            'CONTACT_INDEX_KEY' => config('majas.keys.contact_index'),
            'CONTACT_ENC_KEY_PREVIOUS' => config('majas.keys.contact_enc_previous'),
        ];
        $keys = array_map(static fn (mixed $v): ?string => is_string($v) ? $v : null, $keys);

        $problems = self::problems($config, $sslModes, $keys);

        if ($problems !== []) {
            throw new RuntimeException('Unsafe production configuration: '.implode(' ', $problems));
        }
    }
}
