<?php

declare(strict_types=1);

use App\Auth\StepUpService;
use App\Enums\BuildingRoleType;
use App\Enums\ContactRelation;
use App\Filament\Manage\Resources\ResidentContactResource\Pages\CreateResidentContact;
use App\Filament\Manage\Resources\ResidentContactResource\Pages\ListResidentContacts;
use App\Models\AuditEntry;
use App\Models\ResidentContact;
use App\Services\Roster\ContactData;
use App\Services\Roster\RosterService;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Livewire\Livewire;

/*
 * AC-R2-13 (Feature/Roster/RosterVisibilityTest): building_admin sees and edits every stairwell, a section elder only
 * their own (404 for another stairwell's contact), parking_officer / volunteer get 403 / 404 on every roster URL,
 * residents never see roster data anywhere; platform_admin without a role does not reach it.
 */
beforeEach(function (): void {
    $this->b = rosterHouse('vis-house');
    $this->admin = rosterAdmin($this->b, 'vis-admin@example.test');
    $this->elder1 = rosterElder($this->b, 1, 'vis-elder1@example.test');
    $this->actingAs($this->admin);
    // Stairwell 1 = apartments 1..12, stairwell 2 = 13..24.
    $this->own = rosterContact($this->b, $this->admin, 2, 'Kāpņu Viens', [syntheticPhone(21)], 'viens@example.test');
    $this->other = rosterContact($this->b, $this->admin, 14, 'Kāpņu Divi', [syntheticPhone(22)], 'divi@example.test');
});

it('lists every stairwell for the admin and only the own stairwell for an elder (masked)', function (): void {
    steppedUp($this->admin);
    $this->actingAs($this->admin)->get('/admin/b/vis-house/roster')->assertOk()
        ->assertSee('Kāpņu Viens')->assertSee('Kāpņu Divi')
        ->assertSee('+371 2•••••'.substr(syntheticPhone(21), -2))
        ->assertDontSee(substr(syntheticPhone(21), 4))
        ->assertDontSee('viens@example.test')
        ->assertSee(__('roster.list.no_contact')); // apartments without contacts are listed too

    $this->flushSession();
    steppedUp($this->elder1);
    $this->actingAs($this->elder1)->get('/admin/b/vis-house/roster')->assertOk()
        ->assertSee('Kāpņu Viens')
        ->assertDontSee('Kāpņu Divi');
});

it('gives an elder 404 for another stairwell\'s contact and lets them edit their own', function (): void {
    $this->flushSession();
    steppedUp($this->elder1, 'passkey');
    $this->actingAs($this->elder1);

    $this->get("/admin/b/vis-house/roster/{$this->other->id}/edit")->assertNotFound();
    $this->get("/admin/b/vis-house/roster/{$this->own->id}/edit")->assertOk();
    // Opening the form shows the plaintext, so it counts as an audited reveal of THAT contact.
    expect(inBuilding($this->b, fn () => AuditEntry::query()->where('action', 'roster.reveal')->where('actor_user_id', $this->elder1->id)->count()))->toBe(1);

    expect(fn () => inBuilding($this->b, fn () => app(RosterService::class)->update($this->elder1, $this->other, new ContactData(name: 'X'))))
        ->toThrow(AuthorizationException::class);

    inBuilding($this->b, fn () => app(RosterService::class)->update($this->elder1, $this->own, new ContactData(relation: ContactRelation::Tenant, name: 'Kāpņu Viens Labots')));
    expect(inBuilding($this->b, fn () => $this->own->refresh()->relation))->toBe(ContactRelation::Tenant);
});

it('lets an elder add contacts only to own-stairwell apartments', function (): void {
    $this->actingAs($this->elder1);
    Filament::setCurrentPanel(Filament::getPanel('manage'));
    Filament::setTenant($this->b);
    app(StepUpService::class)->markFresh($this->elder1, 'email');

    inBuilding($this->b, fn () => Livewire::actingAs($this->elder1)->test(CreateResidentContact::class)
        ->fillForm(['apartment_id' => apartment($this->b, 3)->id, 'relation' => 'owner', 'name' => 'Jauns Kaimiņš', 'phones' => [substr(syntheticPhone(30), 4)]])
        ->call('create')
        ->assertHasNoFormErrors());

    $created = inBuilding($this->b, fn () => ResidentContact::query()->where('apartment_id', apartment($this->b, 3)->id)->firstOrFail());
    expect($created->source->value)->toBe('elder');

    expect(fn () => inBuilding($this->b, fn () => app(RosterService::class)->create($this->elder1, apartment($this->b, 15), new ContactData(name: 'Svešs'))))
        ->toThrow(AuthorizationException::class);
});

