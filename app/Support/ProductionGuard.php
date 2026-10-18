<?php

declare(strict_types=1);

namespace App\Support;

use RuntimeException;

/**
 * Fail-closed boot checks for production (review fix, drošība): Redis holds sessions (auth user id, step-up
 * freshness, the pending TOTP secret), every rate limiter, the PIN pause and the TOTP replay markers — so it must
 * require AUTH; the PostgreSQL connections must not allow a TLS downgrade.
 */
final class ProductionGuard
{
    /**
     * @param  array<string, mixed>  $config  session/cache/queue drivers + redis password
     * @param  array<string, string|null>  $sslModes  sslmode per PostgreSQL connection name
     * @return list<string> problems (empty = OK)
     */
    public static function problems(array $config, array $sslModes): array
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

        $problems = self::problems($config, $sslModes);

        if ($problems !== []) {
            throw new RuntimeException('Unsafe production configuration: '.implode(' ', $problems));
        }
    }
}
