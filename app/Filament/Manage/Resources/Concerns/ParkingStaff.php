<?php

declare(strict_types=1);

namespace App\Filament\Manage\Resources\Concerns;

use App\Models\Apartment;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\Parking\PlateNormalizer;
use App\Services\Parking\VehicleRegistry;
use Throwable;

/**
 * Helpers for the parking staff resources: acting user, apartment options (tenant-scoped, active apartments only)
 * and MASKED plates ("AB••34") — a full plate is shown only through the audited Reveal action.
 */
trait ParkingStaff
{
    protected static function actor(): User
    {
        /** @var User $user */
        $user = auth()->user();

        return $user;
    }

    /**
     * @return array<int, string>
     */
    protected static function apartmentOptions(): array
    {
        return Apartment::query()->active()->orderBy('number')->pluck('number', 'id')
            ->map(fn ($n): string => __('admin.apartment.short', ['n' => $n]))->all();
    }

    protected static function maskedPlate(?Vehicle $vehicle): string
    {
        if ($vehicle === null) {
            return '—';
        }

        try {
            return PlateNormalizer::mask(app(VehicleRegistry::class)->plateOf($vehicle));
        } catch (Throwable) {
            return '••••';
        }
    }
}
