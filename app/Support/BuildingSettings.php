<?php

declare(strict_types=1);

namespace App\Support;

use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * Validated DTO for buildings.settings (MVP-SPEC A.4). Unknown keys are dropped, missing keys get defaults.
 * Gate inputs (controller, DPA, DPIA, mandate) live here; CoreGate / ParkingGate are pure functions of
 * this DTO plus building_privacy_notices.
 */
final readonly class BuildingSettings
{
    public const MODULES = ['heating', 'outages', 'parking'];

    public const DPA_SCOPES = ['core', 'heating', 'outages', 'parking'];

    public const ENFORCEMENT_MODES = ['visible_permit', 'plate_list', 'both'];

    /**
     * @param  array{heating: bool, outages: bool, parking: bool}  $modules
     * @param  array{name: ?string, contact: ?string, basis_ref: ?string}  $controller
     * @param  list<string>  $dpaScope
     * @param  array{user_day: int, user_plate_day: int, building_day: int, building_photos_day: int, building_photo_mb: int}  $sightingQuota
     * @param  array{heating_floors: bool, apartment_numbers: bool, decided_ref: ?string}  $shareWithManager
     * @param  array{user_hour: int, user_day: int, building_day: int}  $lookupQuota
     * @param  array{building_hour: int}  $pinLimits
     */
    public function __construct(
        public array $modules = ['heating' => false, 'outages' => false, 'parking' => false],
        public string $parkingEnforcementMode = 'visible_permit',
        public ?string $parkingRulesAdoptedAt = null,
        public ?string $parkingAmnestyUntil = null,
        public array $controller = ['name' => null, 'contact' => null, 'basis_ref' => null],
        public ?string $dpaSignedAt = null,
        public ?string $dpaRef = null,
        public array $dpaScope = [],
        public ?string $dpiaCompletedAt = null,
        public ?string $mandateRef = null,
        public array $sightingQuota = ['user_day' => 10, 'user_plate_day' => 3, 'building_day' => 100, 'building_photos_day' => 50, 'building_photo_mb' => 500],
        public array $shareWithManager = ['heating_floors' => false, 'apartment_numbers' => false, 'decided_ref' => null],
        public int $adhocReportValidDays = 7,
        public int $confirmMinApartments = 2,
        public array $lookupQuota = ['user_hour' => 5, 'user_day' => 20, 'building_day' => 200],
        public array $pinLimits = ['building_hour' => 100],
        public ?string $pinPausedUntil = null,
        public int $plateIndexKeyVersion = 1,
    ) {
        if (! in_array($this->parkingEnforcementMode, self::ENFORCEMENT_MODES, true)) {
            throw new InvalidArgumentException('Invalid parking_enforcement_mode.');
        }

        foreach ($this->dpaScope as $scope) {
            if (! in_array($scope, self::DPA_SCOPES, true)) {
                throw new InvalidArgumentException("Invalid dpa_scope entry [{$scope}].");
            }
        }

        if ($this->shareWithManager['apartment_numbers'] && ! $this->shareWithManager['heating_floors']) {
            throw new InvalidArgumentException('share_with_manager.apartment_numbers requires heating_floors.');
        }

        if ($this->confirmMinApartments < 2) {
            throw new InvalidArgumentException('confirm_min_apartments must be at least 2.');
        }
    }

    /**
     * @param  array<string, mixed>|null  $data
     */
    public static function fromArray(?array $data): self
    {
        $data ??= [];
        $d = new self;

        $modules = $d->modules;
        foreach (self::MODULES as $m) {
            $modules[$m] = (bool) ($data['modules'][$m] ?? false);
        }

        $controller = [
            'name' => self::str($data['controller']['name'] ?? null),
            'contact' => self::str($data['controller']['contact'] ?? null),
            'basis_ref' => self::str($data['controller']['basis_ref'] ?? null),
        ];

        $share = [
            'heating_floors' => (bool) ($data['share_with_manager']['heating_floors'] ?? false),
            'apartment_numbers' => (bool) ($data['share_with_manager']['apartment_numbers'] ?? false),
            'decided_ref' => self::str($data['share_with_manager']['decided_ref'] ?? null),
        ];

        $sighting = $d->sightingQuota;
        foreach (array_keys($sighting) as $k) {
            if (isset($data['sighting_quota'][$k])) {
                $sighting[$k] = max(0, (int) $data['sighting_quota'][$k]);
            }
        }

        $lookup = $d->lookupQuota;
        foreach (array_keys($lookup) as $k) {
            if (isset($data['lookup_quota'][$k])) {
                $lookup[$k] = max(0, (int) $data['lookup_quota'][$k]);
            }
        }

        $scope = array_values(array_unique(array_map('strval', (array) ($data['dpa_scope'] ?? []))));
        sort($scope);

        return new self(
            modules: $modules,
            parkingEnforcementMode: (string) ($data['parking_enforcement_mode'] ?? 'visible_permit'),
            parkingRulesAdoptedAt: self::str($data['parking_rules_adopted_at'] ?? null),
            parkingAmnestyUntil: self::str($data['parking_amnesty_until'] ?? null),
            controller: $controller,
            dpaSignedAt: self::str($data['dpa_signed_at'] ?? null),
            dpaRef: self::str($data['dpa_ref'] ?? null),
            dpaScope: $scope,
            dpiaCompletedAt: self::str($data['dpia_completed_at'] ?? null),
            mandateRef: self::str($data['mandate_ref'] ?? null),
            sightingQuota: $sighting,
            shareWithManager: $share,
            adhocReportValidDays: max(1, (int) ($data['adhoc_report_valid_days'] ?? 7)),
            confirmMinApartments: max(2, (int) ($data['confirm_min_apartments'] ?? 2)),
            lookupQuota: $lookup,
            pinLimits: ['building_hour' => max(1, (int) ($data['pin_limits']['building_hour'] ?? 100))],
            pinPausedUntil: self::str($data['pin_paused_until'] ?? null),
            plateIndexKeyVersion: max(1, (int) ($data['plate_index_key_version'] ?? 1)),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'modules' => $this->modules,
            'parking_enforcement_mode' => $this->parkingEnforcementMode,
            'parking_rules_adopted_at' => $this->parkingRulesAdoptedAt,
            'parking_amnesty_until' => $this->parkingAmnestyUntil,
            'controller' => $this->controller,
            'dpa_signed_at' => $this->dpaSignedAt,
            'dpa_ref' => $this->dpaRef,
            'dpa_scope' => $this->dpaScope,
            'dpia_completed_at' => $this->dpiaCompletedAt,
            'mandate_ref' => $this->mandateRef,
            'sighting_quota' => $this->sightingQuota,
            'share_with_manager' => $this->shareWithManager,
            'adhoc_report_valid_days' => $this->adhocReportValidDays,
            'confirm_min_apartments' => $this->confirmMinApartments,
            'lookup_quota' => $this->lookupQuota,
            'pin_limits' => $this->pinLimits,
            'pin_paused_until' => $this->pinPausedUntil,
            'plate_index_key_version' => $this->plateIndexKeyVersion,
        ];
    }

    /**
     * Return a copy with snake_case keys replaced (nested arrays are merged one level deep).
     *
     * @param  array<string, mixed>  $changes
     */
    public function with(array $changes): self
    {
        $data = $this->toArray();

        foreach ($changes as $key => $value) {
            if (is_array($value) && isset($data[$key]) && is_array($data[$key]) && ! array_is_list($value)) {
                $data[$key] = array_merge($data[$key], $value);
            } else {
                $data[$key] = $value;
            }
        }

        return self::fromArray($data);
    }

    public function moduleEnabled(string $module): bool
    {
        return (bool) ($this->modules[$module] ?? false);
    }

    public function pinPaused(?CarbonImmutable $now = null): bool
    {
        if ($this->pinPausedUntil === null) {
            return false;
        }

        return CarbonImmutable::parse($this->pinPausedUntil)->isAfter($now ?? CarbonImmutable::now());
    }

    private static function str(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
