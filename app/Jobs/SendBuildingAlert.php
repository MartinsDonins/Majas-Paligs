<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\BuildingRoleType;
use App\Models\BuildingRole;
use App\Models\User;
use App\Notifications\LimiterAlert;
use App\Tenancy\CurrentBuilding;
use App\Tenancy\Jobs\RestoreBuildingContext;
use App\Tenancy\Jobs\TenantAware;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Notification;

/**
 * Count-only alert to a building's elders and building_admin (runs inside that building's context).
 */
final class SendBuildingAlert implements ShouldQueue, TenantAware
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public function __construct(
        public readonly int $buildingId,
        public readonly string $limiter,
        public readonly string $window,
        public readonly int $count,
        public readonly int $threshold,
    ) {}

    public function tenantBuildingId(): int
    {
        return $this->buildingId;
    }

    /**
     * @return list<object>
     */
    public function middleware(): array
    {
        return [new RestoreBuildingContext];
    }

    public function handle(): void
    {
        $building = CurrentBuilding::get();

        $ids = BuildingRole::query()
            ->current()
            ->whereIn('role', [BuildingRoleType::BuildingAdmin->value, BuildingRoleType::SectionElder->value])
            ->pluck('user_id');

        $recipients = User::query()->whereIn('id', $ids)->whereNotNull('email')->whereNull('anonymised_at')->get();

        Notification::send($recipients, new LimiterAlert($building->name, $this->limiter, $this->window, $this->count, $this->threshold));
    }
}
