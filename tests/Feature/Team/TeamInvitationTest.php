<?php

declare(strict_types=1);

use App\Auth\StepUpService;
use App\Casts\Bytea;
use App\Enums\BuildingRoleType;
use App\Enums\Locale;
use App\Filament\Manage\Pages\TeamPage;
use App\Models\AuditEntry;
use App\Models\RoleInvitation;
use App\Models\User;
use App\Notifications\TeamInvitation;
use App\Services\Team\TeamInvitationRefused;
use App\Services\Team\TeamInvitationService;
use App\Tenancy\Exceptions\MissingBuildingContext;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

/*
 * Komanda — issuing invitations (ROUND2-SPEC §2.2 item 1, AC-R2-04 first half).
 */

beforeEach(function (): void {
    Notification::fake();
    $this->b = makeBuilding('team-house', 2, 2);
    $this->admin = makeUser('team-admin@example.test');
    grantRole($this->admin, $this->b, BuildingRoleType::BuildingAdmin);
});

function invite(User $actor, string $email, BuildingRoleType $role = BuildingRoleType::SectionElder, array $sections = [1], string $method = 'passkey'): RoleInvitation
{
    test()->actingAs($actor);
    steppedUp($actor, $method);
    // steppedUp() seeds the NEXT request's session; the service reads the current one.
    session()->put(StepUpService::SESSION_KEY, ['user' => $actor->id, 'at' => now()->getTimestamp(), 'seen' => now()->getTimestamp(), 'method' => $method]);
    $ids = array_map(fn (int $n): int => (int) section(test()->b, $n)->id, $sections);

    return inBuilding(test()->b, fn () => app(TeamInvitationService::class)->invite($actor, $email, $role, $role === BuildingRoleType::SectionElder ? $ids : [], 'Lēmums 7', Locale::Ru));
}

it('stores the e-mail only encrypted + HMAC + hint and the token only as its hash, and mails the fragment link', function (): void {
    $invitation = invite($this->admin, 'New.Elder@Example.test');

    $raw = DB::table('role_invitations')->where('id', $invitation->id)->first();
    expect($raw->email_ciphertext)->not->toContain('elder')
        ->and($invitation->email())->toBe('new.elder@example.test')
        ->and($raw->email_hint)->toBe('n•••r@e•••.test')
        ->and(Bytea::decode($raw->email_hmac))->toBe(TeamInvitationService::emailHmac('new.elder@example.test'))
        ->and($invitation->section_ids)->toBe([(int) section($this->b, 1)->id])
        ->and($invitation->expires_at->diffInDays(now(), true))->toBeGreaterThan(6.9);

    $url = null;
    Notification::assertSentOnDemand(TeamInvitation::class, function (TeamInvitation $n, array $channels, object $notifiable) use (&$url): bool {
        $url = $n->url();

        return $notifiable->routes['mail'] === 'new.elder@example.test' && $n->locale === 'ru';
    });

    expect($url)->toStartWith(route('team.accept', ['building' => 'team-house']).'#t=');
    $token = explode('#t=', (string) $url)[1];
    expect(Bytea::decode($raw->token_hash))->toBe(hash('sha256', $token, true));

    $audit = AuditEntry::query()->where('action', 'team.invite.create')->firstOrFail();
    expect(json_encode($audit->data))->not->toContain('example.test')
        ->and($audit->data['role'])->toBe('section_elder')
        ->and($audit->data['invitation_id'])->toBe($invitation->id);
});

it('requires a strong step-up (passkey / TOTP of this session) — a trusted device or e-mail code is not enough', function (string $method): void {
    expect(fn () => invite($this->admin, 'weak@example.test', method: $method))->toThrow(AuthorizationException::class);
    expect(inBuilding($this->b, fn () => RoleInvitation::query()->count()))->toBe(0);
})->with(['trusted', 'email']);

it('lets only a building_admin invite', function (BuildingRoleType $role): void {
    $other = makeUser();
    grantRole($other, $this->b, $role, $role === BuildingRoleType::SectionElder ? 1 : null);

    expect(fn () => invite($other, 'x@example.test', BuildingRoleType::Volunteer, []))->toThrow(AuthorizationException::class);
})->with([BuildingRoleType::SectionElder, BuildingRoleType::ParkingOfficer, BuildingRoleType::Volunteer]);

