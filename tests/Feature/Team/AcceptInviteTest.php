<?php

declare(strict_types=1);

use App\Audit\AuditChainVerifier;
use App\Auth\StepUpService;
use App\Enums\BuildingRoleType;
use App\Enums\Locale;
use App\Gdpr\CoreGate;
use App\Livewire\Me\PasskeyOffer;
use App\Livewire\Team\AcceptInvite;
use App\Models\AuditEntry;
use App\Models\BuildingRole;
use App\Models\RoleInvitation;
use App\Models\User;
use App\Notifications\TeamInvitation;
use App\Notifications\TeamInviteCode;
use App\Notifications\TeamMemberJoined;
use App\Services\Team\TeamInvitationService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

/*
 * Accepting a team invitation (ROUND2-SPEC §2.2 item 2, AC-R2-04 / AC-R2-05).
 */

beforeEach(function (): void {
    Notification::fake();
    RateLimiter::clear('team-invite:v4:127.0.0.1');
    $this->b = makeBuilding('accept-house', 5, 2);
    $this->admin = makeUser('accept-admin@example.test');
    grantRole($this->admin, $this->b, BuildingRoleType::BuildingAdmin);
    $this->admin2 = makeUser('accept-admin2@example.test');
    grantRole($this->admin2, $this->b, BuildingRoleType::BuildingAdmin);
});

/**
 * Issue an invitation as the admin (strong step-up) and return the plaintext token from the mail.
 *
 * @param  list<int>  $sections  stairwell numbers
 */
function issueInvite(string $email, BuildingRoleType $role = BuildingRoleType::SectionElder, array $sections = [5], ?object $building = null): string
{
    $building ??= test()->b;
    $admin = test()->admin;
    test()->actingAs($admin);
    session()->put(StepUpService::SESSION_KEY, ['user' => $admin->id, 'at' => now()->getTimestamp(), 'seen' => now()->getTimestamp(), 'method' => 'passkey']);
    $ids = array_map(fn (int $n): int => (int) section($building, $n)->id, $sections);

    $invitation = inBuilding($building, fn () => app(TeamInvitationService::class)->invite($admin, $email, $role, $role === BuildingRoleType::SectionElder ? $ids : [], null, Locale::Lv));

    $token = null;
    Notification::assertSentOnDemand(TeamInvitation::class, function (TeamInvitation $n) use (&$token, $invitation): bool {
        $candidate = explode('#t=', $n->url())[1];
        if (hash_equals((string) $invitation->token_hash, hash('sha256', $candidate, true))) {
            $token = $candidate;
        }

        return true;
    });

    // Back to a clean, signed-out device for the invitee.
    Auth::logout();
    session()->flush();

    return (string) $token;
}

function acceptPage(): Testable
{
    return Livewire::test(AcceptInvite::class);
}

function lastInviteCode(string $email): string
{
    $code = null;
    Notification::assertSentOnDemand(TeamInviteCode::class, function (TeamInviteCode $n, array $channels, object $notifiable) use (&$code, $email): bool {
        if ($notifiable->routes['mail'] === $email) {
            $code = $n->code();
        }

        return true;
    });

    return (string) $code;
}

it('lets a person WITHOUT an account confirm the address, create it (18+, notice) and hold exactly the invited role', function (): void {
    $token = issueInvite('new-elder@example.test');

    inBuilding($this->b, function () use ($token): void {
        $page = acceptPage()->call('open', $token)
            ->assertSet('state', 'guest')
            ->assertSee('n•••r@e•••.test')
            ->assertSee(__('enum.building_role.section_elder'))
            ->assertSee(__('admin.section.short', ['n' => 5]));
        expect(json_encode($page->snapshot))->not->toContain($token);

        $page->call('sendCode')->assertSet('state', 'code');
        $page->set('code', '000000')->call('verify')->assertSet('state', 'code')->assertSee(__('auth.email.code_invalid'));
        $page->set('code', lastInviteCode('new-elder@example.test'))->call('verify')->assertSet('state', 'confirm_new');
        expect(User::query()->where('email', 'new-elder@example.test')->exists())->toBeFalse();

        $page->call('createAccount')->assertHasErrors(['adult', 'notice']);
        $page->set('adult', true)->set('notice', true)->call('createAccount')
            ->assertRedirect(route('team.accept', ['building' => 'accept-house'], absolute: false));
    });

    $user = User::query()->where('email', 'new-elder@example.test')->firstOrFail();
    expect(Auth::id())->toBe($user->id)
        ->and($user->email_verified_at)->not->toBeNull()
        ->and($user->is_adult_confirmed_at)->not->toBeNull();

    inBuilding($this->b, fn () => acceptPage()->assertSet('state', 'ready')
        ->call('accept')
        ->assertRedirect(route('me.passkey')));
    expect(session(PasskeyOffer::NEXT))->toBe('/admin');

    $roles = inBuilding($this->b, fn () => BuildingRole::query()->current()->where('user_id', $user->id)->get());
    expect($roles)->toHaveCount(1)
        ->and($roles[0]->role)->toBe(BuildingRoleType::SectionElder)
        ->and((int) $roles[0]->section_id)->toBe((int) section($this->b, 5)->id)
        ->and((int) $roles[0]->granted_by)->toBe((int) $this->admin->id);

    Notification::assertSentTo([$this->admin, $this->admin2], TeamMemberJoined::class);
    Notification::assertNotSentTo($user, TeamMemberJoined::class);

    $accept = AuditEntry::query()->where('action', 'team.invite.accept')->firstOrFail();
    expect($accept->subject_user_ids)->toBe([(int) $user->id])
        ->and(app(AuditChainVerifier::class)->verify((int) $this->b->id)->valid)->toBeTrue();

    // Used: the same link is now just "no longer valid".
    Auth::logout();
    session()->flush();
    inBuilding($this->b, fn () => acceptPage()->call('open', $token)->assertSet('state', 'invalid')->assertSee(__('team.accept.invalid')));
});

