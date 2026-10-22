<?php

declare(strict_types=1);

use App\Auth\StepUpService;
use App\Enums\BuildingRoleType;
use App\Enums\Locale;
use App\Models\AuditEntry;
use App\Models\McpToken;
use App\Services\Team\TeamInvitationService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

/*
 * Round 3 acceptance — the in-app MCP server at POST /mcp as a whole (docs/MCP.md, ADR 0008). The per-feature files
 * next to this one cover each rule; this file keeps the cross-cutting contracts that a single feature test cannot:
 * - every bad token (unknown, missing, malformed, wrong, revoked, expired, demoted, stale, tombstoned) gets the SAME
 *   401 body, with no session and no hint of a tool;
 * - no output of ANY listed tool (read pass, refusals and confirmed writes) carries an e-mail, phone or plate pattern
 *   or one of the synthetic secrets seeded below — new tools are covered automatically through tools/list;
 * - exactly one `mcp.call` audit row per tool call (refusals included), none for tools/list or a failed login.
 * Synthetic data only (@example.test, syntheticPhone(), the design system's sample plate "AB1234").
 */

/**
 * tools/call → status, isError, the text and the raw body (everything a client could ever see).
 *
 * @param  array<string, mixed>  $arguments
 * @return array{status: int, isError: bool, text: string, raw: string}
 */
function mcpServerCall(string $token, string $tool, array $arguments = []): array
{
    $response = mcpRpc($token, 'tools/call', ['name' => $tool, 'arguments' => (object) $arguments]);
    $result = (array) $response->json('result');
    $text = collect((array) ($result['content'] ?? []))->pluck('text')->filter()->join("\n");

    return [
        'status' => $response->getStatusCode(),
        // A refusal may be a tool result with isError, or a JSON-RPC error — both count as "refused".
        'isError' => (bool) ($result['isError'] ?? false) || $response->json('error') !== null || $response->getStatusCode() >= 400,
        'text' => $text,
        'raw' => (string) $response->getContent(),
    ];
}

/**
 * Personal-data patterns that must never appear in an MCP answer (masked hints like "j•••s@e•••.test" do not match).
 *
 * @param  list<string>  $secrets
 */
function mcpServerAssertNoPii(string $output, string $label, array $secrets = []): void
{
    // json_encode escapes "/" and non-ASCII; decode \uXXXX so the patterns see what a client renders.
    $plain = (string) preg_replace_callback('/\\\\u([0-9a-fA-F]{4})/', static fn (array $m): string => mb_chr((int) hexdec($m[1])), str_replace('\/', '/', $output));

    expect(preg_match('/[A-Za-z0-9._%+-]+@[A-Za-z0-9-]+(?:\.[A-Za-z0-9-]+)*\.[A-Za-z]{2,}/', $plain, $m))
        ->toBe(0, "{$label}: e-mail address in the output: ".($m[0] ?? ''));
    // E.164 / Latvian numbers: +371…, 00371…, or a bare 8-digit 2xxxxxxx / 6xxxxxxx number.
    expect(preg_match('/(?:\+|(?<!\d)00)\d[\d\s().-]{6,}\d|(?<![\w.:-])[26]\d{7}(?![\w.:-])/', $plain, $m))
        ->toBe(0, "{$label}: phone number in the output: ".($m[0] ?? ''));
    // Latvian plates ("AB1234", "AB-123").
    expect(preg_match('/(?<![A-Za-z0-9])[A-Z]{2}-?\d{2,4}(?![A-Za-z0-9])/', $plain, $m))
        ->toBe(0, "{$label}: plate-shaped string in the output: ".($m[0] ?? ''));

    foreach ($secrets as $secret) {
        expect(mb_stripos($plain, $secret))->toBeFalse("{$label}: leaked \"{$secret}\"");
    }
}

function mcpServerCalls(): int
{
    return AuditEntry::query()->where('action', 'mcp.call')->count();
}

beforeEach(function (): void {
    $this->house = makeBuilding('mcp-accept', 2, 3, open: true);
    $this->house->forceFill(['settings' => $this->house->settings->with(['modules' => ['heating' => false, 'outages' => false]])])->save();

    $this->admin = mcpAdmin('mcp-accept-admin@example.test');
    $this->read = mcpToken($this->admin, ['read']);
    $this->write = mcpToken($this->admin, ['read', 'write']);
});