it('refuses self, duplicates, roles already held, foreign stairwells and the daily quota', function (): void {
    expect(fn () => invite($this->admin, 'TEAM-admin@example.test', BuildingRoleType::BuildingAdmin, []))
        ->toThrow(TeamInvitationRefused::class, __('admin.team.error.self_invite'));

    invite($this->admin, 'dup@example.test');
    try {
        invite($this->admin, 'DUP@example.test');
        $this->fail('duplicate accepted');
    } catch (TeamInvitationRefused $e) {
        expect($e->reason)->toBe('invite_pending');
    }

    $holder = makeUser('holder@example.test');
    grantRole($holder, $this->b, BuildingRoleType::ParkingOfficer);
    expect(fn () => invite($this->admin, 'holder@example.test', BuildingRoleType::ParkingOfficer, []))
        ->toThrow(TeamInvitationRefused::class, __('admin.team.error.role_already_held'));

    $foreign = makeBuilding('team-other', 1, 1);
    $foreignSection = (int) section($foreign, 1)->id;
    $this->actingAs($this->admin);
    session()->put(StepUpService::SESSION_KEY, ['user' => $this->admin->id, 'at' => now()->getTimestamp(), 'seen' => now()->getTimestamp(), 'method' => 'totp']);
    expect(fn () => inBuilding($this->b, fn () => app(TeamInvitationService::class)->invite($this->admin, 'f@example.test', BuildingRoleType::SectionElder, [$foreignSection])))
        ->toThrow(TeamInvitationRefused::class, __('admin.team.error.invalid_section'));

    for ($i = inBuilding($this->b, fn () => RoleInvitation::query()->count()); $i < TeamInvitationService::DAILY_QUOTA; $i++) {
        invite($this->admin, "q{$i}@example.test", BuildingRoleType::Volunteer, []);
    }
    expect(fn () => invite($this->admin, 'one-too-many@example.test', BuildingRoleType::Volunteer, []))
        ->toThrow(TeamInvitationRefused::class, __('admin.team.error.team_invite_quota'));
});

it('never offers manager_viewer and requires a stairwell for an elder (database CHECKs as backstop)', function (): void {
    expect(fn () => invite($this->admin, 'mv@example.test', BuildingRoleType::ManagerViewer, []))->toThrow(AuthorizationException::class);
    expect(fn () => invite($this->admin, 'no-section@example.test', BuildingRoleType::SectionElder, []))
        ->toThrow(TeamInvitationRefused::class, __('admin.team.error.invalid_section'));
});

it('lets an expired, never-closed invitation be replaced by a new one', function (): void {
    $old = invite($this->admin, 'late@example.test');
    $this->travel(8)->days();

    $new = invite($this->admin, 'late@example.test');

    expect($new->id)->not->toBe($old->id)
        ->and(inBuilding($this->b, fn () => $old->refresh()->revoked_at))->not->toBeNull();
});

it('resends with a new token (old link dead), at most three times; revoke needs mfa and closes it', function (): void {
    $invitation = invite($this->admin, 'again@example.test');
    $firstHash = $invitation->token_hash;

    inBuilding($this->b, fn () => app(TeamInvitationService::class)->resend($this->admin, $invitation));
    expect($invitation->token_hash)->not->toBe($firstHash)->and($invitation->resend_count)->toBe(1);

    inBuilding($this->b, function () use ($invitation): void {
        app(TeamInvitationService::class)->resend($this->admin, $invitation);
        app(TeamInvitationService::class)->resend($this->admin, $invitation);
    });
    expect(fn () => inBuilding($this->b, fn () => app(TeamInvitationService::class)->resend($this->admin, $invitation)))
        ->toThrow(TeamInvitationRefused::class, __('admin.team.error.resend_limit'));

    session()->put(StepUpService::SESSION_KEY, ['user' => $this->admin->id, 'at' => now()->getTimestamp(), 'seen' => now()->getTimestamp(), 'method' => 'trusted']);
    inBuilding($this->b, fn () => app(TeamInvitationService::class)->revoke($this->admin, $invitation));
    expect($invitation->revoked_at)->not->toBeNull()
        ->and(AuditEntry::query()->where('action', 'team.invite.revoke')->exists())->toBeTrue()
        ->and(AuditEntry::query()->where('action', 'team.invite.resend')->count())->toBe(3);
});

