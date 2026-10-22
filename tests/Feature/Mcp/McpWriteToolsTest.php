<?php

declare(strict_types=1);

use App\Enums\BuildingRoleType;
use App\Models\Apartment;
use App\Models\Building;
use App\Models\BuildingRole;
use App\Models\RiserStatus;
use App\Models\RoleInvitation;
use App\Models\Section;
use App\Notifications\TeamInvitation;
use Illuminate\Support\Facades\Notification;

/*
 * The MCP write tools (round 3): what the owner needs on day one — make himself building_admin of Pūces 51, invite
 * the team, switch modules, generate a house, recompute heating. Every write needs a write token + confirm=true.
 */

beforeEach(function (): void {
    Notification::fake();
    $this->b = makeBuilding('mcp-write-house', 2, 2);
    $this->admin = mcpAdmin();
    $this->token = mcpToken($this->admin, ['read', 'write']);
});

function writeArgs(array $args): array
{
    return ['building' => 'mcp-write-house', 'confirm' => true] + $args;
}

it('makes the platform admin building_admin of a house and re-confirms on a second grant', function (): void {
    $first = mcpTool($this->token, 'grant_role', writeArgs(['user_id' => $this->admin->id, 'role' => 'building_admin']));
    $second = mcpTool($this->token, 'grant_role', writeArgs(['user_id' => $this->admin->id, 'role' => 'building_admin']));

    expect($first['isError'])->toBeFalse($first['text'])
        ->and($first['data']['reconfirmed'])->toBeFalse()
        ->and($second['data']['reconfirmed'])->toBeTrue()
        ->and($second['data']['role_id'])->toBe($first['data']['role_id'])
        ->and(inBuilding($this->b, fn () => BuildingRole::query()->current()->count()))->toBe(1);
});

it('refuses manager_viewer, unknown users and an elder without a stairwell', function (?string $role, string $needle): void {
    $args = $role === null
        ? ['user_id' => 999999, 'role' => 'volunteer']
        : ['user_id' => $this->admin->id, 'role' => $role];

    $result = mcpTool($this->token, 'grant_role', writeArgs($args));

    // Validation messages come in the app language (Latvian), like everywhere else in the admin.
    expect($result['isError'])->toBeTrue()->and($result['text'])->toContain($needle)
        ->and(inBuilding($this->b, fn () => BuildingRole::query()->count()))->toBe(0);
})->with([
    'manager_viewer' => ['manager_viewer', 'loma'],
    'unknown user' => [null, 'Unknown or deleted account'],
    'elder without section' => ['section_elder', 'section_elder needs'],
]);

it('revokes a role but keeps the last building_admin unless the platform override is set', function (): void {
    $other = makeUser('mcp-other-admin@example.test');
    $role = grantRole($other, $this->b, BuildingRoleType::BuildingAdmin);

    $kept = mcpTool($this->token, 'revoke_role', writeArgs(['role_id' => $role->id]));
    expect($kept['isError'])->toBeTrue();

    $revoked = mcpTool($this->token, 'revoke_role', writeArgs(['role_id' => $role->id, 'allow_last_admin' => true]));
    expect($revoked['isError'])->toBeFalse($revoked['text'])
        ->and($revoked['data']['revoked_at'])->not->toBeNull()
        ->and(inBuilding($this->b, fn () => BuildingRole::query()->current()->count()))->toBe(0);
});

it('invites the first building_admin (platform bootstrap) and resends the invitation', function (): void {
    $result = mcpTool($this->token, 'invite_team_member', writeArgs(['email' => 'new.house.admin@example.test', 'role' => 'building_admin', 'locale' => 'ru']));

    expect($result['isError'])->toBeFalse($result['text']);
    Notification::assertSentOnDemand(TeamInvitation::class);

    $resent = mcpTool($this->token, 'resend_invitation', writeArgs(['invitation_id' => $result['data']['invitation_id']]));

    expect($resent['isError'])->toBeFalse($resent['text'])
        ->and($resent['data']['resend_count'])->toBe(1)
        ->and(inBuilding($this->b, fn () => RoleInvitation::query()->first()->locale->value))->toBe('ru');
});

it('invites other roles only once the platform admin holds building_admin in that house', function (): void {
    $before = mcpTool($this->token, 'invite_team_member', writeArgs(['email' => 'elder.to.be@example.test', 'role' => 'section_elder', 'sections' => [1]]));
    expect($before['isError'])->toBeTrue()->and($before['text'])->toContain('Not allowed');

    mcpTool($this->token, 'grant_role', writeArgs(['user_id' => $this->admin->id, 'role' => 'building_admin']));
    $after = mcpTool($this->token, 'invite_team_member', writeArgs(['email' => 'elder.to.be@example.test', 'role' => 'section_elder', 'sections' => [1]]));

    expect($after['isError'])->toBeFalse($after['text'])->and($after['data']['sections'])->toBe([1]);
});

