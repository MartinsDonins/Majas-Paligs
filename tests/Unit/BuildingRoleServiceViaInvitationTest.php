<?php

declare(strict_types=1);

use App\Auth\StepUpService;
use App\Casts\Bytea;
use App\Enums\BuildingRoleType;
use App\Enums\Locale;
use App\Models\RoleInvitation;
use App\Services\Memberships\BuildingRoleService;
use App\Services\Team\TeamInvitationService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Notification;

/*
 * BuildingRoleService::grant() skips the inviter's strong check ONLY for a valid invitation (ROUND2-SPEC §2.2 item 2):
 * open, unexpired, same building, issued by that actor, for that role and stairwell, with the current token.
 */

beforeEach(function (): void {
    Notification::fake();
    $this->b = makeBuilding('via-house', 2, 1);
    $this->admin = makeUser('via-admin@example.test');
    grantRole($this->admin, $this->b, BuildingRoleType::BuildingAdmin);
    $this->invitee = makeUser('via-invitee@example.test');

    $this->actingAs($this->admin);
    session()->put(StepUpService::SESSION_KEY, ['user' => $this->admin->id, 'at' => now()->getTimestamp(), 'seen' => now()->getTimestamp(), 'method' => 'passkey']);
    $this->invitation = inBuilding($this->b, fn () => app(TeamInvitationService::class)->invite(
        $this->admin, 'via-invitee@example.test', BuildingRoleType::SectionElder, [(int) section($this->b, 1)->id], null, Locale::Lv,
    ));

    // From here on the session is the INVITEE's: the inviter has no fresh step-up in it.
    session()->forget(StepUpService::SESSION_KEY);
    $this->actingAs($this->invitee);
});

function grantVia(?RoleInvitation $via, ?int $sectionNumber = 1, BuildingRoleType $role = BuildingRoleType::SectionElder, $actor = null)
{
    $actor ??= test()->admin;

    return inBuilding(test()->b, fn () => app(BuildingRoleService::class)->grant(
        $actor, test()->invitee, $role, $sectionNumber !== null ? (int) section(test()->b, $sectionNumber)->id : null, null, $via,
    ));
}

it('grants with the valid invitation although the inviter has no fresh step-up here', function (): void {
    expect(grantVia($this->invitation)->role)->toBe(BuildingRoleType::SectionElder);
});

it('refuses without an invitation', function (): void {
    expect(fn () => grantVia(null))->toThrow(AuthorizationException::class);
});

it('refuses a revoked, expired, accepted or resent (stale token) invitation', function (string $case): void {
    $inv = $this->invitation;
    inBuilding($this->b, function () use ($case, $inv): void {
        match ($case) {
            'revoked' => $inv->forceFill(['revoked_at' => now()])->save(),
            'accepted' => $inv->forceFill(['accepted_at' => now(), 'accepted_user_id' => $this->invitee->id])->save(),
            'expired' => $inv->forceFill(['expires_at' => now()->subMinute()])->save(),
            'stale' => RoleInvitation::query()->whereKey($inv->id)->update(['token_hash' => Bytea::literal(random_bytes(32))]),
        };
    });
    // The in-memory copy still looks valid for 'stale': the service re-reads the row.
    if ($case !== 'stale') {
        $inv = inBuilding($this->b, fn () => $inv->refresh());
    }

    expect(fn () => grantVia($inv))->toThrow(AuthorizationException::class);
})->with(['revoked', 'expired', 'accepted', 'stale']);

it('refuses another role, another stairwell or another actor than the invitation says', function (): void {
    expect(fn () => grantVia($this->invitation, 2))->toThrow(AuthorizationException::class)
        ->and(fn () => grantVia($this->invitation, null, BuildingRoleType::BuildingAdmin))->toThrow(AuthorizationException::class);

    $otherAdmin = makeUser('via-other@example.test');
    grantRole($otherAdmin, $this->b, BuildingRoleType::BuildingAdmin);
    expect(fn () => grantVia($this->invitation, 1, BuildingRoleType::SectionElder, $otherAdmin))->toThrow(AuthorizationException::class);
});

it('refuses an invitation of another building', function (): void {
    $other = makeBuilding('via-other-house', 1, 1);
    grantRole($this->admin, $other, BuildingRoleType::BuildingAdmin);

    expect(fn () => inBuilding($other, fn () => app(BuildingRoleService::class)->grant(
        $this->admin, $this->invitee, BuildingRoleType::SectionElder, (int) section($other, 1)->id, null, $this->invitation,
    )))->toThrow(AuthorizationException::class);
});
