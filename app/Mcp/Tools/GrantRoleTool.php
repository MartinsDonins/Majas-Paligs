<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Enums\BuildingRoleType;
use App\Mcp\McpPrivacy;
use App\Mcp\McpRefusal;
use App\Models\Building;
use App\Models\McpToken;
use App\Models\Section;
use App\Models\User;
use App\Services\Memberships\BuildingRoleService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[Name('grant_role')]
#[Description('WRITE. Grant a building role (building_admin, section_elder with "section", parking_officer, volunteer) to an existing account by user id — also to yourself. 12-month mandate; re-granting a current role extends it. Elevated roles need an account with an e-mail. Needs a write token and "confirm": true.')]
#[IsDestructive(false)]
final class GrantRoleTool extends McpTool
{
    protected string $scope = McpToken::SCOPE_WRITE;

    public const ROLES = ['building_admin', 'section_elder', 'parking_officer', 'volunteer'];

    public function schema(JsonSchema $schema): array
    {
        return [
            'building' => $schema->string()->description('Building slug.')->required(),
            'user_id' => $schema->integer()->min(1)->description('Account id (users_and_roles lists role holders; your own id is in the token owner line of app_status).')->required(),
            'role' => $schema->string()->enum(self::ROLES)->required(),
            'section' => $schema->integer()->min(1)->description('Stairwell NUMBER (section_elder only).'),
            'mandate_ref' => $schema->string()->max(200)->description('Optional reference to the house decision / authorisation (no personal data).'),
            'confirm' => $schema->boolean()->description('Must be true: the owner approved this exact change.')->required(),
        ];
    }

    protected function execute(Request $request, User $actor): array
    {
        $data = $request->validate([
            'user_id' => ['required', 'integer', 'min:1'],
            'role' => ['required', 'string', 'in:'.implode(',', self::ROLES)],
            'section' => ['nullable', 'integer', 'min:1'],
            'mandate_ref' => ['nullable', 'string', 'max:200'],
        ]);

        $building = $this->building($request);
        $user = User::query()->whereKey((int) $data['user_id'])->first();
        if ($user === null || $user->isTombstone()) {
            throw new McpRefusal('user', 'Unknown or deleted account id.');
        }

        $role = BuildingRoleType::from((string) $data['role']);

        return $this->inBuilding($building, function (Building $b) use ($actor, $user, $role, $data): array {
            $sectionId = null;
            if ($role === BuildingRoleType::SectionElder) {
                $sectionId = isset($data['section']) ? Section::query()->where('number', (int) $data['section'])->value('id') : null;
                if ($sectionId === null) {
                    throw new McpRefusal('section', 'section_elder needs "section": an existing stairwell number of this building.');
                }
            }

            $grant = app(BuildingRoleService::class)->grant($actor, $user, $role, $sectionId !== null ? (int) $sectionId : null, $data['mandate_ref'] ?? null);

            return [
                'building' => $b->slug,
                'role_id' => (int) $grant->id,
                'role' => $grant->role->value,
                'section' => $role === BuildingRoleType::SectionElder ? (int) $data['section'] : null,
                'user_id' => (int) $user->id,
                'email_hint' => McpPrivacy::maskEmail($user->email),
                'expires_at' => $grant->expires_at->toIso8601String(),
                'reconfirmed' => ! $grant->wasRecentlyCreated,
            ];
        });
    }
}
