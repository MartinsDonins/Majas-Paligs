<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Enums\MembershipStatus;
use App\Gdpr\CoreGate;
use App\Gdpr\ParkingGate;
use App\Gdpr\RosterGate;
use App\Models\Apartment;
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

#[Name('buildings_list')]
#[Description('All buildings on the platform: slug, address, series, status, gate state (L-core / roster / parking level) and structure / membership / role counts.')]
#[IsReadOnly]
final class BuildingsListTool extends McpTool
{
    public const MAX = 50;

    public function schema(JsonSchema $schema): array
    {
        return [];
    }

    protected function execute(Request $request, User $actor): array
    {
        $buildings = Building::query()->with('series')->orderBy('name')->limit(self::MAX)->get();

        $rows = $buildings->map(fn (Building $b): array => $this->inBuilding($b, static function (Building $building): array {
            $core = CoreGate::status($building);
            $roster = RosterGate::status($building);
            $parking = ParkingGate::status($building);

            return [
                'slug' => $building->slug,
                'name' => $building->name,
                'city' => $building->city,
                'series' => $building->series?->code,
                'status' => $building->status->value,
                'opening_completed_at' => $building->settings->openingCompletedAt,
                'gates' => [
                    'core_open' => $core->open,
                    'roster_open' => $roster->open,
                    'parking_level' => $parking->level,
                    'parking_module_on' => $parking->moduleOn,
                ],
                'counts' => [
                    'sections' => Section::query()->count(),
                    'apartments' => Apartment::query()->count(),
                    'members_approved' => ApartmentMembership::query()->where('status', MembershipStatus::Approved->value)->count(),
                    'members_pending' => ApartmentMembership::query()->where('status', MembershipStatus::Pending->value)->count(),
                    'roles_current' => BuildingRole::query()->current()->count(),
                ],
            ];
        }))->values()->all();

        // One building context per row was opened; the call itself is not about one building.
        $this->forgetAuditBuilding();

        return ['buildings' => $rows, 'total' => Building::query()->count(), 'shown' => count($rows)];
    }
}
