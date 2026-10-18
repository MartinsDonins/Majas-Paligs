<?php

declare(strict_types=1);

namespace App\Services\Outages;

use App\Models\Incident;
use Illuminate\Support\Collection;

/**
 * "Jau ziņojuši N dzīvokļi Jūsu stāvadā. Tas nav tikai pie Jums." — joined incidents + the apartment count
 * (largest incident). Counts only.
 */
final readonly class OutageResult
{
    /**
     * @param  Collection<int, Incident>  $incidents
     */
    public function __construct(
        public Collection $incidents,
        public int $apartments,
    ) {}

    public function joinedNotice(): bool
    {
        return $this->incidents->contains(fn (Incident $i): bool => $i->origin->value === 'notice');
    }
}
