<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Enums\BuildingRoleType;
use App\Enums\Locale;
use App\Mcp\McpRefusal;
use App\Models\Building;
use App\Models\McpToken;
use App\Models\Section;
use App\Models\User;
use App\Services\Team\TeamInvitationRefused;
use App\Services\Team\TeamInvitationService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsOpenWorld;

#[Name('invite_team_member')]
#[Description('WRITE. E-mail a team invitation (7 days, single use) for a building role. building_admin: the platform bootstrap (always allowed). Other roles: only when YOU hold building_admin in that house (grant it first). The address is used for the mail only — it is never echoed or logged; results show the masked hint. Needs a write token and "confirm": true.')]
#[IsOpenWorld]
final class InviteTeamMemberTool extends McpTool
{
    protected string $scope = McpToken::SCOPE_WRITE;

    public const ROLES = ['building_admin', 'section_elder', 'parking_officer', 'volunteer'];

    public function schema(JsonSchema $schema): array
    {
        return [
            'building' => $schema->string()->description('Building slug.')->required(),
            'email' => $schema->string()->format('email')->max(254)->description('Invitee address (synthetic @example.test in tests).')->required(),
            'role' => $schema->string()->enum(self::ROLES)->required(),
            'sections' => $schema->array()->items($schema->integer()->min(1))->description('Stairwell NUMBERS (section_elder only).'),
            'locale' => $schema->string()->enum(['lv', 'ru'])->description('Language of the mail (default lv).'),
            'mandate_ref' => $schema->string()->max(200)->description('Optional reference to the house decision (no personal data).'),
            'confirm' => $schema->boolean()->description('Must be true: the owner approved this exact invitation.')->required(),
        ];
    }

    protected function execute(Request $request, User $actor): array
    {
        $data = $request->validate([
            'email' => ['required', 'string', 'email:rfc', 'max:254'],
            'role' => ['required', 'string', 'in:'.implode(',', self::ROLES)],
            'sections' => ['nullable', 'array', 'max:20'],
            'sections.*' => ['integer', 'min:1'],
            'locale' => ['nullable', 'in:lv,ru'],
            'mandate_ref' => ['nullable', 'string', 'max:200'],
        ]);

        $building = $this->building($request);
        $role = BuildingRoleType::from((string) $data['role']);

        return $this->inBuilding($building, static function (Building $b) use ($actor, $data, $role): array {
            $numbers = array_values(array_unique(array_map('intval', (array) ($data['sections'] ?? []))));
            $sectionIds = Section::query()->whereIn('number', $numbers)->pluck('id')->map(static fn ($id): int => (int) $id)->all();
            if (count($sectionIds) !== count($numbers)) {
                throw new McpRefusal('section', 'Unknown stairwell number in "sections".');
            }

            try {
                $invitation = app(TeamInvitationService::class)->invite(
                    $actor,
                    (string) $data['email'],
                    $role,
                    $sectionIds,
                    $data['mandate_ref'] ?? null,
                    Locale::from((string) ($data['locale'] ?? 'lv')),
                    platformBootstrap: $role === BuildingRoleType::BuildingAdmin,
                );
            } catch (TeamInvitationRefused $e) {
                throw new McpRefusal($e->reason, $e->getMessage());
            }

            return [
                'building' => $b->slug,
                'invitation_id' => $invitation->id,
                'role' => $invitation->role->value,
                'sections' => $numbers,
                'email_hint' => $invitation->email_hint,
                'expires_at' => $invitation->expires_at->toIso8601String(),
            ];
        });
    }
}
