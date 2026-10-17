<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\OrganizationKind;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Optional organisation above buildings (biedrība / kopība / manager company).
 *
 * @property int $id
 * @property string $name
 * @property OrganizationKind $kind
 */
class Organization extends Model
{
    protected $fillable = ['name', 'kind', 'reg_no'];

    protected function casts(): array
    {
        return ['kind' => OrganizationKind::class];
    }

    /** @return HasMany<Building, $this> */
    public function buildings(): HasMany
    {
        return $this->hasMany(Building::class);
    }
}