it('signs an EXISTING account in with the code and grants the role (no second account)', function (): void {
    $existing = makeUser('known-officer@example.test');
    $token = issueInvite('known-officer@example.test', BuildingRoleType::ParkingOfficer, []);

    inBuilding($this->b, function () use ($token): void {
        $page = acceptPage()->call('open', $token)->call('sendCode');
        $page->set('code', lastInviteCode('known-officer@example.test'))->call('verify')
            ->assertRedirect(route('team.accept', ['building' => 'accept-house'], absolute: false));
    });

    expect(Auth::id())->toBe($existing->id)
        ->and(User::query()->where('email', 'known-officer@example.test')->count())->toBe(1);

    inBuilding($this->b, fn () => acceptPage()->call('accept'));
    expect(inBuilding($this->b, fn () => $existing->hasRoleIn($this->b, BuildingRoleType::ParkingOfficer)))->toBeTrue();
});

it('never accepts for a signed-in person with another e-mail', function (): void {
    $token = issueInvite('right@example.test');
    $other = makeUser('wrong@example.test');
    $this->actingAs($other);

    inBuilding($this->b, function () use ($token): void {
        acceptPage()->call('open', $token)->assertSet('state', 'wrong_email')
            ->assertSee(__('team.accept.wrong_email', ['email' => 'r•••t@e•••.test']))
            ->call('accept')->assertSet('state', 'wrong_email');
    });

    expect(inBuilding($this->b, fn () => BuildingRole::query()->where('user_id', $other->id)->exists()))->toBeFalse()
        ->and(inBuilding($this->b, fn () => RoleInvitation::query()->firstOrFail()->accepted_at))->toBeNull();
});

it('answers the same neutral text for revoked, resent (old link), expired and other-building tokens', function (string $case): void {
    $other = makeBuilding('accept-other', 1, 1);
    $token = issueInvite('neutral@example.test', BuildingRoleType::Volunteer, []);
    $invitation = inBuilding($this->b, fn () => RoleInvitation::query()->firstOrFail());
    $this->actingAs($this->admin);
    session()->put(StepUpService::SESSION_KEY, ['user' => $this->admin->id, 'at' => now()->getTimestamp(), 'seen' => now()->getTimestamp(), 'method' => 'passkey']);

    $slugBuilding = $this->b;
    match ($case) {
        'revoked' => inBuilding($this->b, fn () => app(TeamInvitationService::class)->revoke($this->admin, $invitation)),
        'resent' => inBuilding($this->b, fn () => app(TeamInvitationService::class)->resend($this->admin, $invitation)),
        'expired' => $this->travelTo(now()->addDays(7)->addMinute()),
        'other_building' => $slugBuilding = $other,
        'garbage' => $token = 'not-a-token',
    };
    Auth::logout();
    session()->flush();

    $html = inBuilding($slugBuilding, fn () => acceptPage()->call('open', $token)->assertSet('state', 'invalid')->html());
    expect($html)->toContain(e(__('team.accept.invalid')))
        ->and($html)->not->toContain(__('enum.building_role.volunteer'))
        ->and($html)->not->toContain('n•••l@');
})->with(['revoked', 'resent', 'expired', 'other_building', 'garbage']);

it('throttles a device after 10 wrong tokens, even for a correct one', function (): void {
    $token = issueInvite('throttle@example.test', BuildingRoleType::Volunteer, []);

    inBuilding($this->b, function () use ($token): void {
        for ($i = 0; $i < 10; $i++) {
            acceptPage()->call('open', 'wrong-'.$i);
        }
        acceptPage()->call('open', $token)->assertSet('state', 'invalid')->assertSet('cooldown', true)->assertSee(__('team.accept.cooldown'));
    });
});

it('serves the accept page with a strict CSP, no-store and no secret in the URL', function (): void {
    $this->get('/team-invite/accept-house')->assertOk()
        ->assertSee(__('team.accept.title'))
        ->assertHeader('Cache-Control', 'no-store, private');
    $this->get('/team-invite/accept-house?t=abc')->assertRedirect('/team-invite/accept-house');
    $this->get('/team-invite/no-such-house')->assertNotFound();

    $csp = (string) $this->get('/team-invite/accept-house')->headers->get('Content-Security-Policy');
    expect($csp)->not->toContain('unsafe-eval');
});

it('works below gate L-core (a house is opened by its team, D5)', function (): void {
    expect(inBuilding($this->b, fn () => CoreGate::status($this->b)->open))->toBeFalse();

    $token = issueInvite('closed-house@example.test', BuildingRoleType::BuildingAdmin, []);
    inBuilding($this->b, fn () => acceptPage()->call('open', $token)->assertSet('state', 'guest'));
});