// ---------------------------------------------------------------- auth

it('answers 401 with one neutral body to every unknown, missing, malformed, wrong, revoked, expired or stale token', function (string $case): void {
    $reference = mcpRpc('kmcp_'.strtolower((string) Str::ulid()).'_'.str_repeat('A', 43), 'tools/list')->assertStatus(401);

    $token = match ($case) {
        'unknown' => 'kmcp_'.strtolower((string) Str::ulid()).'_'.str_repeat('B', 43),
        'missing' => null,
        'malformed' => 'not-a-token',
        'wrong secret' => substr($this->read, 0, -4).'ZZZZ',
        'revoked' => tap($this->read, fn (string $t) => McpToken::query()->whereKey(mcpTokenId($t))->update(['revoked_at' => now()])),
        'expired' => tap($this->read, fn (string $t) => McpToken::query()->whereKey(mcpTokenId($t))->update(['expires_at' => now()->subMinute()])),
        'not platform_admin' => tap($this->read, fn () => $this->admin->forceFill(['platform_role' => null])->save()),
        'credentials changed' => tap($this->read, fn () => $this->admin->forceFill(['auth_epoch' => (int) $this->admin->auth_epoch + 1])->save()),
        'tombstone' => tap($this->read, fn () => $this->admin->forceFill(['anonymised_at' => now()])->save()),
        default => throw new LogicException($case),
    };

    $response = mcpRpc($token, 'tools/call', ['name' => 'app_status', 'arguments' => (object) []])->assertStatus(401);

    expect($response->json())->toEqual($reference->json())
        ->and($response->headers->getCookies())->toBe([])
        ->and((string) $response->getContent())->not->toContain('app_status');
})->with(['unknown', 'missing', 'malformed', 'wrong secret', 'revoked', 'expired', 'not platform_admin', 'credentials changed', 'tombstone']);

// ---------------------------------------------------------------- privacy

