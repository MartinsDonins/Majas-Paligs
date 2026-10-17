<?php

declare(strict_types=1);

namespace App\Tenancy\Exceptions;

use LogicException;

/**
 * Thrown when a tenant model is queried without a current building (fail-closed tenancy, PLANNING §4.2).
 */
final class MissingBuildingContext extends LogicException
{
    public static function forModel(string $model): self
    {
        return new self("Tenant model [{$model}] used without a current building context.");
    }
}
