<?php

declare(strict_types=1);

use App\Auth\StepUpService;
use App\Enums\BuildingRoleType;
use App\Mcp\McpAccess;
use App\Models\Building;
use App\Models\BuildingRole;
use App\Models\McpToken;
use App\Platform\PlatformBuildingContext;
use App\Platform\PlatformPurpose;
use App\Services\Memberships\BuildingRoleService;
use Illuminate\Auth\Access\AuthorizationException;

/*
 * Scopes (round 3, ADR 0008): read tokens read; write tools need a write token AND confirm=true; the write elevation
 * exists only inside a confirmed write tool and only for the token's own user.
 */

beforeEach(function (): void {
    $this->b = makeBuilding('mcp-scope-house', 2, 2);
    $this->admin = mcpAdmin();
    $this->read = mcpToken($this->admin, ['read']);
    $this->write = mcpToken($this->admin, ['read', 'write']);
});

it('runs read tools with a read token', function (): void {
    $result = mcpTool($this->read, 'building_detail', ['building' => 'mcp-scope-house']);

    expect($result['isError'])->toBeFalse()
        ->and($result['data']['structure']['apartments'])->toBe(16)
        ->and(count($result['data']['structure']['sections']))->toBe(2);
});

it('refuses every write tool with a read token', function (string $tool, array $args): void {
    $result = mcpTool($this->read, $tool, ['building' => 'mcp-scope-house', 'confirm' => true] + $args);

    expect($result['isError'])->toBeTrue()
        ->and($result['text'])->toContain('"write" scope');
})->with([
    'grant_role' => ['grant_role', ['user_id' => 1, 'role' => 'building_admin']],
    'revoke_role' => ['revoke_role', ['role_id' => 1]],
    'invite_team_member' => ['invite_team_member', ['email' => 'x@example.test', 'role' => 'building_admin']],
    'resend_invitation' => ['resend_invitation', ['invitation_id' => str_repeat('a', 26)]],
    'set_building_setting' => ['set_building_setting', ['key' => 'modules.heating', 'value' => 'true']],
    'generate_building_from_series' => ['generate_building_from_series', ['sections' => 1, 'floors' => 2, 'dry_run' => false]],
    'run_recompute' => ['run_recompute', []],
]);

it('refuses a write without confirm=true and changes nothing', function (mixed $confirm): void {
    $args = ['building' => 'mcp-scope-house', 'user_id' => $this->admin->id, 'role' => 'building_admin'];
    if ($confirm !== 'absent') {
        $args['confirm'] = $confirm;
    }

    $result = mcpTool($this->write, 'grant_role', $args);

    expect($result['isError'])->toBeTrue()
        ->and($result['text'])->toContain('"confirm": true')
        ->and(inBuilding($this->b, fn () => BuildingRole::query()->count()))->toBe(0);
})->with(['absent' => ['absent'], 'false' => [false], 'string "true"' => ['true'], 'one' => [1]]);

it('writes with a write token and confirm=true', function (): void {
    $result = mcpTool($this->write, 'grant_role', ['building' => 'mcp-scope-house', 'user_id' => $this->admin->id, 'role' => 'building_admin', 'confirm' => true]);

    expect($result['isError'])->toBeFalse($result['text'])
        ->and($result['data']['role'])->toBe('building_admin')
        ->and(inBuilding($this->b, fn () => BuildingRole::query()->current()->where('user_id', $this->admin->id)->where('role', 'building_admin')->exists()))->toBeTrue();
});

it('lets a dry run of the generator run with a write token without confirm', function (): void {
    $empty = Building::query()->create(['slug' => 'mcp-empty', 'name' => 'Tukšā iela 1', 'address_line' => 'Tukšā iela 1', 'city' => 'Rīga', 'series_id' => series602()->id, 'floors' => 9]);

    $result = mcpTool($this->write, 'generate_building_from_series', ['building' => $empty->slug, 'sections' => 1, 'floors' => 2]);

    expect($result['isError'])->toBeFalse($result['text'])->and($result['data']['dry_run'])->toBeTrue();
});

it('elevates the step-up only inside a confirmed write tool, for the token owner only', function (): void {
    $access = app(McpAccess::class);
    $stepUp = app(StepUpService::class);
    $other = mcpAdmin('other-mcp@example.test');
    $token = McpToken::query()->findOrFail(mcpTokenId($this->write));

    $access->authenticate($token, $this->admin);

    expect($stepUp->hasFreshStrongMfa($this->admin))->toBeFalse()
        ->and($stepUp->hasFreshMfa($this->admin))->toBeFalse();

    $access->elevated(function () use ($stepUp, $other): void {
        expect($stepUp->hasFreshStrongMfa($this->admin))->toBeTrue()
            ->and($stepUp->hasFreshMfa($this->admin))->toBeTrue()
            ->and($stepUp->hasFreshStrongMfa($other))->toBeFalse();
    });

    expect($stepUp->hasFreshStrongMfa($this->admin))->toBeFalse();
});

it('never elevates a read token', function (): void {
    $access = app(McpAccess::class);
    $access->authenticate(McpToken::query()->findOrFail(mcpTokenId($this->read)), $this->admin);

    expect(fn () => $access->elevated(fn () => true))->toThrow(LogicException::class)
        ->and(app(StepUpService::class)->hasFreshStrongMfa($this->admin))->toBeFalse();
});

it('keeps the MCP purposes out of reach of a session and the write purposes out of reach of a read token', function (): void {
    $this->actingAs($this->admin);
    app(StepUpService::class)->markFresh($this->admin, 'totp');

    // A session with a fresh step-up still cannot use the MCP purposes.
    expect(fn () => PlatformBuildingContext::run($this->b, PlatformPurpose::McpRead, fn () => true))->toThrow(AuthorizationException::class);

    app(StepUpService::class)->forget();
    app(McpAccess::class)->authenticate(McpToken::query()->findOrFail(mcpTokenId($this->read)), $this->admin);

    expect(PlatformBuildingContext::run($this->b, PlatformPurpose::McpRead, fn () => 'ok'))->toBe('ok')
        ->and(fn () => PlatformBuildingContext::run($this->b, PlatformPurpose::McpWrite, fn () => true))->toThrow(AuthorizationException::class)
        ->and(fn () => PlatformBuildingContext::run($this->b, PlatformPurpose::Generate, fn () => true))->toThrow(AuthorizationException::class)
        // Not an MCP-level purpose: still needs the session step-up.
        ->and(fn () => PlatformBuildingContext::run($this->b, PlatformPurpose::DpaRecord, fn () => true))->toThrow(AuthorizationException::class);
});

it('grants roles to someone else only through the elevation (the strong backstop stays for sessions)', function (): void {
    $elder = makeUser('mcp-elder@example.test');

    $result = mcpTool($this->write, 'grant_role', ['building' => 'mcp-scope-house', 'user_id' => $elder->id, 'role' => 'section_elder', 'section' => 2, 'confirm' => true]);

    expect($result['isError'])->toBeFalse($result['text'])
        ->and($result['data']['section'])->toBe(2)
        ->and(inBuilding($this->b, fn () => BuildingRole::query()->where('user_id', $elder->id)->value('role')))->toBe(BuildingRoleType::SectionElder);

    // The same service call from a session without a strong step-up is still refused.
    $this->actingAs($this->admin);
    expect(fn () => inBuilding($this->b, fn () => app(BuildingRoleService::class)
        ->grant($this->admin, makeUser('mcp-x@example.test'), BuildingRoleType::Volunteer)))->toThrow(AuthorizationException::class);
});