it('keeps parking officers, volunteers and residents out of every roster URL', function (): void {
    $officer = parkingOfficer($this->b, 'vis-officer@example.test');
    $volunteer = makeUser('vis-volunteer@example.test');
    grantRole($volunteer, $this->b, BuildingRoleType::Volunteer, 1);
    $resident = makeUser('vis-resident@example.test');
    member($resident, $this->b, 2);

    $urls = [
        '/admin/b/vis-house/roster',
        '/admin/b/vis-house/roster/create',
        "/admin/b/vis-house/roster/{$this->own->id}/edit",
        '/admin/b/vis-house/roster/import',
    ];

    foreach ([$officer, $volunteer, $resident] as $user) {
        $this->flushSession();
        steppedUp($user);
        foreach ($urls as $url) {
            $status = $this->actingAs($user)->get($url)->getStatusCode();
            expect(in_array($status, [403, 404], true))->toBeTrue("{$url} → {$status}");
        }
        expect(inBuilding($this->b, fn () => $user->can('viewAny', ResidentContact::class)))->toBeFalse()
            ->and(inBuilding($this->b, fn () => $user->can('view', $this->own)))->toBeFalse();
    }

    // The resident app shows no roster data anywhere (home, own apartment page).
    $this->flushSession();
    foreach (['/b/vis-house', '/b/vis-house/me'] as $page) {
        $this->actingAs($resident)->get($page)->assertOk()->assertDontSee('Kāpņu Viens')->assertDontSee(__('roster.title'));
    }

    // The template download is admin + elder for the roster, never officers / residents.
    $this->actingAs($officer)->get('/admin/b/vis-house/files/import-template/roster.csv')->assertForbidden();
    $this->actingAs($resident)->get('/admin/b/vis-house/files/import-template/roster.csv')->assertForbidden();
});

it('never lets a platform_admin without a building role read the roster', function (): void {
    $platform = makeUser('vis-platform@example.test', ['platform_role' => 'platform_admin']);

    expect(inBuilding($this->b, fn () => $platform->can('viewAny', ResidentContact::class)))->toBeFalse()
        ->and(inBuilding($this->b, fn () => $platform->can('view', $this->own)))->toBeFalse();

    steppedUp($platform, 'totp');
    // FIRSTRUN-001: instead of a bare 403 he lands on the platform house page (no roster data there).
    $this->actingAs($platform)->get('/admin/b/vis-house/roster')->assertRedirect(route('admin.house', ['building' => 'vis-house']));
});

it('filters the list (stairwell, BIS, no contact) and shows the counts header', function (): void {
    Filament::setCurrentPanel(Filament::getPanel('manage'));
    Filament::setTenant($this->b);
    app(StepUpService::class)->markFresh($this->admin, 'totp');

    $component = inBuilding($this->b, fn () => Livewire::actingAs($this->admin)->test(ListResidentContacts::class));
    $component->assertSee(__('roster.list.stats', ['with' => 2, 'total' => 24, 'percent' => 8, 'bis' => 2, 'whatsapp' => 0]));

    inBuilding($this->b, fn () => Livewire::actingAs($this->admin)->test(ListResidentContacts::class)
        ->filterTable('section_id', section($this->b, 2)->id)
        ->assertSee('Kāpņu Divi')->assertDontSee('Kāpņu Viens'));

    inBuilding($this->b, fn () => Livewire::actingAs($this->admin)->test(ListResidentContacts::class)
        ->filterTable('no_contact', true)
        ->assertDontSee('Kāpņu Viens')->assertDontSee('Kāpņu Divi'));

    inBuilding($this->b, fn () => Livewire::actingAs($this->admin)->test(ListResidentContacts::class)
        ->filterTable('bis', 'no')
        ->assertDontSee('Kāpņu Viens'));
});
