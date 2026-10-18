<?php

declare(strict_types=1);

namespace App\Services\Parking;

use RuntimeException;

/**
 * A refused officer/admin parking action with a translatable reason key (`parking.error.<key>` / admin UI).
 * Never raised on resident paths whose answer must stay identical (declarations, sightings).
 */
final class ParkingActionFailed extends RuntimeException
{
    public function __construct(public readonly string $key)
    {
        parent::__construct('Parking action refused: '.$key);
    }

    public function label(): string
    {
        return __('admin.parking.error.'.$this->key);
    }
}
