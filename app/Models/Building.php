<?php

declare(strict_types=1);

namespace App\Models;

use App\Casts\AsBuildingSettings;
use App\Enums\BuildingStatus;
use App\Support\BuildingSettings;
use Filament\Models\Contracts\HasName;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * A building (the tenant). NOT a tenant model itself — no BuildingScope — so route-model binding
 * `{building:slug}` and Filament tenant resolution can load it without a context. What a user may do with
 * it is decided by policies (PLANNING §4.2). Its tenant relations still go through BuildingScope.
 *
 * @property int $id
 * @property int|null $organization_id
 * @property string $slug
 * @property string $name
 * @property string $address_line
 * @property string $address_search
 * @property string|null $postcode
 * @property string $city
 * @property string|null $address_code
 * @property string|null $cadastre_designation
 * @property int|null $series_id
 * @property int $floors
 * @property int|null $year_built
 * @property string|null $manager_name
 * @property string|null $manager_emergency_phone
 * @property string $default_locale
 * @property BuildingSettings $settings
 * @property BuildingStatus $status
 */
class Building extends Model implements HasName
{
    protected $fillable = [
        'organization_id', 'slug', 'name', 'address_line', 'address_search', 'postcode', 'city',
        'address_code', 'cadastre_designation', 'series_id', 'floors', 'year_built', 'manager_name',
        'manager_emergency_phone', 'default_locale', 'settings', 'status',
    ];

    protected $attributes = [
        'settings' => '{}',
        'status' => 'draft',
        'default_locale' => 'lv',
    ];

    protected static function booted(): void
    {
        static::saving(static function (self $building): void {
            $building->address_search = self::normalizeAddress($building->address_line.' '.$building->city);
        });
    }

    protected function casts(): array
    {
        return [
            'settings' => AsBuildingSettings::class,
            'status' => BuildingStatus::class,
            'floors' => 'integer',
            'year_built' => 'integer',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    public function getFilamentName(): string
    {
        return $this->name;
    }

    /**
     * Lowercase, unaccented, single-spaced: "Pūces iela 51" → "puces iela 51".
     */
    public static function normalizeAddress(string $value): string
    {
        $ascii = Str::lower(Str::ascii($value));
        $ascii = (string) preg_replace('/[^a-z0-9]+/', ' ', $ascii);

        return trim($ascii);
    }

    /** @return BelongsTo<BuildingSeries, $this> */
    public function series(): BelongsTo
    {
        return $this->belongsTo(BuildingSeries::class, 'series_id');
    }

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** @return HasMany<Section, $this> */
    public function sections(): HasMany
    {
        return $this->hasMany(Section::class)->orderBy('number');
    }

    /** @return HasMany<Apartment, $this> */
    public function apartments(): HasMany
    {
        return $this->hasMany(Apartment::class);
    }

    /** @return HasMany<Riser, $this> */
    public function risers(): HasMany
    {
        return $this->hasMany(Riser::class);
    }

    /** @return HasMany<LandParcel, $this> */
    public function landParcels(): HasMany
    {
        return $this->hasMany(LandParcel::class);
    }

    /** @return HasMany<BuildingPrivacyNotice, $this> */
    public function privacyNotices(): HasMany
    {
        return $this->hasMany(BuildingPrivacyNotice::class);
    }

    /** @return HasMany<ApartmentMembership, $this> */
    public function memberships(): HasMany
    {
        return $this->hasMany(ApartmentMembership::class);
    }

    /** @return HasMany<BuildingRole, $this> */
    public function roles(): HasMany
    {
        return $this->hasMany(BuildingRole::class);
    }

    /**
     * Short label for the building switcher pill: "Pūces 51".
     */
    public function shortLabel(): string
    {
        return (string) preg_replace('/\s+iela\s+/iu', ' ', $this->address_line);
    }
}
