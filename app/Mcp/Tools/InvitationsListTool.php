<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Models\Building;
use App\Models\RoleInvitation;
use App\Models\Section;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('invitations_list')]
#[Description('Team (role) invitations of one building: invitation id, role, stairwells, masked e-mail hint, state (open / expired / accepted / revoked), dates, resend count and inviter user id. Use the id with resend_invitation.')]
#[IsReadOnly]
final class InvitationsListTool extends McpTool
{
    public function schema(JsonSchema $schema): array
    {
        return [
            'building' => $schema->string()->description('Building slug (see buildings_list).')->required(),
            'state' => $schema->string()->enum(['open', 'all'])->description('"open" (default): not accepted / revoked; "all": the last 50.'),
        ];
    }

    protected function execute(Request $request, User $actor): array
    {
        $building = $this->building($request);
        $all = $request->get('state') === 'all';

        return $this->inBuilding($building, static function (Building $b) use ($all): array {
            $sections = Section::query()->pluck('number', 'id');
            $query = RoleInvitation::query()->latest('created_at')->limit(50);
            if (! $all) {
                $query->whereNull('accepted_at')->whereNull('revoked_at');
            }

            $rows = $query->get()->map(static fn (RoleInvitation $i): array => [
                'invitation_id' => $i->id,
                'role' => $i->role->value,
                'sections' => array_values(array_map(static fn (int $id): int => (int) ($sections[$id] ?? 0), $i->section_ids)),
                'email_hint' => $i->email_hint,
                'locale' => $i->locale->value,
                'state' => match (true) {
                    $i->accepted_at !== null => 'accepted',
                    $i->revoked_at !== null => 'revoked',
                    ! $i->expires_at->isFuture() => 'expired',
                    default => 'open',
                },
                'invited_by_user_id' => $i->invited_by,
                'accepted_user_id' => $i->accepted_user_id,
                'created_at' => $i->created_at->toIso8601String(),
                'expires_at' => $i->expires_at->toIso8601String(),
                'resend_count' => (int) $i->resend_count,
            ])->values()->all();

            return ['building' => $b->slug, 'invitations' => $rows];
        });
    }
}
