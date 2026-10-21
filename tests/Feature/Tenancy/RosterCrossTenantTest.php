<?php

declare(strict_types=1);

use App\Models\ImportRun;
use App\Models\ResidentContact;
use App\Notifications\RosterContactsErased;
use App\Notifications\RosterGateChanged;
use App\Services\Import\ImportRefused;
use App\Services\Import\ImportRunService;
use App\Support\ProductionGuard;
use App\Tenancy\Exceptions\MissingBuildingContext;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Facades\URL;

/*
 * ROUND2-SPEC §9 A01 for the round-2 tables (CrossTenantTest dataset extension): resident_contacts and import_runs of
 * building A are unreachable from building B (scope → 404), fail closed without a context; a non-member gets the
 * anti-enumeration 404 on every roster URL. Plus the count-only alert payloads (AlertPayloadTest) and the dedicated
 * key checks of ProductionGuard.
 */
beforeEach(function (): void {
    $this->a = rosterHouse('rx-a');
    $this->bb = rosterHouse('rx-b');
    $this->adminA = rosterAdmin($this->a, 'rx-admin-a@example.test');
    $this->adminB = rosterAdmin($this->bb, 'rx-admin-b@example.test');
    $this->actingAs($this->adminA);
    $this->contactA = rosterContact($this->a, $this->adminA, 1, 'Mājas A kontakts', [syntheticPhone(95)]);
    $this->runA = uploadCsv($this->a, $this->adminA, rosterCsv([['1', 'A', '', '', '', '', '', '']]));
});

it('keeps building A roster rows and import runs unreachable from building B', function (): void {
    expect(inBuilding($this->bb, fn () => ResidentContact::query()->whereKey($this->contactA->id)->exists()))->toBeFalse()
        ->and(inBuilding($this->bb, fn () => ImportRun::query()->whereKey($this->runA->id)->exists()))->toBeFalse()
        ->and(fn () => inBuilding($this->bb, fn () => app(ImportRunService::class)->find($this->adminB, 'roster', $this->runA->id)))->toThrow(ImportRefused::class)
        ->and(fn () => ResidentContact::query()->count())->toThrow(MissingBuildingContext::class);

    // Admin of B, own panel, A's contact id → 404; A's panel → 404.
    $this->flushSession();
    steppedUp($this->adminB, 'totp');
    $this->actingAs($this->adminB)->get("/admin/b/rx-b/roster/{$this->contactA->id}/edit")->assertNotFound();
    $this->actingAs($this->adminB)->get('/admin/b/rx-a/roster')->assertNotFound();
    $this->actingAs($this->adminB)->get(URL::temporarySignedRoute('admin.roster.extract', now()->addMinutes(5), ['building' => 'rx-b', 'contact' => $this->contactA->id]))->assertNotFound();
});

it('answers 404 for a non-member on every roster file URL, identical to an unknown slug', function (): void {
    $stranger = makeUser('rx-stranger@example.test');
    $this->flushSession();
    steppedUp($stranger);

    foreach (['/admin/b/rx-a/files/import-template/roster.csv', '/admin/b/rx-a/files/roster-export', "/admin/b/rx-a/files/roster-extract/{$this->contactA->id}"] as $url) {
        $this->actingAs($stranger)->get($url)->assertNotFound();
        $this->actingAs($stranger)->get(str_replace('rx-a', 'no-such-house', $url))->assertNotFound();
    }
});

it('keeps roster alerts count-only (no name, phone, e-mail, apartment or user id)', function (): void {
    foreach ([new RosterGateChanged('Testa iela 1', ['dpa_scope_roster']), new RosterContactsErased('Testa iela 1', 3)] as $n) {
        $mail = $n->toMail($this->adminA);
        $payload = json_encode([$mail instanceof MailMessage ? [$mail->subject, $mail->introLines] : [], $n->toArray($this->adminA)]);

        expect($payload)->toContain('Testa iela 1')
            ->not->toContain('@example.test')
            ->not->toContain('"user_id"')
            ->not->toContain('apartment')
            ->not->toContain('Mājas A kontakts');
    }
});

it('refuses roster keys that reuse another dedicated key or are not 32 bytes (ProductionGuard)', function (): void {
    $config = ['session.driver' => 'redis', 'cache.default' => 'redis', 'queue.default' => 'redis', 'database.redis.default.password' => 'pw'];
    $tls = ['app' => 'require'];
    $plate = 'base64:'.base64_encode(str_repeat('p', 32));
    $contact = 'base64:'.base64_encode(str_repeat('c', 32));

    expect(ProductionGuard::problems($config, $tls, ['PLATE_ENC_KEY' => $plate, 'CONTACT_ENC_KEY' => $contact, 'CONTACT_INDEX_KEY' => null]))->toBe([])
        ->and(ProductionGuard::problems($config, $tls, ['PLATE_ENC_KEY' => $plate, 'CONTACT_ENC_KEY' => $plate]))->toBe(['CONTACT_ENC_KEY must not reuse PLATE_ENC_KEY.'])
        ->and(ProductionGuard::problems($config, $tls, ['CONTACT_INDEX_KEY' => 'base64:'.base64_encode('short')]))->toHaveCount(1);
});
