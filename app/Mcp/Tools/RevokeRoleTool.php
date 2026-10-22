<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Mcp\McpRefusal;
use App\Models\Building;
use App\Models\BuildingRole;
use App\Models\McpToken;
use App\Models\User;
use App\Services\Memberships\BuildingRoleService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[Name('revoke_role')]
#[Description('WRITE. Revoke one building role by its role id (see users_and_roles). The last building_admin of a house is kept unless "allow_last_admin": true (platform override). The holder\'s other sessions end. Needs a write token and "confirm": true.')]
#[IsDestructive]
final class RevokeRoleTool extends McpTool
{
    protected string $scope = McpToken::SCOPE_WRITE;

    public function schema(JsonSchema $schema): array
    {
        return [
            'building' => $schema->string()->description('Building slug.')->required(),
            'role_id' => $schema->integer()->min(1)->description('Role id from users_and_roles.')->required(),
            'allow_last_admin' => $schema->boolean()->description('Platform override: allow removing the LAST building_admin (default false).'),
            'confirm' => $schema->boolean()->description('Must be true: the owner approved this exact change.')->required(),
        ];
    }

    protected function execute(Request $request, User $actor): array
    {
        $data = $request->validate(['role_id' => ['required', 'integer', 'min:1']]);
        $building = $this->building($request);
        $override = $request->get('allow_last_admin') === true;

        return $this->inBuilding($building, static function (Building $b) use ($actor, $data, $override): array {
            /** @var BuildingRole|null $role */
            $role = BuildingRole::query()->whereKey((int) $data['role_id'])->first();
            if ($role === null) {
                throw new McpRefusal('role', 'No role with this id in this building.');
            }
            if ($role->revoked_at !== null) {
                throw new McpRefusal('role', 'This role is already revoked.');
            }

            app(BuildingRoleService::class)->revoke($actor, $role, platformOverride: $override);

            return [
                'building' => $b->slug,
                'role_id' => (int) $role->id,
                'role' => $role->role->value,
                'user_id' => (int) $role->user_id,
                'revoked_at' => $role->refresh()->revoked_at?->toIso8601String(),
            ];
        });
    }
}
