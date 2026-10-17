<?php

declare(strict_types=1);

it('has libsodium XChaCha20-Poly1305 (PlateVault)', function (): void {
    expect(function_exists('sodium_crypto_aead_xchacha20poly1305_ietf_encrypt'))->toBeTrue();
});

it('has Argon2id (PINs and temporary codes)', function (): void {
    expect(defined('PASSWORD_ARGON2ID'))->toBeTrue()
        ->and(password_get_info(password_hash('x', PASSWORD_ARGON2ID, ['memory_cost' => 1024, 'time_cost' => 1, 'threads' => 1]))['algoName'])->toBe('argon2id');
});

it('has the required PHP extensions', function (string $ext): void {
    expect(extension_loaded($ext))->toBeTrue();
})->with(['intl', 'gd', 'pdo_pgsql', 'sodium', 'redis', 'zip']);