it('renders the Komanda page with holders, pending invitations and role help; read-only for an elder', function (): void {
    invite($this->admin, 'pending-one@example.test');
    $elder = makeUser('elder-view@example.test');
    grantRole($elder, $this->b, BuildingRoleType::SectionElder, 2);

    steppedUp($this->admin, 'totp');
    $this->actingAs($this->admin)->get('/admin/b/team-house/team')->assertOk()
        ->assertSee(__('admin.team.title'))
        ->assertSee('pending-one@example.test')
        ->assertSee(__('admin.team.invite'))
        ->assertSee(__('admin.team.role_help.section_elder'))
        ->assertSee(__('admin.team.grant_existing'));

    $this->flushSession();
    steppedUp($elder, 'email');
    $this->actingAs($elder)->get('/admin/b/team-house/team')->assertOk()
        ->assertSee('p•••e@e•••.test')
        ->assertDontSee('pending-one@example.test')
        ->assertDontSee(__('admin.team.grant_existing'));

    // BuildingRoleResource left the navigation (reached from Komanda only).
    $this->flushSession();
    steppedUp($this->admin, 'totp');
    $this->actingAs($this->admin)->get('/admin/b/team-house')->assertOk()->assertDontSee('/admin/b/team-house/building-roles"', false);
});

it('invites through the page action and asks for the strong step-up first', function (): void {
    $this->actingAs($this->admin);
    session()->put(StepUpService::SESSION_KEY, ['user' => $this->admin->id, 'at' => now()->getTimestamp(), 'seen' => now()->getTimestamp(), 'method' => 'trusted']);

    inBuilding($this->b, fn () => Livewire::test(TeamPage::class)
        ->callAction('invite', ['email' => 'via-page@example.test', 'role' => 'volunteer', 'locale' => 'lv'])
        ->assertRedirect(route('step-up')));
    expect(inBuilding($this->b, fn () => RoleInvitation::query()->count()))->toBe(0);

    session()->put(StepUpService::SESSION_KEY, ['user' => $this->admin->id, 'at' => now()->getTimestamp(), 'seen' => now()->getTimestamp(), 'method' => 'passkey']);
    inBuilding($this->b, fn () => Livewire::test(TeamPage::class)
        ->callAction('invite', ['email' => 'via-page@example.test', 'role' => 'volunteer', 'locale' => 'lv'])
        ->assertHasNoActionErrors()
        ->assertNotified(__('admin.team.invited')));
    expect(inBuilding($this->b, fn () => RoleInvitation::query()->count()))->toBe(1);
});

it('keeps invitations tenant-scoped: fail-closed without context, invisible and unactionable from another building', function (): void {
    $invitation = invite($this->admin, 'scoped@example.test', BuildingRoleType::Volunteer, []);
    $other = makeBuilding('team-other-house', 1, 1);
    $adminB = makeUser('team-admin-b@example.test');
    grantRole($adminB, $other, BuildingRoleType::BuildingAdmin);

    expect(fn () => RoleInvitation::query()->count())->toThrow(MissingBuildingContext::class)
        ->and(inBuilding($other, fn () => RoleInvitation::query()->whereKey($invitation->id)->exists()))->toBeFalse();

    $this->actingAs($adminB);
    session()->put(StepUpService::SESSION_KEY, ['user' => $adminB->id, 'at' => now()->getTimestamp(), 'seen' => now()->getTimestamp(), 'method' => 'passkey']);
    inBuilding($other, fn () => Livewire::test(TeamPage::class)->callAction('revokeInvite', arguments: ['id' => $invitation->id])->assertNotFound());
    expect(inBuilding($this->b, fn () => $invitation->refresh()->revoked_at))->toBeNull();
});

it('counts the daily quota from midnight in Riga, also in the first hours after it (UTC offset)', function (): void {
    // 00:30 in Riga is still "yesterday" in UTC: the quota must count today's invitations anyway.
    $this->travelTo(CarbonImmutable::parse('2026-10-22 00:30:00', 'Europe/Riga'));

    for ($i = 0; $i < TeamInvitationService::DAILY_QUOTA; $i++) {
        invite($this->admin, "night{$i}@example.test", BuildingRoleType::Volunteer, []);
    }

    expect(fn () => invite($this->admin, 'night-extra@example.test', BuildingRoleType::Volunteer, []))
        ->toThrow(TeamInvitationRefused::class, __('admin.team.error.team_invite_quota'));
});
