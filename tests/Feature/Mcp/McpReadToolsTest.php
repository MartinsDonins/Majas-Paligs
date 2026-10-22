<?php

declare(strict_types=1);

use App\Audit\AuditLogger;
use App\Enums\BuildingRoleType;
use App\Mcp\Servers\KaiminiServer;
use App\Mcp\Tools\McpTool;
use App\Models\ImportRun;
use Laravel\Mcp\Server\Transport\FakeTransporter;

/*
 * The read tools answer what the owner asked for on day one ("māja neradās, neredzu neko"): which houses exist, what
 * is generated, which gate / opening step is missing, who holds which role.
 */

beforeEach(function (): void {
    $this->b = makeBuilding('mcp-read-house', 2, 3);
    $this->admin = mcpAdmin();
    $this->token = mcpToken($this->admin);
});

it('lists buildings with gates and counts', function (): void {
    $result = mcpTool($this->token, 'buildings_list');

    $row = collect($result['data']['buildings'])->firstWhere('slug', 'mcp-read-house');

    expect($result['data']['total'])->toBe(1)
        ->and($row['series'])->toBe('602')
        ->and($row['gates'])->toBe(['core_open' => false, 'roster_open' => false, 'parking_level' => 0, 'parking_module_on' => false])
        ->and($row['counts'])->toMatchArray(['sections' => 2, 'apartments' => 24, 'members_approved' => 0, 'roles_current' => 0]);
});

it('explains a closed house: missing gate conditions and opening steps', function (): void {
    $d = mcpTool($this->token, 'building_detail', ['building' => 'mcp-read-house'])['data'];

    $steps = collect($d['opening']['steps'])->pluck('status', 'step');

    expect($d['gates']['core']['open'])->toBeFalse()
        ->and($d['gates']['core']['missing'])->toContain('privacy_notice')
        ->and($steps['structure'])->toBe('done')
        ->and($steps['controller'])->toBe('todo')
        ->and($d['opening']['progress']['core'])->toBe(['done' => 1, 'total' => 4])
        ->and($d['structure']['sections'][1])->toMatchArray(['number' => 2, 'first_apartment' => 13, 'last_apartment' => 24])
        ->and($d['settings']['controller_set'])->toBeFalse()
        ->and($d['team']['building_admins'])->toBe(0);
});

it('lists roles with stairwells and expiry', function (): void {
    $elder = makeUser('mcp-read-elder@example.test');
    grantRole($elder, $this->b, BuildingRoleType::SectionElder, 2);

    $roles = mcpTool($this->token, 'users_and_roles', ['building' => 'mcp-read-house'])['data']['roles'];

    expect($roles)->toHaveCount(1)
        ->and($roles[0])->toMatchArray(['role' => 'section_elder', 'section' => 2, 'user_id' => $elder->id, 'current' => true, 'has_second_factor' => false]);
});

it('lists import runs as counts and codes only', function (): void {
    inBuilding($this->b, fn () => ImportRun::query()->create([
        'kind' => 'roster', 'status' => ImportRun::VALIDATED, 'file_sha256' => random_bytes(32), 'encoding' => 'utf-8', 'delimiter' => ';',
        'has_header' => true, 'row_count' => 3, 'expires_at' => now()->addDay(),
        'summary' => ['dry_run_at' => now()->toIso8601String(), 'rows' => 3, 'status' => ['ok' => 2, 'warning' => 0, 'error' => 1], 'actions' => ['create' => 2, 'update' => 0, 'skip' => 1], 'codes' => ['phone_invalid' => 1, 'Jānis Bērziņš' => 5]],
    ]));

    $run = mcpTool($this->token, 'imports_list', ['building' => 'mcp-read-house'])['data']['imports'][0];

    expect($run)->toMatchArray(['kind' => 'roster', 'status' => 'validated', 'rows' => 3])
        ->and($run['summary']['validation'])->toBe(['phone_invalid' => 1])
        ->and($run['summary']['status'])->toEqual(['ok' => 2, 'warning' => 0, 'error' => 1]);
});

it('searches the audit log by chain, action prefix and actor, without free text', function (): void {
    inBuilding($this->b, fn () => AuditLogger::record('role.grant', data: ['role' => 'volunteer', 'comment_like' => 'Brīvs teksts ar atstarpēm'], reasonText: 'Privāts iemesls', actorUserId: $this->admin->id));

    $building = mcpTool($this->token, 'audit_search', ['building' => 'mcp-read-house', 'action' => 'role.']);
    $platform = mcpTool($this->token, 'audit_search', ['action' => 'role.']);

    $entry = $building['data']['entries'][0];

    expect($building['data']['chain'])->toBe('mcp-read-house')
        ->and($entry['action'])->toBe('role.grant')
        ->and($entry['has_reason_text'])->toBeTrue()
        ->and($entry['data'])->toBe(['role' => 'volunteer', 'comment_like' => '[omitted]'])
        ->and($building['raw'])->not->toContain('Privāts')
        ->and($platform['data']['entries'])->toBe([]);

    $bad = mcpTool($this->token, 'audit_search', ['since' => 'vakar']);
    expect($bad['isError'])->toBeTrue();
});

it('reports the document masters and a house without documents', function (): void {
    $result = mcpTool($this->token, 'templates_status', ['building' => 'mcp-read-house']);

    expect($result['isError'])->toBeFalse($result['text'])
        ->and(collect($result['data']['masters'])->pluck('kind')->all())->toContain('dpa', 'privacy_notice')
        ->and(collect($result['data']['building']['documents'])->pluck('status')->unique()->all())->toBe(['missing']);
});

it('refuses an unknown building with a hint', function (): void {
    $result = mcpTool($this->token, 'heating_summary', ['building' => 'nav-tadas']);

    expect($result['isError'])->toBeTrue()->and($result['text'])->toContain('buildings_list');
});

it('builds every tool on the audited base class with a snake_case name and a description', function (): void {
    $tools = (fn () => $this->tools)->call(new KaiminiServer(new FakeTransporter));

    foreach ($tools as $class) {
        $tool = app($class);
        expect($tool)->toBeInstanceOf(McpTool::class)
            ->and($tool->name())->toMatch('/^[a-z]+(_[a-z]+)+$/')
            ->and(strlen($tool->description()))->toBeGreaterThan(40)
            ->and((new ReflectionMethod($class, 'handle'))->isFinal())->toBeTrue();
    }

    expect($tools)->toHaveCount(17);
});
