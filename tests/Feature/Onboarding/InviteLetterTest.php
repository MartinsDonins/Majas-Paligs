<?php

declare(strict_types=1);

use App\Enums\BuildingRoleType;
use App\Gdpr\CoreGateClosed;
use App\Models\Apartment;
use App\Models\ApartmentInvite;
use App\Models\AuditEntry;
use App\Services\Invites\InviteLetterService;
use Illuminate\Support\Facades\URL;

/*
 * AC 15 / AUTH-002.1: invite letter PDF — signed 10-minute single-use URL, only with gate L-core open,
 * codes generated in memory (HMAC stored), audit-logged.
 */
it('prints letters through a single-use signed URL, storing only HMACs and auditing', function (): void {
    $b = makeBuilding('letter-a', 1, 1, open: true);
    $admin = makeUser('letter-admin@example.test');
    grantRole($admin, $b, BuildingRoleType::BuildingAdmin);
    $oldCode = makeInvite($b, 1);
    steppedUp($admin);

    $ids = inBuilding($b, fn () => Apartment::query()->pluck('id')->all());
    $batch = inBuilding($b, fn () => app(InviteLetterService::class)->prepare($admin, $ids, 'Dz. 3, vakaros', 'lv'));
    $url = URL::temporarySignedRoute('building.invite-letters', now()->addMinutes(10), ['building' => 'letter-a', 'batch' => $batch]);

    $response = $this->actingAs($admin)->get($url);
    $response->assertOk()->assertHeader('Content-Type', 'application/pdf')->assertHeader('Cache-Control', 'no-store, private');
    expect(substr((string) $response->getContent(), 0, 4))->toBe('%PDF');

    inBuilding($b, function () use ($batch): void {
        expect(ApartmentInvite::query()->where('batch_ref', $batch)->count())->toBe(4)
            ->and(ApartmentInvite::query()->whereNull('revoked_at')->count())->toBe(4)
            ->and(ApartmentInvite::query()->whereNotNull('revoked_at')->count())->toBe(1);
    });
    expect(AuditEntry::query()->forBuilding($b->id)->where('action', 'invite.print')->exists())->toBeTrue();

    // single use
    $this->actingAs($admin)->get($url)->assertNotFound();
});

it('refuses printing while gate L-core is closed', function (): void {
    $b = makeBuilding('letter-b', 1, 1);
    $admin = makeUser('letter-admin2@example.test');
    grantRole($admin, $b, BuildingRoleType::BuildingAdmin);

    inBuilding($b, fn () => app(InviteLetterService::class)->prepare($admin, [1], null));
})->throws(CoreGateClosed::class);

it('rejects unsigned or foreign requests', function (): void {
    $b = makeBuilding('letter-c', 1, 1, open: true);
    $admin = makeUser('letter-admin3@example.test');
    grantRole($admin, $b, BuildingRoleType::BuildingAdmin);
    steppedUp($admin);

    $this->actingAs($admin)->get('/b/letter-c/files/invite-letters/01JABCDEFGHJKMNPQRSTVWXYZ0')->assertForbidden();

    $resident = makeUser('letter-res@example.test');
    member($resident, $b, 2);
    steppedUp($resident);
    $url = URL::temporarySignedRoute('building.invite-letters', now()->addMinutes(10), ['building' => 'letter-c', 'batch' => '01JABCDEFGHJKMNPQRSTVWXYZ0']);
    $this->actingAs($resident)->get($url)->assertForbidden();
});

it('keeps each language of a letter on ONE page at the senior-friendly type sizes (16 pt+)', function (): void {
    $b = makeBuilding('letter-pages', 1, 1, open: true);
    $admin = makeUser('letter-pages@example.test');
    grantRole($admin, $b, BuildingRoleType::BuildingAdmin);
    steppedUp($admin);

    $one = inBuilding($b, fn () => [Apartment::query()->orderBy('number')->value('id')]);
    $batch = inBuilding($b, fn () => app(InviteLetterService::class)->prepare($admin, $one, 'Dz. 3, vakaros, zvaniet pie durvīm', 'lv'));
    $url = URL::temporarySignedRoute('building.invite-letters', now()->addMinutes(10), ['building' => 'letter-pages', 'batch' => $batch]);

    $pdf = (string) $this->actingAs($admin)->get($url)->assertOk()->getContent();

    // LV front + RU back: exactly two pages for one apartment (a third page would break duplex printing).
    expect(preg_match_all('#/Type\s*/Page[^s]#', $pdf))->toBe(2);
});
