<?php

declare(strict_types=1);

use App\Auth\SessionEpoch;
use App\Mcp\Http\AuthenticateMcpToken;
use App\Models\McpToken;
use Illuminate\Support\Str;

/*
 * MCP server authentication (round 3, docs/MCP.md, ADR 0008): bearer tokens of a platform_admin only, one neutral 401
 * for every bad token, no session, failure throttle.
 */

beforeEach(function (): void {
    $this->admin = mcpAdmin();
    $this->token = mcpToken($this->admin);
});

it('lists the Kaimiņos tools for a valid token', function (): void {
    $response = mcpRpc($this->token, 'tools/list')->assertOk();

    $names = collect($response->json('result.tools'))->pluck('name')->sort()->values()->all();

    expect($names)->toBe([
        'app_status', 'audit_search', 'building_detail', 'buildings_list', 'generate_building_from_series', 'grant_role',
        'heating_summary', 'imports_list', 'invitations_list', 'invite_team_member', 'parking_summary', 'resend_invitation',
        'revoke_role', 'run_recompute', 'set_building_setting', 'templates_status', 'users_and_roles',
    ]);
});

it('answers initialize for a 2025-11-25 client (Claude Code) with the server name and version', function (): void {
    mcpRpc($this->token, 'initialize', ['protocolVersion' => '2025-11-25', 'capabilities' => (object) [], 'clientInfo' => ['name' => 'claude-code', 'version' => '2']])
        ->assertOk()
        ->assertJsonPath('result.protocolVersion', '2025-11-25')
        ->assertJsonPath('result.serverInfo.name', 'Kaimiņos')
        ->assertJsonPath('result.serverInfo.version', (string) config('app.version'));
});

it('refuses a missing, malformed, unknown or wrong token with the same 401', function (?string $mutate): void {
    $token = match ($mutate) {
        null => null,
        'malformed' => 'not-a-token',
        'unknown' => 'kmcp_'.strtolower((string) Str::ulid()).'_'.str_repeat('A', 43),
        'wrong_secret' => substr($this->token, 0, -4).'AAAA',
        default => throw new LogicException,
    };

    $response = mcpRpc($token, 'tools/list')->assertStatus(401);

    expect($response->json('error.message'))->toBe('Unauthenticated: a valid Kaimiņos MCP token is required.')
        ->and($response->headers->get('WWW-Authenticate'))->toContain('Bearer realm="mcp"')
        ->and($response->headers->get('Cache-Control'))->toContain('no-store');
})->with(['missing' => [null], 'malformed' => ['malformed'], 'unknown' => ['unknown'], 'wrong secret' => ['wrong_secret']]);

it('refuses revoked, expired and stale tokens and tokens of an account that is no longer platform_admin', function (string $case): void {
    $id = mcpTokenId($this->token);

    match ($case) {
        'revoked' => McpToken::query()->whereKey($id)->update(['revoked_at' => now()]),
        'expired' => McpToken::query()->whereKey($id)->update(['expires_at' => now()->subMinute()]),
        // Any credential change (TOTP, passkey removal, "Aizmirst ierīces", reset-mfa, role revoke) ends the token.
        'epoch' => SessionEpoch::bump($this->admin),
        'demoted' => $this->admin->forceFill(['platform_role' => null])->save(),
        'tombstone' => $this->admin->forceFill(['anonymised_at' => now()])->save(),
    };

    mcpRpc($this->token, 'tools/list')->assertStatus(401);
})->with(['revoked', 'expired', 'epoch', 'demoted', 'tombstone']);

it('never accepts a token of an ordinary account', function (): void {
    $resident = makeUser('resident-mcp@example.test');
    $token = mcpToken($resident);

    mcpRpc($token, 'tools/list')->assertStatus(401);
});

it('creates no session and sets no cookie', function (): void {
    $response = mcpRpc($this->token, 'tools/list')->assertOk();

    expect($response->headers->getCookies())->toBe([])
        ->and($response->headers->get('X-App-Version'))->toBe((string) config('app.version'));
});

it('does not accept the session cookie of a logged-in platform admin instead of a token', function (): void {
    $this->actingAs($this->admin);

    mcpRpc(null, 'tools/list')->assertStatus(401);
});

it('answers GET with 405 (no server-sent stream)', function (): void {
    $this->get('/mcp')->assertStatus(405);
});

it('throttles failed attempts per client bucket before any lookup', function (): void {
    for ($i = 0; $i < AuthenticateMcpToken::FAILURES_PER_15_MIN; $i++) {
        mcpRpc('kmcp_wrong', 'tools/list')->assertStatus(401);
    }

    // Now even a VALID token from the same client waits.
    mcpRpc($this->token, 'tools/list')->assertStatus(429)->assertHeader('Retry-After');
});

it('records the last use at most once a minute', function (): void {
    mcpRpc($this->token, 'tools/list')->assertOk();
    $first = McpToken::query()->find(mcpTokenId($this->token))->last_used_at;

    mcpRpc($this->token, 'tools/list')->assertOk();

    expect($first)->not->toBeNull()
        ->and(McpToken::query()->find(mcpTokenId($this->token))->last_used_at->equalTo($first))->toBeTrue();
});