it('sets only whitelisted settings', function (): void {
    $on = mcpTool($this->token, 'set_building_setting', writeArgs(['key' => 'modules.heating', 'value' => 'true']));
    $days = mcpTool($this->token, 'set_building_setting', writeArgs(['key' => 'adhoc_report_valid_days', 'value' => 10]));
    $locale = mcpTool($this->token, 'set_building_setting', writeArgs(['key' => 'default_locale', 'value' => 'ru']));
    $gate = mcpTool($this->token, 'set_building_setting', writeArgs(['key' => 'dpa_scope', 'value' => 'parking']));
    $sharing = mcpTool($this->token, 'set_building_setting', writeArgs(['key' => 'share_with_manager.apartment_numbers', 'value' => 'true']));

    $b = $this->b->refresh();

    expect($on['data'])->toMatchArray(['before' => false, 'after' => true, 'changed' => true])
        ->and($days['data']['after'])->toBe(10)
        ->and($locale['data']['after'])->toBe('ru')
        ->and($gate['isError'])->toBeTrue()
        ->and($sharing['isError'])->toBeTrue()
        ->and($b->settings->moduleEnabled('heating'))->toBeTrue()
        ->and($b->settings->adhocReportValidDays)->toBe(10)
        ->and($b->default_locale)->toBe('ru')
        ->and($b->settings->dpaScope)->toBe([])
        ->and($b->settings->shareWithManager['apartment_numbers'])->toBeFalse();
});

it('generates a house only after a dry run with the same plan', function (): void {
    $empty = Building::query()->create(['slug' => 'mcp-new-house', 'name' => 'Jaunā iela 3', 'address_line' => 'Jaunā iela 3', 'city' => 'Rīga', 'floors' => 9]);
    $args = ['building' => 'mcp-new-house', 'series' => '602', 'sections' => 2, 'floors' => 9, 'layout' => '2-2-3-3'];

    $dry = mcpTool($this->token, 'generate_building_from_series', $args);
    expect($dry['isError'])->toBeFalse($dry['text'])
        ->and($dry['data']['apartments'])->toBe(72)
        ->and($dry['data']['sections'][1]['first_apartment'])->toBe(37)
        ->and($dry['data']['conflicts'])->toBe([])
        ->and(inBuilding($empty, fn () => Section::query()->count()))->toBe(0);

    $noPlan = mcpTool($this->token, 'generate_building_from_series', $args + ['dry_run' => false, 'confirm' => true]);
    $otherPlan = mcpTool($this->token, 'generate_building_from_series', ['floors' => 5] + $args + ['dry_run' => false, 'confirm' => true, 'plan' => $dry['data']['plan']]);
    $noConfirm = mcpTool($this->token, 'generate_building_from_series', $args + ['dry_run' => false, 'plan' => $dry['data']['plan']]);
    expect($noPlan['isError'])->toBeTrue()->and($noPlan['text'])->toContain('dry run first')
        ->and($otherPlan['isError'])->toBeTrue()
        ->and($noConfirm['isError'])->toBeTrue()
        ->and(inBuilding($empty, fn () => Section::query()->count()))->toBe(0);

    $real = mcpTool($this->token, 'generate_building_from_series', $args + ['dry_run' => false, 'confirm' => true, 'plan' => $dry['data']['plan']]);

    expect($real['isError'])->toBeFalse($real['text'])
        ->and($real['data']['created']['apartments'])->toBe(72)
        ->and(inBuilding($empty, fn () => Apartment::query()->count()))->toBe(72)
        ->and($empty->refresh()->series_id)->toBe(series602()->id);

    // Same input again: idempotent, nothing new.
    $again = mcpTool($this->token, 'generate_building_from_series', $args + ['dry_run' => false, 'confirm' => true, 'plan' => $dry['data']['plan']]);
    expect(array_sum($again['data']['created']))->toBe(0);
});

it('refuses a generator input that conflicts with stored stairwells', function (): void {
    $dry = mcpTool($this->token, 'generate_building_from_series', ['building' => 'mcp-write-house', 'sections' => 2, 'floors' => 5]);

    expect($dry['data']['conflicts'])->not->toBe([])->and($dry['data']['plan'])->toBeNull();
});

it('recomputes heating on request and refuses while the module is off', function (): void {
    $off = mcpTool($this->token, 'run_recompute', writeArgs([]));
    expect($off['isError'])->toBeTrue()->and($off['text'])->toContain('heating module is off');

    $house = heatingHouse('mcp-heat-house', 1, 3);
    residentReports($house, 1, 'cold');
    inBuilding($house, fn () => RiserStatus::query()->delete());

    $result = mcpTool($this->token, 'run_recompute', ['building' => 'mcp-heat-house', 'confirm' => true]);

    expect($result['isError'])->toBeFalse($result['text'])
        ->and($result['data']['rolling_rows'])->toBeGreaterThan(0);

    $summary = mcpTool($this->token, 'heating_summary', ['building' => 'mcp-heat-house']);
    expect($summary['data']['reports']['apartments_reporting'])->toBe(1)
        ->and($summary['data']['sections'][0]['risers'])->toBeGreaterThan(0);
});
