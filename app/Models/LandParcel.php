<?php

declare(strict_types=1);

namespace App\Models;

use App\Tenancy\BelongsToBuilding;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $building_id
 * @property string $cadastre_no
 * @property bool $verified
 */
class LandParcel extends Model
{
    use BelongsToBuilding;

    protected $fillable = ['building_id', 'cadastre_no', 'area_m2', 'verified', 'note'];

    protected function casts(): array
    {
        return ['verified' => 'boolean'];
    }
}
