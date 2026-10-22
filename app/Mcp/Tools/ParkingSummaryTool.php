<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Gdpr\ParkingGate;
use App\Models\ApartmentParkingDeclaration;
use App\Models\Building;
use App\Models\ParkingLot;
use App\Models\ParkingOfficerTask;
use App\Models\Permit;
use App\Models\PlateLookup;
use App\Models\Sighting;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\Parking\Completeness;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('parking_summary')]
#[Description('Parking of one building as counts only: gate level (L0 / L1 / L2) with missing conditions, lots and capacity, permits by type and status, vehicles by status (+ conflicts), declarations, registry completeness per stairwell, sightings, lookups and open officer tasks. Never a plate, never a plate-to-apartment link.')]
#[IsReadOnly]
final class ParkingSummaryTool extends McpTool
{
    public function schema(JsonSchema $schema): array
    {
        return [
            'building' => $schema->string()->description('Building slug (see buildings_list).')->required(),
        ];
    }

    protected function execute(Request $request, User $actor): array
    {
        $building = $this->building($request);

        return $this->inBuilding($building, static function (Building $b): array {
            $gate = ParkingGate::status($b);
            $completeness = app(Completeness::class);

            $permits = Permit::query()->selectRaw('type, status, count(*) AS n')->groupBy('type', 'status')->get()
                ->map(static fn (Permit $p): array => ['type' => $p->getRawOriginal('type'), 'status' => $p->getRawOriginal('status'), 'count' => (int) $p->getAttribute('n')])
                ->values()->all();

            return [
                'building' => $b->slug,
                'gate' => [
                    'level' => $gate->level,
                    'missing_l1' => $gate->missingL1,
                    'missing_l2' => $gate->missingL2,
                    'module_on' => $gate->moduleOn,
                    'enforcement_mode' => $b->settings->parkingEnforcementMode,
                    'rules_adopted_at' => $b->settings->parkingRulesAdoptedAt,
                ],
                'lots' => [
                    'count' => ParkingLot::query()->count(),
                    'capacity_estimate' => (int) ParkingLot::query()->sum('capacity_estimate'),
                ],
                'permits' => $permits,
                'vehicles' => [
                    'by_status' => Vehicle::query()->selectRaw('status, count(*) AS n')->groupBy('status')->pluck('n', 'status')->map(static fn ($n): int => (int) $n)->all(),
                    'conflicts' => Vehicle::query()->where('conflict', true)->whereIn('status', ['pending', 'active'])->count(),
                    'company_cars' => Vehicle::query()->where('is_company_car', true)->where('status', 'active')->count(),
                ],
                'declarations' => [
                    'total' => ApartmentParkingDeclaration::query()->count(),
                    'no_vehicle' => ApartmentParkingDeclaration::query()->where('has_no_vehicle', true)->count(),
                ],
                'completeness' => [
                    'building' => $completeness->forBuilding(),
                    'per_section' => $completeness->perSection(),
                ],
                'sightings' => [
                    'last_30_days' => Sighting::query()->where('seen_at', '>=', now()->subDays(30))->count(),
                    'open' => Sighting::query()->where('status', 'open')->count(),
                ],
                'lookups_last_7_days' => PlateLookup::query()->where('created_at', '>=', now()->subDays(7))->count(),
                'officer_tasks_open' => ParkingOfficerTask::query()->where('status', 'open')->count(),
            ];
        });
    }
}
