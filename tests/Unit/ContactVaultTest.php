<?php

declare(strict_types=1);

use App\Services\Roster\ContactVault;
use App\Services\Roster\ContactVaultException;
use App\Support\Keys;

/*
 * Unit/ContactVaultTest (SEC-011): XChaCha20-Poly1305 with AD bound to key version + building + table + row + FIELD;
 * per-building, per-field blind indexes; the previous master opens old ciphertext during a rotation.
 */
it('round-trips and binds the ciphertext to building, row, field and key version', function (): void {
    $vault = new ContactVault;
    $row = '01jabcdefghjkmnpqrstvwxyz0';
    $c = $vault->seal('Anna Paraudziņa', 7, $row, 'name', 1);

    expect($vault->open($c, 7, $row, 'name', 1))->toBe('Anna Paraudziņa')
        ->and($c)->not->toContain('Anna');

    foreach ([[8, $row, 'name', 1], [7, '01jabcdefghjkmnpqrstvwxyz1', 'name', 1], [7, $row, 'comment', 1], [7, $row, 'name', 2]] as [$building, $r, $field, $version]) {
        expect(fn () => $vault->open($c, $building, $r, $field, $version))->toThrow(ContactVaultException::class);
    }
});

it('derives blind indexes per building and per field', function (): void {
    $vault = new ContactVault;
    $phone = syntheticPhone(5);

    expect($vault->bidx(1, 'phone', $phone))->toBe($vault->bidx(1, 'phone', $phone))
        ->and(strlen($vault->bidx(1, 'phone', $phone)))->toBe(32)
        ->and($vault->bidx(1, 'phone', $phone))->not->toBe($vault->bidx(2, 'phone', $phone))
        ->and($vault->bidx(1, 'phone', 'x@example.test'))->not->toBe($vault->bidx(1, 'email', 'x@example.test'))
        ->and(fn () => $vault->bidx(1, 'name', 'Anna'))->toThrow(InvalidArgumentException::class);
});

it('opens with CONTACT_ENC_KEY_PREVIOUS during a master rotation and never uses the plate keys', function (): void {
    $vault = new ContactVault;
    $c = $vault->seal('Paraugs', 1, 'r1', 'comment', 1);

    $old = config('majas.keys.contact_enc');
    config(['majas.keys.contact_enc' => Keys::generate()]);
    expect(fn () => $vault->open($c, 1, 'r1', 'comment', 1))->toThrow(ContactVaultException::class);

    config(['majas.keys.contact_enc_previous' => $old]);
    expect($vault->open($c, 1, 'r1', 'comment', 1))->toBe('Paraugs');

    expect(Keys::contactEncryption())->not->toBe(Keys::plateEncryption())
        ->and(Keys::contactIndex())->not->toBe(Keys::plateIndex());
});

it('refuses missing keys with the env name, never a value', function (): void {
    config(['majas.keys.contact_index' => '']);

    expect(fn () => Keys::contactIndex())->toThrow(RuntimeException::class, 'CONTACT_INDEX_KEY');
});
