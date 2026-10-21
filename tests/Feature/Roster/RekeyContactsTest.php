<?php

declare(strict_types=1);

use App\Models\AuditEntry;
use App\Models\ResidentContact;
use App\Services\Roster\ContactVault;
use App\Services\Roster\RosterViewer;
use App\Support\Keys;

/*
 * Feature/Roster/RekeyContactsTest (SEC-011): contacts:rekey moves a building to key version + 1 in one transaction —
 * every field re-sealed, blind indexes recomputed; a master-key rotation works with CONTACT_ENC_KEY_PREVIOUS.
 */
it('re-seals every contact of a building with the next key version and keeps them readable', function (): void {
    $b = rosterHouse('rekey-house');
    $admin = rosterAdmin($b);
    $this->actingAs($admin);
    $c = rosterContact($b, $admin, 2, 'Pārslēdzams', [syntheticPhone(71)], 'rekey@example.test');
    $before = $c->refresh()->phones_ciphertext;

    $this->artisan('contacts:rekey', ['building' => 'rekey-house'])->assertSuccessful();

    $c->refresh();
    expect($c->key_version)->toBe(2)
        ->and($c->phones_ciphertext)->not->toBe($before)
        ->and(inBuilding($b, fn () => app(RosterViewer::class)->plain($c))->phones)->toBe([syntheticPhone(71)])
        ->and(inBuilding($b, fn () => AuditEntry::query()->where('action', 'contacts.rekey')->sole()->data))->toEqual(['from' => 1, 'to' => 2, 'contacts' => 1]);

    $bidx = app(ContactVault::class)->bidx((int) $b->id, 'email', 'rekey@example.test');
    expect(inBuilding($b, fn () => ResidentContact::query()->withEmailBidx($bidx)->count()))->toBe(1);

    // New contacts are sealed with the building's current version.
    $new = rosterContact($b, $admin, 3, 'Jauns');
    expect($new->refresh()->key_version)->toBe(2);
});

it('rotates the encryption master with CONTACT_ENC_KEY_PREVIOUS', function (): void {
    $b = rosterHouse('rekey-master');
    $admin = rosterAdmin($b);
    $this->actingAs($admin);
    $c = rosterContact($b, $admin, 2, 'Vecā atslēga', [syntheticPhone(72)]);

    $old = config('majas.keys.contact_enc');
    config(['majas.keys.contact_enc' => Keys::generate(), 'majas.keys.contact_enc_previous' => $old]);

    $this->artisan('contacts:rekey')->assertSuccessful();
    config(['majas.keys.contact_enc_previous' => null]);

    expect(inBuilding($b, fn () => app(RosterViewer::class)->plain($c->refresh()))->name)->toBe('Vecā atslēga');
});
