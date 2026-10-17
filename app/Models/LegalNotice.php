<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Spatie\Translatable\HasTranslations;

/**
 * Platform legal notice (e.g. "parking.riga.current_rule"), versioned by valid_from.
 *
 * @property int $id
 * @property string $key
 * @property CarbonImmutable $valid_from
 */
class LegalNotice extends Model
{
    use HasTranslations;

    /** @var list<string> */
    public array $translatable = ['body'];

    protected $fillable = ['key', 'jurisdiction', 'body', 'source_url', 'valid_from', 'updated_by'];

    protected function casts(): array
    {
        return ['valid_from' => 'immutable_datetime'];
    }
}
