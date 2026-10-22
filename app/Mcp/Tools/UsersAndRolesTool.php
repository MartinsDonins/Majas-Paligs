<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Mcp\McpPrivacy;
use App\Models\ApartmentMembership;
use App\Models\Building;
use App\Models\BuildingRole;
use App\Models\Section;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('users_and_roles')]
#[Description('Team of one building: every current role (role id, user id, masked e-mail hint, stairwell, granted / expires, whether the holder has a second factor), plus membership counts by status and role and per stairwell. No names, no full e-mails, no per-person apartment link.')]
#[IsReadOnly]
final class UsersAndRolesTool extends McpTool
{
    public function schema(JsonSchema $schema): array
    {
        return [
            'building' => $schema->string()->description('Building slug (see buildings_list).')->required(),
            'include_expired' => $schema->boolean()->description('Also list revoked / expired roles of the last 12 months (default false).'),
        ];
    }

    protected function execute(Request $request, User $actor): array
    {
        $building = $this->building($request);
        $includeExpired = $request->get('include_expired') === true;

        return $this->inBuilding($building, static function (Building $b) use ($includeExpired): array {
            $sections = Section::query()->pluck('number', 'id');

            $query = BuildingRole::query()->with('user')->orderBy('role')->orderBy('granted_at');
            $includeExpired
                ? $query->where(fn ($q) => $q->whereNull('revoked_at')->orWhere('revoked_at', '>', now()->subYear()))
                : $query->current();

            $roles = $query->get()->map(static fn (BuildingRole $r): array => [
                'role_id' => (int) $r->id,
                'role' => $r->role->value,
                'section' => $r->section_id !== null ? (int) ($sections[$r->section_id] ?? 0) : null,
                'user_id' => (int) $r->user_id,
                'email_hint' => $r->user?->isTombstone() ? null : McpPrivacy::maskEmail($r->user?->email),
                'deleted_user' => (bool) $r->user?->isTombstone(),
                'has_second_factor' => (bool) $r->user?->hasMfaFactor(),
                'granted_at' => $r->granted_at->toIso8601String(),
                'expires_at' => $r->expires_at->toIso8601String(),
                'revoked_at' => $r->revoked_at?->toIso8601String(),
                'current' => $r->revoked_at === null && $r->expires_at->isFuture(),
            ])->values()->all();

            $byStatus = ApartmentMembership::query()->selectRaw('status, count(*) AS n')->groupBy('status')->pluck('n', 'status')
                ->map(static fn ($n): int => (int) $n)->all();
            $byRole = ApartmentMembership::query()->where('status', 'approved')->selectRaw('role, count(*) AS n')->groupBy('role')->pluck('n', 'role')
                ->map(static fn ($n): int => (int) $n)->all();
            $bySection = ApartmentMembership::query()
                ->join('apartments', 'apartments.id', '=', 'apartment_memberships.apartment_id')
                ->whereIn('apartment_memberships.status', ['approved', 'pending'])
                ->selectRaw('apartments.section_id, apartment_memberships.status, count(*) AS n')
                ->groupBy('apartments.section_id', 'apartment_memberships.status')
                ->get()
                ->groupBy('section_id')
                ->map(static fn ($rows, $sectionId): array => [
                    'section' => (int) ($sections[(int) $sectionId] ?? 0),
                    'approved' => (int) ($rows->firstWhere('status', 'approved')?->getAttribute('n') ?? 0),
                    'pending' => (int) ($rows->firstWhere('status', 'pending')?->getAttribute('n') ?? 0),
                ])->sortBy('section')->values()->all();

            return [
                'building' => $b->slug,
                'roles' => $roles,
                'memberships' => ['by_status' => $byStatus, 'approved_by_role' => $byRole, 'by_section' => $bySection],
            ];
        });
    }
}
