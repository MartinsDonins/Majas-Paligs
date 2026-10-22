<?php

declare(strict_types=1);

use App\Auth\StepUpService;
use App\Enums\BuildingRoleType;
use App\Enums\Locale;
use App\Models\AuditEntry;
use App\Models\BuildingRole;
use App\Models\McpToken; // TODO(round3): verify name
use App\Models\User;
use App\Services\Team\TeamInvitationService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

/*
 * Round 3 acceptance — the in-app MCP server at POST /mcp (laravel/mcp, HTTP transport), written ahead of the feature
 * (group `round3`). Contract under test:
 * - bearer personal access tokens of a platform_admin; any unknown / bad token → the same 401, no session;
 * - scopes: a `read` token can never write;
 * - every write needs `"confirm": true` (strict boolean) and changes nothing without it;
 * - no tool output carries personal data: no e-mail, phone or plate pattern, no synthetic secret seeded below;
 * - exactly one audit row (`mcp.call`, platform chain) per tool call, arguments never logged;
 * - rate limits per token and per client.
 *
 * Every name the round-3 branch has to provide is marked `TODO(round3): verify name`. Local helpers are prefixed
 * `r3Mcp` so they never collide with the builders' tests/Support helpers. Synthetic data only (@example.test,
 * runtime-generated phone, the design system's sample plate "AB1234").
 *
 * Run: scripts/dev.sh pest --group=round3 tests/Feature/Mcp/McpServerTest.php
 */

/**
 * A personal access token row + its plaintext (`kmcp_<id>_<secret>`, only SHA-256(secret) stored).
 *
 * @param  list<string>  $scopes
 * @param  array<string, mixed>  $attributes
 */
function r3McpToken(User $user, array $scopes = ['read'], array $attributes = []): string
{
    $secret = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');

    // TODO(round3): verify name — App\Models\McpToken columns (label, scopes, token_hash, token_hint, auth_epoch, expires_at)
    // and the plaintext format "kmcp_{id}_{secret}" of McpTokenService::issue().
    $token = McpToken::query()->create($attributes + [
        'user_id' => $user->id,
        'label' => 'Acceptance',
        'scopes' => $scopes,
        'token_hash' => hash('sha256', $secret, true),
        'token_hint' => substr($secret, -4),
        'auth_epoch' => (int) $user->auth_epoch,
        'expires_at' => now()->addDays(30),
    ]);

    return 'kmcp_'.$token->id.'_'.$secret;
}

function r3McpTokenId(string $plaintext): string
{
    return explode('_', $plaintext, 3)[1];
}

/**
 * One JSON-RPC message to /mcp as a streamable-HTTP client sends it.
 *
 * @param  array<string, mixed>  $params
 */
function r3McpRpc(?string $token, string $method, array $params = [], int $id = 1): TestResponse
{
    $headers = ['Accept' => 'application/json, text/event-stream'];
    if ($token !== null) {
        $headers['Authorization'] = 'Bearer '.$token;
    }

    // The MCP route is stateless: a session step-up of this test must never help a bearer call.
    test()->flushSession();

    return test()->postJson('/mcp', ['jsonrpc' => '2.0', 'id' => $id, 'method' => $method, 'params' => (object) $params], $headers);
}

/**
 * tools/call → status, isError, the text and the raw body (everything a client could ever see).
 *
 * @param  array<string, mixed>  $arguments
 * @return array{status: int, isError: bool, text: string, raw: string}
 */
