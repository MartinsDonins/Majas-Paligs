<?php

declare(strict_types=1);

use App\Enums\BuildingRoleType;
use App\Enums\Locale;
use App\Mcp\McpAccess;
use App\Mcp\McpPrivacy;
use App\Mcp\McpPrivacyViolation;
use App\Mcp\Tools\McpTool;
use App\Models\AuditEntry;
use App\Models\HeatingReport;
use App\Models\McpToken;
use App\Models\RoleInvitation;
use App\Models\User;
use App\Services\Team\TeamInvitationService;
use Illuminate\Support\Facades\Auth;
use Laravel\Mcp\Request;

/*
 * The MCP privacy rule (docs/MCP.md §Privacy): no tool output carries a name, e-mail, phone, plate or free text —
 * counts, statuses, ids, apartment / stairwell numbers and masked hints only. Synthetic data only.
 */

beforeEach(function (): void {
    $this->b = parkingHouse('mcp-privacy-house', 2);
    $this->b->forceFill([
        'settings' => $this->b->settings->with(['modules' => ['heating' => true, 'outages' => true]]),
        'manager_name' => 'SIA Testa Pārvaldnieks',
        'manager_emergency_phone' => '+371 2'.'0000000',
    ])->save();

    $this->admin = mcpAdmin('mcp-privacy-admin@example.test');
    $this->token = mcpToken($this->admin, ['read', 'write']);

    // Personal data that must never come back over MCP.
    $this->secrets = [
        'janis.berzins.private@example.test',
        'elder.secret.person@example.test',
        'invitee.hidden.person@example.test',
        'Ļoti privāta piezīme 12345',
        'AB1234',
        '2'.'0000000',
        'Testa biedrība',
        'dati@example.test',
    ];

    $resident = makeUser('janis.berzins.private@example.test', ['display_name' => 'Jānis Bērziņš']);
    member($resident, $this->b, 7);
    $elder = makeUser('elder.secret.person@example.test');
    grantRole($elder, $this->b, BuildingRoleType::SectionElder, 1);
    grantRole($this->admin, $this->b, BuildingRoleType::BuildingAdmin);

    reportHeating($resident, $this->b, 7, ['kitchen' => 'cold', 'room1' => 'cold']);
    inBuilding($this->b, fn () => HeatingReport::query()->update(['note_private' => 'Ļoti privāta piezīme 12345']));

    activeVehicle($this->b, 9, 'AB1234');

    // A pending team invitation (the invitee's address exists only as ciphertext + hint).
    $this->actingAs($this->admin);
    inBuilding($this->b, function (): void {
        app(McpAccess::class)->authenticate(McpToken::query()->findOrFail(mcpTokenId($this->token)), $this->admin);
        app(McpAccess::class)->elevated(fn () => app(TeamInvitationService::class)
            ->invite($this->admin, 'invitee.hidden.person@example.test', BuildingRoleType::ParkingOfficer, [], null, Locale::Lv));
    });
    app()->forgetInstance(McpAccess::class);
    Auth::forgetGuards();
});

it('returns no personal data from any read tool', function (string $tool, array $args): void {
    $result = mcpTool($this->token, $tool, $args);

    expect($result['isError'])->toBeFalse($tool.': '.$result['text']);

    foreach ($this->secrets as $secret) {
        expect(str_contains($result['raw'], $secret))->toBeFalse("{$tool} leaked [{$secret}]");
        // JSON escapes non-ASCII and slashes: check the escaped form too.
        expect(str_contains($result['raw'], trim(json_encode($secret), '"')))->toBeFalse("{$tool} leaked [{$secret}] (escaped)");
    }
})->with([
    'app_status' => ['app_status', []],
    'buildings_list' => ['buildings_list', []],
    'building_detail' => ['building_detail', ['building' => 'mcp-privacy-house']],
    'users_and_roles' => ['users_and_roles', ['building' => 'mcp-privacy-house', 'include_expired' => true]],
    'invitations_list' => ['invitations_list', ['building' => 'mcp-privacy-house', 'state' => 'all']],
    'imports_list' => ['imports_list', ['building' => 'mcp-privacy-house']],
    'heating_summary' => ['heating_summary', ['building' => 'mcp-privacy-house']],
    'parking_summary' => ['parking_summary', ['building' => 'mcp-privacy-house']],
    'audit_search platform' => ['audit_search', ['since' => '2020-01-01']],
    'audit_search building' => ['audit_search', ['building' => 'mcp-privacy-house', 'since' => '2020-01-01']],
    'templates_status' => ['templates_status', ['building' => 'mcp-privacy-house']],
]);

