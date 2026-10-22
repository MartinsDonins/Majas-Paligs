<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Enums\BuildingRoleType;
use App\Mcp\McpRefusal;
use App\Models\Building;
use App\Models\McpToken;
use App\Models\RoleInvitation;
use App\Models\User;
use App\Services\Team\TeamInvitationRefused;
use App\Services\Team\TeamInvitationService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsOpenWorld;

#[Name('resend_invitation')]
#[Description('WRITE. Re-send an open team invitation by its id (invitations_list): a new link, 7 more days, the old link stops working. At most 3 resends. building_admin invitations always; other roles only when you hold building_admin in that house. Needs a write token and "confirm": true.')]
#[IsOpenWorld]
final class ResendInvitationTool extends McpTool
{
    protected string $scope = McpToken::SCOPE_WRITE;

    public function schema(JsonSchema $schema): array
    {
        return [
            'building' => $schema->string()->description('Building slug.')->required(),
            'invitation_id' => $schema->string()->description('Invitation id from invitations_list.')->required(),
            'confirm' => $schema->boolean()->description('Must be true: the owner approved this resend.')->required(),
        ];
    }

    protected function execute(Request $request, User $actor): array
    {
        $data = $request->validate(['invitation_id' => ['required', 'string', 'size:26', 'alpha_num']]);
        $building = $this->building($request);

        return $this->inBuilding($building, static function (Building $b) use ($actor, $data): array {
            $invitation = RoleInvitation::query()->whereKey(strtolower((string) $data['invitation_id']))->first();
            if ($invitation === null) {
                throw new McpRefusal('invitation', 'No invitation with this id in this building.');
            }

            try {
                app(TeamInvitationService::class)->resend($actor, $invitation, platformBootstrap: $invitation->role === BuildingRoleType::BuildingAdmin);
            } catch (TeamInvitationRefused $e) {
                throw new McpRefusal($e->reason, $e->getMessage());
            }

            return [
                'building' => $b->slug,
                'invitation_id' => $invitation->id,
                'role' => $invitation->role->value,
                'email_hint' => $invitation->email_hint,
                'resend_count' => (int) $invitation->resend_count,
                'expires_at' => $invitation->expires_at->toIso8601String(),
            ];
        });
    }
}
