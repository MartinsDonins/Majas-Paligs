<?php

declare(strict_types=1);

use App\Auth\StepUpService;
use App\Enums\BuildingRoleType;
use App\Enums\MembershipRole;
use App\Enums\MembershipStatus;
use App\Livewire\Admin\PlatformHouse;
use App\Models\ApartmentMembership;
use App\Models\AuditEntry;
use App\Models\BuildingRole;
use App\Models\User;
use App\Notifications\TeamMemberJoined;
use App\Services\Memberships\SelfLinkService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

/*
 * FIRSTRUN-001 — the platform admin's first run: the house page /admin/houses/{slug}, "Kļūt par šīs mājas
 * administratoru" and "Piesaistīt mani dzīvoklim" (strong step-up, own account only, both audit chains), and the
 * explained dead ends (/admin/b/{slug} and /b/{slug} without a role). Synthetic data only.
 */

beforeEach(function (): void {
    Notification::fake();
    $this->b = makeBuilding('first-house', 2, 2);
    $this->platform = makeUser('platform-owner@example.test', ['platform_role' => 'platform_admin']);
});

function factorNow(User $user, string $method): void
{
    test()->actingAs($user);
    session()->put(StepUpService::SESSION_KEY, ['user' => $user->id, 'at' => now()->getTimestamp(), 'seen' => now()->getTimestamp(), 'method' => $method]);
}

it('is a 404 for everyone but a platform_admin, and asks a platform_admin for his second factor first', function (): void {
    $admin = makeUser();
    grantRole($admin, $this->b, BuildingRoleType::BuildingAdmin);
    steppedUp($admin, 'totp');

    $this->actingAs($admin)->get('/admin/houses/first-house')->assertNotFound();

    $this->flushSession();
    $this->actingAs($this->platform)->get('/admin/houses/first-house')->assertRedirect(route('step-up'));
});

it('explains a closed house without a role: no role, opening x of 4, what is missing, the claim button', function (): void {
    steppedUp($this->platform, 'totp');

    $this->actingAs($this->platform)->get('/admin/houses/first-house')->assertOk()
        ->assertSee($this->b->name)
        ->assertSee(__('start.house.no_role'))
        ->assertSee(__('start.house.core_progress', ['done' => 1, 'total' => 4]))
        ->assertSee(__('start.house.claim_button'))
        ->assertSee(__('start.house.link_closed'))
        ->assertSee('data-testid="view-switch"', false)
        ->assertHeader('Cache-Control', 'no-store, private');

    // Reading the house page is a platform-context entry (purpose house_overview), not a silent cross-tenant read.
    expect(AuditEntry::query()->platformChain()->where('action', 'platform.building_context')->where('data->purpose', 'house_overview')->exists())->toBeTrue();
});

it('lets the platform_admin become the house admin only with a passkey / TOTP of this session — audited on both chains', function (): void {
    $existing = makeUser('house-admin@example.test');
    grantRole($existing, $this->b, BuildingRoleType::BuildingAdmin);

    factorNow($this->platform, 'trusted');
    Livewire::test(PlatformHouse::class, ['building' => $this->b])
        ->set('mandateRef', 'Kopības lēmums Nr. 3')
        ->call('claimAdmin')
        ->assertRedirect(route('step-up'));
    expect(inBuilding($this->b, fn () => BuildingRole::query()->where('user_id', $this->platform->id)->exists()))->toBeFalse();

    factorNow($this->platform, 'totp');
    Livewire::test(PlatformHouse::class, ['building' => $this->b])
        ->set('mandateRef', 'Kopības lēmums Nr. 3')
        ->call('claimAdmin')
        ->assertSet('done', 'claimed')
        ->assertSee(__('start.house.open_panel'));

    $role = inBuilding($this->b, fn () => BuildingRole::query()->where('user_id', $this->platform->id)->firstOrFail());
    expect($role->role)->toBe(BuildingRoleType::BuildingAdmin)
        ->and($role->mandate_ref)->toBe('Kopības lēmums Nr. 3')
        ->and((int) $role->granted_by)->toBe($this->platform->id)
        ->and(AuditEntry::query()->where('building_id', $this->b->id)->where('action', 'role.grant')->where('actor_user_id', $this->platform->id)->exists())->toBeTrue()
        ->and(AuditEntry::query()->platformChain()->where('action', 'platform.self_admin')->exists())->toBeTrue()
        ->and(AuditEntry::query()->platformChain()->where('action', 'platform.building_context')->where('data->purpose', 'role_bootstrap')->exists())->toBeTrue();

    // The house's own administrator is told (count-free text).
    Notification::assertSentTo($existing, TeamMemberJoined::class);

    // The panel now opens for him (Komanda marks the self-granted role); a second claim is refused calmly.
    steppedUp($this->platform, 'totp');
    $this->get('/admin/b/first-house')->assertOk()->assertSee('data-testid="getting-started"', false);
    $this->get('/admin/b/first-house/team')->assertOk()->assertSee(__('start.self.granted_mark'));
    factorNow($this->platform, 'totp');
    Livewire::test(PlatformHouse::class, ['building' => $this->b])->call('claimAdmin')->assertSet('refused', 'already_admin');
});

