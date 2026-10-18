<?php

declare(strict_types=1);

use App\Support\ProductionGuard;

/*
 * Review fix (drošība, 0.0.12): production refuses to boot on an unauthenticated Redis or a downgradable DB TLS mode.
 */
function guardConfig(array $overrides = []): array
{
    return array_merge([
        'session.driver' => 'redis', 'cache.default' => 'redis', 'queue.default' => 'redis',
        'database.redis.default.password' => 's3cret-redis',
    ], $overrides);
}

const GUARD_TLS = ['app' => 'require', 'owner' => 'verify-full', 'retention' => 'require'];

it('accepts Redis AUTH + required TLS', function (): void {
    expect(ProductionGuard::problems(guardConfig(), GUARD_TLS))->toBe([]);
});

it('refuses a missing / "null" Redis password when Redis backs sessions, cache or queue', function (?string $password): void {
    expect(ProductionGuard::problems(guardConfig(['database.redis.default.password' => $password]), GUARD_TLS))->toHaveCount(1);
})->with([null, '', 'null']);

it('ignores the Redis password when nothing uses Redis', function (): void {
    expect(ProductionGuard::problems(guardConfig([
        'session.driver' => 'database', 'cache.default' => 'database', 'queue.default' => 'database',
        'database.redis.default.password' => null,
    ]), GUARD_TLS))->toBe([]);
});

it('refuses prefer / allow / disable sslmodes', function (string $mode): void {
    expect(ProductionGuard::problems(guardConfig(), ['app' => $mode] + GUARD_TLS))->toHaveCount(1);
})->with(['prefer', 'allow', 'disable']);
