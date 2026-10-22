<?php

declare(strict_types=1);

use App\Mcp\Tools\McpTool;
use App\Models\AuditEntry;
use App\Models\McpToken;
use Illuminate\Support\Facades\RateLimiter;

/*
 * Every MCP call is audited (tool, actor, token, building, outcome) in the platform chain; arguments never are.
 * Rate limits: per client bucket, per token, and writes per token per hour.
 */

beforeEach(function (): void {
    $this->b = makeBuilding('mcp-audit-house', 1, 2);
    $this->admin = mcpAdmin();
    $this->read = mcpToken($this->admin, ['read']);
    $this->write = mcpToken($this->admin, ['read', 'write']);
});

function lastMcpCall(): AuditEntry
{
    return AuditEntry::query()->where('action', 'mcp.call')->orderByDesc('id')->firstOrFail();
}

it('audits a read call with tool, actor, token and building in the platform chain', function (): void {
    mcpTool($this->read, 'building_detail', ['building' => 'mcp-audit-house']);

    $row = lastMcpCall();

    expect($row->chain_key)->toBe(0)
        ->and($row->actor_user_id)->toBe($this->admin->id)
        ->and($row->actor_role)->toBe('platform_admin_mcp')
        ->and($row->subject_type)->toBe((new McpToken)->getMorphClass())
        ->and($row->data)->toMatchArray([
            'tool' => 'building_detail',
            'token_id' => mcpTokenId($this->read),
            'building_id' => $this->b->id,
            'scope' => 'read',
            'outcome' => 'ok',
        ])
        // The building context itself is audited too (purpose mcp_read).
        ->and(AuditEntry::query()->where('action', 'platform.building_context')->orderByDesc('id')->first()->data)
        ->toMatchArray(['purpose' => 'mcp_read', 'building_id' => $this->b->id, 'mcp_token_id' => mcpTokenId($this->read)]);
});

it('audits refusals, invalid input and unknown buildings', function (string $token, string $tool, array $args, string $outcome): void {
    mcpTool($this->{$token}, $tool, $args);

    expect(lastMcpCall()->data['outcome'])->toBe($outcome);
})->with([
    'scope' => ['read', 'grant_role', ['building' => 'mcp-audit-house', 'user_id' => 1, 'role' => 'volunteer', 'confirm' => true], 'refused:scope'],
    'confirm' => ['write', 'grant_role', ['building' => 'mcp-audit-house', 'user_id' => 1, 'role' => 'volunteer'], 'refused:confirm'],
    'unknown building' => ['read', 'building_detail', ['building' => 'no-such-house'], 'refused:building'],
    'invalid' => ['write', 'set_building_setting', ['building' => 'mcp-audit-house', 'key' => 'adhoc_report_valid_days', 'value' => '99', 'confirm' => true], 'invalid'],
    'not allowed key' => ['write', 'set_building_setting', ['building' => 'mcp-audit-house', 'key' => 'dpa_ref', 'value' => 'x', 'confirm' => true], 'refused:key'],
]);

it('audits a write on both chains: the call on the platform chain, the change on the building chain', function (): void {
    mcpTool($this->write, 'set_building_setting', ['building' => 'mcp-audit-house', 'key' => 'modules.outages', 'value' => 'true', 'confirm' => true]);

    expect(lastMcpCall()->data)->toMatchArray(['tool' => 'set_building_setting', 'scope' => 'write', 'outcome' => 'ok', 'building_id' => $this->b->id])
        ->and(AuditEntry::query()->forBuilding($this->b->id)->where('action', 'settings.update')->first()->data)->toEqual(['changed' => ['modules.outages'], 'via' => 'mcp'])
        ->and(AuditEntry::query()->where('action', 'platform.building_context')->orderByDesc('id')->first()->data['purpose'])->toBe('mcp_write');
});

it('never writes tool arguments into the audit log', function (): void {
    mcpTool($this->write, 'invite_team_member', ['building' => 'mcp-audit-house', 'email' => 'audit.argument.person@example.test', 'role' => 'building_admin', 'confirm' => true]);

    $all = AuditEntry::query()->get()->map(fn (AuditEntry $e): string => json_encode($e->data).' '.$e->reason_text)->join("\n");

    expect($all)->not->toContain('audit.argument.person')
        ->and(lastMcpCall()->data)->not->toHaveKey('arguments');
});

it('limits calls per token per minute', function (): void {
    config(['majas.mcp.per_token_per_minute' => 3]);

    for ($i = 0; $i < 3; $i++) {
        mcpRpc($this->read, 'tools/list')->assertOk();
    }

    mcpRpc($this->read, 'tools/list')->assertStatus(429)->assertHeader('Retry-After');
    // Another token of the same admin has its own budget.
    mcpRpc($this->write, 'tools/list')->assertOk();
});

it('limits calls per client bucket before authentication', function (): void {
    config(['majas.mcp.per_ip_per_minute' => 2]);

    mcpRpc($this->read, 'tools/list')->assertOk();
    mcpRpc($this->write, 'tools/list')->assertOk();
    mcpRpc($this->read, 'tools/list')->assertStatus(429);
});

it('limits writes per token per hour and audits the refusal', function (): void {
    for ($i = 0; $i < McpTool::WRITES_PER_HOUR; $i++) {
        RateLimiter::hit('mcp-write:'.mcpTokenId($this->write), 3600);
    }

    $result = mcpTool($this->write, 'set_building_setting', ['building' => 'mcp-audit-house', 'key' => 'modules.outages', 'value' => 'true', 'confirm' => true]);

    expect($result['isError'])->toBeTrue()
        ->and($result['text'])->toContain('Write limit')
        ->and(lastMcpCall()->data['outcome'])->toBe('refused:rate_limited')
        ->and($this->b->refresh()->settings->moduleEnabled('outages'))->toBeFalse();
});
