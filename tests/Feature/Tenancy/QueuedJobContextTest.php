<?php

declare(strict_types=1);

use App\Models\Apartment;
use App\Tenancy\CurrentBuilding;
use App\Tenancy\Jobs\RestoreBuildingContext;
use App\Tenancy\Jobs\TenantAware;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;

final class CountApartmentsJob implements ShouldQueue, TenantAware
{
    use Dispatchable;
    use Queueable;

    public static ?int $seenBuilding = null;

    public static ?int $count = null;

    public function __construct(public int $buildingId) {}

    public function tenantBuildingId(): int
    {
        return $this->buildingId;
    }

    public function middleware(): array
    {
        return [new RestoreBuildingContext];
    }

    public function handle(): void
    {
        self::$seenBuilding = CurrentBuilding::id();
        self::$count = Apartment::query()->count();
    }
}

it('restores the serialized building for TenantAware jobs and clears it afterwards', function (): void {
    $a = makeBuilding('job-a', 1, 1);
    makeBuilding('job-b', 1, 3);

    CountApartmentsJob::dispatch($a->id);

    expect(CountApartmentsJob::$seenBuilding)->toBe($a->id)
        ->and(CountApartmentsJob::$count)->toBe(4)
        ->and(CurrentBuilding::has())->toBeFalse();
});

it('serializes the building id with the job payload', function (): void {
    $job = new CountApartmentsJob(42);

    expect(unserialize(serialize($job))->tenantBuildingId())->toBe(42);
});
