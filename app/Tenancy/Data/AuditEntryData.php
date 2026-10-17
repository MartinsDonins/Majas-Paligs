<?php

declare(strict_types=1);

namespace App\Tenancy\Data;

use Carbon\CarbonImmutable;

/**
 * An audit entry in which the bound user is a subject ("Kas skatīja manus datus"). Actor is shown by role only.
 */
final readonly class AuditEntryData
{
    public function __construct(
        public int $id,
        public ?int $buildingId,
        public ?string $buildingName,
        public string $action,
        public ?string $actorRole,
        public bool $actorDeleted,
        public ?string $reasonCode,
        public CarbonImmutable $createdAt,
    ) {}
}