it('shows masked e-mail hints instead of addresses', function (): void {
    $roles = mcpTool($this->token, 'users_and_roles', ['building' => 'mcp-privacy-house']);
    $invites = mcpTool($this->token, 'invitations_list', ['building' => 'mcp-privacy-house']);

    expect(collect($roles['data']['roles'])->pluck('email_hint')->all())->toContain('e•••n@e•••.test')
        ->and($invites['data']['invitations'][0]['email_hint'])->toBe(RoleInvitation::hint('invitee.hidden.person@example.test'));
});

it('never echoes the invitee address of invite_team_member, neither in the result nor in the audit log', function (): void {
    $result = mcpTool($this->token, 'invite_team_member', [
        'building' => 'mcp-privacy-house', 'email' => 'second.invitee.person@example.test', 'role' => 'volunteer', 'confirm' => true,
    ]);

    expect($result['isError'])->toBeFalse($result['text'])
        ->and($result['raw'])->not->toContain('second.invitee.person')
        ->and($result['data']['email_hint'])->toBe('s•••n@e•••.test');

    $audit = AuditEntry::query()->get()->map(fn (AuditEntry $e) => json_encode($e->data).$e->reason_text)->join(' ');
    expect($audit)->not->toContain('second.invitee.person');
});

it('withholds a whole result when a tool would return personal data (backstop)', function (array $result): void {
    expect(fn () => McpPrivacy::assertClean($result))->toThrow(McpPrivacyViolation::class);
})->with([
    'e-mail value' => [['rows' => [['hint' => 'janis@example.test']]]],
    'phone value' => [['x' => ['y' => 'Zvaniet +371 2'.'0000000']]],
    'bare 8 digits' => [['x' => '2'.'0000000']],
    'email key' => [['email' => null]],
    'nested name key' => [['a' => ['display_name' => ['x' => 1]]]],
    'note key' => [['note_private' => 'x']],
]);

it('lets ids, timestamps, hashes and masked hints through', function (): void {
    McpPrivacy::assertClean([
        'id' => '01jbz3k0m9q8r7s6t5v4w3x2y1',
        'at' => '2026-10-22T10:05:15+00:00',
        'date' => '2026-10-22 10:05:15+00',
        'fingerprint' => '12345678abcdef0123456789abcdef0123456789abcdef0123456789abcdef01',
        'hint' => 'j•••s@e•••.test',
        'phone_mask' => '+371 2•••••45',
        'apartments' => [1, 2, 180],
        'version' => '0.0.31',
    ]);

    expect(true)->toBeTrue();
});

it('reports a withheld result as an MCP error and audits it as withheld', function (): void {
    // The manager phone is house data, but never leaves over MCP — not even when a tool is written carelessly.
    $tool = new class extends McpTool
    {
        protected function execute(Request $request, User $actor): array
        {
            return ['phone_number_by_mistake' => '+371 2'.'0000000'];
        }
    };

    $access = app(McpAccess::class);
    $access->authenticate(McpToken::query()->findOrFail(mcpTokenId($this->token)), $this->admin);
    $this->actingAs($this->admin);

    $response = app()->call([$tool, 'handle']);
    $content = $response->content();

    expect($response->isError())->toBeTrue()
        ->and((string) $content)->toContain('withheld')
        ->and((string) $content)->not->toContain('2'.'0000000')
        ->and(AuditEntry::query()->where('action', 'mcp.call')->latest('id')->first()->data['outcome'])->toBe('withheld');
});