function r3McpCall(string $token, string $tool, array $arguments = []): array
{
    $response = r3McpRpc($token, 'tools/call', ['name' => $tool, 'arguments' => (object) $arguments]);
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
 * @return list<string>
 */
function r3McpToolNames(string $token): array
{
    return collect(r3McpRpc($token, 'tools/list')->assertOk()->json('result.tools'))->pluck('name')->values()->all();
}

/**
 * Personal-data patterns that must never appear in an MCP answer (masked hints like "j•••s@e•••.test" do not match).
 */
function r3McpAssertNoPii(string $output, string $label, array $secrets = []): void
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

function r3McpCalls(): int
{
    return AuditEntry::query()->where('action', 'mcp.call')->count(); // TODO(round3): verify name (audit action)
}

beforeEach(function (): void {
    $this->house = makeBuilding('mcp-accept', 2, 3, open: true);
    $this->house->forceFill(['settings' => $this->house->settings->with(['modules' => ['heating' => false, 'outages' => false]])])->save();

    $this->admin = makeUser('mcp-accept-admin@example.test', ['platform_role' => 'platform_admin']);
    $this->read = r3McpToken($this->admin, ['read']);
    $this->write = r3McpToken($this->admin, ['read', 'write']);
});

// ---------------------------------------------------------------- auth

it('serves the tool list to a valid platform-admin token without starting a session', function (): void {
    $response = r3McpRpc($this->read, 'tools/list')->assertOk();
    $names = collect($response->json('result.tools'))->pluck('name')->all();

    // TODO(round3): verify name — tool names of App\Mcp\Servers\KaiminiServer.
    expect($names)->toContain('app_status', 'buildings_list', 'building_detail', 'set_building_setting', 'grant_role')
        ->and($response->headers->get('Set-Cookie'))->toBeNull();
})->group('round3');

it('answers 401 with one neutral body to an unknown, missing, malformed, wrong, revoked, expired or non-admin token', function (string $case): void {
    $reference = r3McpRpc('kmcp_'.strtolower((string) Str::ulid()).'_'.str_repeat('A', 43), 'tools/list')->assertStatus(401);

    $token = match ($case) {
        'unknown' => 'kmcp_'.strtolower((string) Str::ulid()).'_'.str_repeat('B', 43),
        'missing' => null,
        'malformed' => 'not-a-token',
        'wrong secret' => substr($this->read, 0, -4).'ZZZZ',
        'revoked' => tap($this->read, fn (string $t) => McpToken::query()->whereKey(r3McpTokenId($t))->update(['revoked_at' => now()])),
        'expired' => tap($this->read, fn (string $t) => McpToken::query()->whereKey(r3McpTokenId($t))->update(['expires_at' => now()->subMinute()])),
        'not platform_admin' => tap($this->read, fn () => $this->admin->forceFill(['platform_role' => null])->save()),
        'credentials changed' => tap($this->read, fn () => $this->admin->forceFill(['auth_epoch' => (int) $this->admin->auth_epoch + 1])->save()),
        default => throw new LogicException($case),
    };

    $response = r3McpRpc($token, 'tools/call', ['name' => 'app_status', 'arguments' => (object) []])->assertStatus(401);

    expect($response->json())->toEqual($reference->json())
        ->and($response->headers->get('Set-Cookie'))->toBeNull()
        ->and((string) $response->getContent())->not->toContain('app_status');
})->with(['unknown', 'missing', 'malformed', 'wrong secret', 'revoked', 'expired', 'not platform_admin', 'credentials changed'])
    ->group('round3');

it('ignores a logged-in browser session: no bearer token, no access', function (): void {
    $this->actingAs($this->admin);
    steppedUp($this->admin, 'totp');

    $this->postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list', 'params' => (object) []], ['Accept' => 'application/json, text/event-stream'])
        ->assertStatus(401);
})->group('round3');

// ---------------------------------------------------------------- scopes

it('never lets a read token write, even with confirm=true', function (string $tool, array $args): void {
    $volunteer = makeUser('mcp-accept-volunteer@example.test');
    $args = array_map(static fn (mixed $v): mixed => $v === ':user' ? $volunteer->id : $v, $args);
    $rolesBefore = inBuilding($this->house, fn () => BuildingRole::query()->count());

    $result = r3McpCall($this->read, $tool, $args + ['building' => 'mcp-accept', 'confirm' => true]);

    expect($result['isError'])->toBeTrue("{$tool} with a read token was not refused: {$result['raw']}")
        ->and(inBuilding($this->house, fn () => BuildingRole::query()->count()))->toBe($rolesBefore)
        ->and($this->house->refresh()->settings->moduleEnabled('heating'))->toBeFalse();
})->with([
    // TODO(round3): verify name — write tools and their arguments.
    'grant_role' => ['grant_role', ['user_id' => ':user', 'role' => 'volunteer']],
    'set_building_setting' => ['set_building_setting', ['key' => 'modules.heating', 'value' => 'true']],
])->group('round3');

// ---------------------------------------------------------------- confirm

it('changes nothing until a write carries confirm=true (strict boolean)', function (): void {
    $args = ['building' => 'mcp-accept', 'key' => 'modules.heating', 'value' => 'true']; // TODO(round3): verify name

    foreach ([[], ['confirm' => false], ['confirm' => 'true'], ['confirm' => 1], ['confirm' => 'yes']] as $confirm) {
        $result = r3McpCall($this->write, 'set_building_setting', $args + $confirm);

        expect($result['isError'])->toBeTrue('accepted without confirm=true: '.json_encode($confirm))
            ->and($this->house->refresh()->settings->moduleEnabled('heating'))->toBeFalse();
    }

    $ok = r3McpCall($this->write, 'set_building_setting', $args + ['confirm' => true]);

    expect($ok['isError'])->toBeFalse($ok['raw'])
        ->and($this->house->refresh()->settings->moduleEnabled('heating'))->toBeTrue();
})->group('round3');

it('grants a role only with a write token and confirm=true', function (): void {
    $volunteer = makeUser('mcp-accept-helper@example.test');
    $args = ['building' => 'mcp-accept', 'user_id' => $volunteer->id, 'role' => 'volunteer']; // TODO(round3): verify name
    $held = fn (): bool => inBuilding($this->house, fn () => BuildingRole::query()->current()->where('user_id', $volunteer->id)->exists());

    expect(r3McpCall($this->write, 'grant_role', $args)['isError'])->toBeTrue()
        ->and($held())->toBeFalse();

    $ok = r3McpCall($this->write, 'grant_role', $args + ['confirm' => true]);
    expect($ok['isError'])->toBeFalse($ok['raw'])
        ->and($held())->toBeTrue();
})->group('round3');

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
    $tools = r3McpToolNames($this->write);
    foreach ($tools as $tool) {
        // Read pass (write tools refuse with the read token; their refusal text is an output too).
        foreach ([[], ['building' => 'mcp-accept-pii'], ['building' => 'mcp-accept-pii', 'section' => 1]] as $args) {
            $outputs["{$tool} read ".json_encode($args)] = r3McpCall($this->read, $tool, $args)['raw'];
        }
    }

    // Confirmed writes answer without echoing anything personal — not even the address the caller sent.
    // TODO(round3): verify name — write tools and arguments.
    $outputs['invite_team_member write'] = r3McpCall($this->write, 'invite_team_member', ['building' => 'mcp-accept-pii', 'email' => 'new.paraugs.private@example.test', 'role' => 'section_elder', 'section' => 1, 'confirm' => true])['raw'];
    $outputs['grant_role write'] = r3McpCall($this->write, 'grant_role', ['building' => 'mcp-accept-pii', 'user_id' => $resident->id, 'role' => 'volunteer', 'confirm' => true])['raw'];
    $outputs['set_building_setting write'] = r3McpCall($this->write, 'set_building_setting', ['building' => 'mcp-accept-pii', 'key' => 'modules.outages', 'value' => 'false', 'confirm' => true])['raw'];
    $outputs['users_and_roles after writes'] = r3McpCall($this->read, 'users_and_roles', ['building' => 'mcp-accept-pii'])['raw'];
    $outputs['invitations_list after writes'] = r3McpCall($this->read, 'invitations_list', ['building' => 'mcp-accept-pii'])['raw'];
    $outputs['audit_search after writes'] = r3McpCall($this->read, 'audit_search', ['building' => 'mcp-accept-pii'])['raw'];

    expect(count($outputs))->toBeGreaterThan(count($tools));
    foreach ($outputs as $label => $raw) {
        r3McpAssertNoPii($raw, $label, [...$secrets, 'new.paraugs.private']);
    }
})->group('round3');

