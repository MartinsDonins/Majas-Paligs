<?php

declare(strict_types=1);

use App\Services\Parking\PlateVault;
use App\Services\Parking\PlateVaultException;
use App\Services\Parking\VaultContext;

/*
 * MVP-SPEC C.8 Unit/PlateVaultTest (AC 6): round trip, per-building blind index, key versions, AEAD context binding.
 */
beforeEach(function (): void {
    $this->vault = new PlateVault;
    $this->ctx = new VaultContext(7, 'vehicles', '01jabcdefghjkmnpqrstvwxyz0');
});

it('round-trips a plate and never stores it in clear', function (): void {
    $sealed = $this->vault->seal('AB1234', $this->ctx, 1);

    expect($sealed->ciphertext)->not->toContain('AB1234')
        ->and(strlen($sealed->bidx))->toBe(32)
        ->and($this->vault->open($sealed->ciphertext, $this->ctx, 1))->toBe('AB1234');
});

it('uses a random nonce (same plate → different ciphertext, same index)', function (): void {
    $a = $this->vault->seal('AB1234', $this->ctx, 1);
    $b = $this->vault->seal('AB1234', $this->ctx, 1);

    expect($a->ciphertext)->not->toBe($b->ciphertext)->and($a->bidx)->toBe($b->bidx);
});

it('gives the same plate different blind indexes in two buildings and two key versions', function (): void {
    expect($this->vault->bidx(1, 'AB1234', 1))->not->toBe($this->vault->bidx(2, 'AB1234', 1))
        ->and($this->vault->bidx(1, 'AB1234', 1))->not->toBe($this->vault->bidx(1, 'AB1234', 2))
        ->and($this->vault->bidx(1, 'AB1234', 1))->toBe($this->vault->bidx(1, 'AB1234', 1));
});

it('refuses to open a ciphertext moved to another row, table, building or key version', function (VaultContext $other, int $version): void {
    $sealed = $this->vault->seal('AB1234', $this->ctx, 1);

    expect(fn () => $this->vault->open($sealed->ciphertext, $other, $version))->toThrow(PlateVaultException::class);
})->with([
    'other row' => [fn () => new VaultContext(7, 'vehicles', '01jabcdefghjkmnpqrstvwxyz1'), 1],
    'other table' => [fn () => new VaultContext(7, 'sightings', '01jabcdefghjkmnpqrstvwxyz0'), 1],
    'other building' => [fn () => new VaultContext(8, 'vehicles', '01jabcdefghjkmnpqrstvwxyz0'), 1],
    'other version' => [fn () => new VaultContext(7, 'vehicles', '01jabcdefghjkmnpqrstvwxyz0'), 2],
]);

it('detects tampering', function (): void {
    $sealed = $this->vault->seal('AB1234', $this->ctx, 1);
    $tampered = substr($sealed->ciphertext, 0, -1).chr(ord(substr($sealed->ciphertext, -1)) ^ 1);

    expect(fn () => $this->vault->open($tampered, $this->ctx, 1))->toThrow(PlateVaultException::class)
        ->and(fn () => $this->vault->open('short', $this->ctx, 1))->toThrow(PlateVaultException::class);
});

it('opens with the previous encryption master during a master-key rotation', function (): void {
    $sealed = $this->vault->seal('AB1234', $this->ctx, 1);
    $old = config('majas.keys.plate_enc');

    config(['majas.keys.plate_enc' => 'base64:'.base64_encode(str_repeat('n', 32))]);
    expect(fn () => $this->vault->open($sealed->ciphertext, $this->ctx, 1))->toThrow(PlateVaultException::class);

    config(['majas.keys.plate_enc_previous' => $old]);
    expect($this->vault->open($sealed->ciphertext, $this->ctx, 1))->toBe('AB1234');
});

it('rejects unknown tables in the vault context', function (): void {
    expect(fn () => new VaultContext(1, 'users', 'x'))->toThrow(InvalidArgumentException::class);
});
