<?php

declare(strict_types=1);

namespace App\Casts;

use App\Support\BuildingSettings;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/**
 * Casts buildings.settings (jsonb) to the validated BuildingSettings DTO.
 *
 * @implements CastsAttributes<BuildingSettings, BuildingSettings|array<string, mixed>>
 */
final class AsBuildingSettings implements CastsAttributes
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): BuildingSettings
    {
        $data = is_string($value) ? json_decode($value, true, 512, JSON_THROW_ON_ERROR) : $value;

        return BuildingSettings::fromArray(is_array($data) ? $data : []);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): string
    {
        if (is_array($value)) {
            $value = BuildingSettings::fromArray($value);
        }

        if (! $value instanceof BuildingSettings) {
            throw new InvalidArgumentException('settings must be a BuildingSettings instance or array.');
        }

        return json_encode($value->toArray(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    }
}
