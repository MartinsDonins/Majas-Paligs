<?php

declare(strict_types=1);

namespace App\Filament\Manage\Resources\Concerns;

use App\Models\Apartment;
use App\Models\User;
use App\Models\Vehicle;

/**
 * Helpers for the parking staff resources: acting user, apartment options (tenant-scoped, active apartments only)
 * and vehicle ALIASES. Review fix (drošība): lists never decrypt a plate and never show a plate fragment — the old
 * "AB••34" mask next to the apartment number left ~100 candidates for a 6-character plate, a plate ↔ apartment link
 * without reason, cap, audit or notice. The alias ("Auto #7Q2K") is derived from the random part of the vehicle's
 * ULID, so it says nothing about the plate. RevealService (revealVehicle / findByPlate) stays the ONLY route to a
 * plate ↔ apartment link.
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

    protected static function vehicleAlias(?Vehicle $vehicle): string
    {
        if ($vehicle === null) {
            return '—';
        }

        return self::alias((string) $vehicle->id).' · '.$vehicle->country;
    }

    /**
     * Short per-row alias from the random (last) characters of a ULID — never derived from plate data.
     */
    public static function alias(string $ulid): string
    {
        return __('admin.parking.vehicle.alias', ['ref' => strtoupper(substr($ulid, -4))]);
    }
}
