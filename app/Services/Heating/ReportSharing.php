<?php

declare(strict_types=1);

namespace App\Services\Heating;

use App\Support\BuildingSettings;
use InvalidArgumentException;

/**
 * What a manager report may contain beyond aggregates (PLANNING §4.6): per-floor data only with
 * `heating_floors`; apartment numbers only with `apartment_numbers`, which REQUIRES heating_floors.
 * Both default off; the house decides (settings.share_with_manager).
 */
final readonly class ReportSharing
{
    public function __construct(
        public bool $heatingFloors = false,
        public bool $apartmentNumbers = false,
    ) {
        if ($this->apartmentNumbers && ! $this->heatingFloors) {
            throw new InvalidArgumentException('apartment_numbers requires heating_floors.');
        }
    }

    public static function fromSettings(BuildingSettings $settings): self
    {
        return new self(
            heatingFloors: $settings->shareWithManager['heating_floors'],
            apartmentNumbers: $settings->shareWithManager['apartment_numbers'],
        );
    }

    /**
     * @return array{heating_floors: bool, apartment_numbers: bool}
     */
    public function toArray(): array
    {
        return ['heating_floors' => $this->heatingFloors, 'apartment_numbers' => $this->apartmentNumbers];
    }
}