it('links the platform_admin to his apartment only after the house is open — approved, marked self-approved, audited', function (): void {
    factorNow($this->platform, 'passkey');

    Livewire::test(PlatformHouse::class, ['building' => $this->b])
        ->assertDontSee(__('start.link.button'))
        ->set('apartmentNumber', '7')
        ->call('linkSelf')
        ->assertSet('refused', 'house_closed');
    expect(inBuilding($this->b, fn () => ApartmentMembership::query()->count()))->toBe(0);

    openGate($this->b);
    Livewire::test(PlatformHouse::class, ['building' => $this->b])
        ->set('apartmentNumber', '999')
        ->call('linkSelf')
        ->assertSet('refused', 'apartment_not_found');

    Livewire::test(PlatformHouse::class, ['building' => $this->b])
        ->set('apartmentNumber', '7')
        ->set('role', 'owner')
        ->call('linkSelf')
        ->assertSet('done', 'linked');

    $membership = inBuilding($this->b, fn () => ApartmentMembership::query()->where('user_id', $this->platform->id)->firstOrFail());
    $entry = AuditEntry::query()->where('building_id', $this->b->id)->where('action', 'membership.self_link')->firstOrFail();

    expect($membership->status)->toBe(MembershipStatus::Approved)
        ->and((int) $membership->approved_by)->toBe($this->platform->id)
        ->and(SelfLinkService::isSelfApproved($membership))->toBeTrue()
        ->and($entry->reason_code)->toBe('self_approved')
        ->and($entry->subject_user_ids)->toBe([$this->platform->id])
        ->and(AuditEntry::query()->platformChain()->where('action', 'platform.self_link')->exists())->toBeTrue()
        ->and(AuditEntry::query()->platformChain()->where('action', 'platform.building_context')->where('data->purpose', 'self_link')->exists())->toBeTrue();

    // Only once: a second apartment in the same house is refused.
    Livewire::test(PlatformHouse::class, ['building' => $this->b])->set('apartmentNumber', '8')->call('linkSelf')->assertSet('refused', 'already_member');
});

it('never lets the self-link path approve anybody else, or a non-platform admin approve himself', function (): void {
    openGate($this->b);
    $admin = makeUser('only-admin@example.test');
    grantRole($admin, $this->b, BuildingRoleType::BuildingAdmin);
    factorNow($admin, 'totp');

    // A building_admin's own request stays pending (never self-approve) …
    $pending = inBuilding($this->b, fn () => app(SelfLinkService::class)->link($admin, 3, MembershipRole::Owner));
    expect($pending->status)->toBe(MembershipStatus::Pending)
        ->and($pending->approved_by)->toBeNull();

    // … and he cannot ask the service for the platform exception.
    $other = makeUser();
    grantRole($other, $this->b, BuildingRoleType::BuildingAdmin);
    factorNow($other, 'totp');
    expect(fn () => inBuilding($this->b, fn () => app(SelfLinkService::class)->link($other, 4, MembershipRole::Owner, selfApprove: true)))
        ->toThrow(AuthorizationException::class);
});

it('turns the bare 403 of a house panel and the 404 of the resident app into the explained house page for a platform_admin', function (): void {
    steppedUp($this->platform, 'totp');

    $this->actingAs($this->platform)->get('/admin/b/first-house')->assertRedirect(route('admin.house', ['building' => 'first-house']));
    $this->actingAs($this->platform)->get('/admin/b/first-house/memberships')->assertRedirect(route('admin.house', ['building' => 'first-house']));
    $this->actingAs($this->platform)->get('/b/first-house')->assertRedirect(route('admin.house', ['building' => 'first-house']));
    $this->actingAs($this->platform)->get('/b/first-house/heating')->assertRedirect(route('admin.house', ['building' => 'first-house']));

    $this->followingRedirects()->actingAs($this->platform)->get('/b/first-house')->assertOk()
        ->assertSee(__('start.house.why.no_home_here.title'));

    // Everybody else keeps the anti-enumeration 404 / the panel 403.
    $stranger = makeUser();
    $this->flushSession();
    $this->actingAs($stranger)->get('/b/first-house')->assertNotFound();
    $this->actingAs($stranger)->get('/admin/b/first-house')->assertForbidden();
});

it('sends a platform_admin with no house link from /home to the "no apartment yet" page with the way on', function (): void {
    $this->actingAs($this->platform)->get('/home')->assertRedirect(route('start'));

    $this->actingAs($this->platform)->get('/start')->assertOk()
        ->assertSee(__('start.no_home.title'))
        ->assertSee(__('start.no_home.join_button'))
        ->assertSee(__('home.tools.administration'))
        ->assertSee('data-start-house="first-house"', false)
        ->assertSee(route('admin.house', ['building' => 'first-house']), false);
});

it('fills the empty platform dashboard with "Sākt šeit": platform checklist and one row per house', function (): void {
    steppedUp($this->platform, 'totp');

    $this->actingAs($this->platform)->get('/admin/platform')->assertOk()
        ->assertSee(__('start.platform.heading'))
        ->assertSee('data-testid="platform-account_notice"', false)
        ->assertSee('data-testid="platform-house-first-house"', false)
        ->assertSee(__('start.house.claim_button'))
        ->assertSee('data-testid="panel-view-switch"', false);
});