// ---------------------------------------------------------------- audit

it('writes exactly one audit row per tool call, with tool, token and outcome — never the arguments', function (): void {
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
        $before = r3McpCalls();
        r3McpCall($token, $tool, $args);

        expect(r3McpCalls())->toBe($before + 1, "call #{$i} ({$tool}) did not write exactly one mcp.call row");

        $row = AuditEntry::query()->where('action', 'mcp.call')->orderByDesc('id')->firstOrFail();
        expect($row->chain_key)->toBe(0) // platform chain
            ->and((int) $row->actor_user_id)->toBe((int) $this->admin->id)
            ->and($row->data)->toMatchArray(['tool' => $tool, 'token_id' => r3McpTokenId($token)]) // TODO(round3): verify name
            ->and($row->data)->toHaveKey('outcome')
            ->and($row->data)->not->toHaveKey('arguments');
    }

    // tools/list and failed authentication are not tool calls.
    $before = r3McpCalls();
    r3McpRpc($this->read, 'tools/list')->assertOk();
    r3McpRpc('kmcp_nope', 'tools/call', ['name' => 'app_status', 'arguments' => (object) []])->assertStatus(401);
    expect(r3McpCalls())->toBe($before);

    $everything = AuditEntry::query()->get()->map(fn (AuditEntry $e): string => json_encode($e->data).' '.$e->reason_text)->join("\n");
    expect($everything)->not->toContain('audit.argument.paraugs');
})->group('round3');

// ---------------------------------------------------------------- rate limits

it('rate limits calls per token, and every token has its own budget', function (): void {
    config(['majas.mcp.per_token_per_minute' => 3]); // TODO(round3): verify name

    for ($i = 0; $i < 3; $i++) {
        r3McpRpc($this->read, 'tools/list')->assertOk();
    }

    $limited = r3McpRpc($this->read, 'tools/list')->assertStatus(429);
    expect($limited->headers->get('Retry-After'))->not->toBeNull();

    r3McpRpc($this->write, 'tools/list')->assertOk();
})->group('round3');

it('locks a client out after repeated bad tokens, even when it then presents a good one', function (): void {
    for ($i = 0; $i < 10; $i++) { // TODO(round3): verify name — AuthenticateMcpToken::FAILURES_PER_15_MIN = 10
        r3McpRpc('kmcp_'.strtolower((string) Str::ulid()).'_'.Str::random(43), 'tools/list')->assertStatus(401);
    }

    r3McpRpc($this->read, 'tools/list')->assertStatus(429);
})->group('round3');

it('limits per client before authentication', function (): void {
    config(['majas.mcp.per_ip_per_minute' => 2]); // TODO(round3): verify name

    r3McpRpc($this->read, 'tools/list')->assertOk();
    r3McpRpc($this->write, 'tools/list')->assertOk();
    r3McpRpc($this->read, 'tools/list')->assertStatus(429);
})->group('round3');