it('keeps every tool output free of e-mails, phones and plates', function (): void {
    // A house full of synthetic personal data that MCP must never return.
    $b = parkingHouse('mcp-accept-pii', 2);
    $phone = syntheticPhone(4242);
    $b->forceFill([
        'settings' => $b->settings->with(['modules' => ['heating' => true, 'outages' => true, 'parking' => true]]),
        'manager_name' => 'SIA Paraugs Pārvaldnieks',
        'manager_emergency_phone' => $phone,
    ])->save();
    $resident = makeUser('liga.paraudzina.private@example.test', ['display_name' => 'Līga Paraudziņa']);
    member($resident, $b, 7);
    $elder = makeUser('elder.paraugs.private@example.test', ['display_name' => 'Vecākais Paraugs']);
    grantRole($elder, $b, BuildingRoleType::SectionElder, 1);
    grantRole($this->admin, $b, BuildingRoleType::BuildingAdmin);
    reportHeating($resident, $b, 7, ['kitchen' => 'cold', 'room1' => 'cold', 'room2' => 'cold']);
    activeVehicle($b, 9, 'AB1234');

    // A pending team invitation: the invitee's address lives only as ciphertext + hint.
    Auth::login($this->admin);
    session()->put(StepUpService::SESSION_KEY, ['user' => $this->admin->id, 'at' => now()->getTimestamp(), 'seen' => now()->getTimestamp(), 'method' => 'passkey']);
    inBuilding($b, fn () => app(TeamInvitationService::class)->invite($this->admin, 'invitee.paraugs.private@example.test', BuildingRoleType::SectionElder, [(int) section($b, 2)->id], null, Locale::Lv));
    Auth::logout();
    session()->flush();

    $secrets = [
        'liga.paraudzina.private', 'elder.paraugs.private', 'invitee.paraugs.private', 'mcp-accept-admin@',
        'Līga Paraudziņa', 'Vecākais Paraugs', 'SIA Paraugs Pārvaldnieks', 'Testa biedrība', 'dati@example.test',
        $phone, substr($phone, 4), 'AB1234',
    ];

    $outputs = [];
    $tools = collect(mcpRpc($this->write, 'tools/list')->assertOk()->json('result.tools'))->pluck('name')->values()->all();
    foreach ($tools as $tool) {
        // Read pass (write tools refuse with the read token; their refusal text is an output too).
        foreach ([[], ['building' => 'mcp-accept-pii'], ['building' => 'mcp-accept-pii', 'section' => 1]] as $args) {
            $outputs["{$tool} read ".json_encode($args)] = mcpServerCall($this->read, $tool, $args)['raw'];
        }
    }

    // Confirmed writes answer without echoing anything personal — not even the address the caller sent.
    $outputs['invite_team_member write'] = mcpServerCall($this->write, 'invite_team_member', ['building' => 'mcp-accept-pii', 'email' => 'new.paraugs.private@example.test', 'role' => 'section_elder', 'section' => 1, 'confirm' => true])['raw'];
    $outputs['grant_role write'] = mcpServerCall($this->write, 'grant_role', ['building' => 'mcp-accept-pii', 'user_id' => $resident->id, 'role' => 'volunteer', 'confirm' => true])['raw'];
    $outputs['set_building_setting write'] = mcpServerCall($this->write, 'set_building_setting', ['building' => 'mcp-accept-pii', 'key' => 'modules.outages', 'value' => 'false', 'confirm' => true])['raw'];
    $outputs['users_and_roles after writes'] = mcpServerCall($this->read, 'users_and_roles', ['building' => 'mcp-accept-pii'])['raw'];
    $outputs['invitations_list after writes'] = mcpServerCall($this->read, 'invitations_list', ['building' => 'mcp-accept-pii'])['raw'];
    $outputs['audit_search after writes'] = mcpServerCall($this->read, 'audit_search', ['building' => 'mcp-accept-pii'])['raw'];

    expect(count($outputs))->toBeGreaterThan(count($tools));
    foreach ($outputs as $label => $raw) {
        mcpServerAssertNoPii($raw, $label, [...$secrets, 'new.paraugs.private']);
    }
});

// ---------------------------------------------------------------- audit

it('writes exactly one audit row per tool call, refusals included, and none for tools/list or a failed login', function (): void {
    $calls = [
        [$this->read, 'app_status', []],
        [$this->read, 'building_detail', ['building' => 'mcp-accept']],
        [$this->read, 'building_detail', ['building' => 'no-such-house']],                                          // refused
        [$this->read, 'set_building_setting', ['building' => 'mcp-accept', 'key' => 'modules.heating', 'value' => 'true', 'confirm' => true]], // scope
        [$this->write, 'set_building_setting', ['building' => 'mcp-accept', 'key' => 'modules.heating', 'value' => 'true']],                    // confirm
        [$this->write, 'set_building_setting', ['building' => 'mcp-accept', 'key' => 'modules.heating', 'value' => 'true', 'confirm' => true]], // ok
        [$this->write, 'invite_team_member', ['building' => 'mcp-accept', 'email' => 'audit.argument.paraugs@example.test', 'role' => 'building_admin', 'confirm' => true]],
    ];

    foreach ($calls as $i => [$token, $tool, $args]) {
        $before = mcpServerCalls();
        mcpServerCall($token, $tool, $args);

        expect(mcpServerCalls())->toBe($before + 1, "call #{$i} ({$tool}) did not write exactly one mcp.call row");

        $row = AuditEntry::query()->where('action', 'mcp.call')->orderByDesc('id')->firstOrFail();
        expect($row->chain_key)->toBe(0) // platform chain
            ->and((int) $row->actor_user_id)->toBe((int) $this->admin->id)
            ->and($row->data)->toMatchArray(['tool' => $tool, 'token_id' => mcpTokenId($token)])
            ->and($row->data)->toHaveKey('outcome');
    }

    $before = mcpServerCalls();
    mcpRpc($this->read, 'tools/list')->assertOk();
    mcpRpc('kmcp_nope', 'tools/call', ['name' => 'app_status', 'arguments' => (object) []])->assertStatus(401);
    expect(mcpServerCalls())->toBe($before);
});
