<?php

declare(strict_types=1);

namespace App\Tenancy\Exceptions;

use LogicException;

/**
 * Thrown when CurrentBuilding::set() is asked to re-point an already set context to another building.
 */
final class BuildingContextAlreadySet extends LogicException
{
    public static function create(int $current, int $requested): self
    {
        return new self("Building context is already set to #{$current}; refusing to re-point it to #{$requested}.");
    }
}
