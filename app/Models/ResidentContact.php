<?php

declare(strict_types=1);

namespace App\Models;

use App\Casts\Bytea;
use App\Casts\ByteaArray;
use App\Enums\ContactRelation;
use App\Enums\ContactSource;
use App\Gdpr\GuardedByRosterGate;
use App\Tenancy\BelongsToBuilding;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One contact of an apartment in the resident roster (ADMIN-007, ROUND2-SPEC §5.1). Name, phones, e-mail and the
 * comment exist only as ContactVault ciphertext (bound to building + table + this ULID + field); phones and the
 * e-mail have per-building blind indexes. Admin + own-stairwell elder only (ResidentContactPolicy), gate L-R enforced
 * by the model guard. Written only through RosterService; never in the building export, manager reports or any
 * resident-facing query.
 *
 * @property string $id
 * @property int $building_id
 * @property int $apartment_id
 * @property ContactRelation $relation
 * @property string|null $name_ciphertext
 * @property string|null $phones_ciphertext
 * @property list<string> $phone_bidx
 * @property string|null $email_ciphertext
 * @property string|null $email_bidx
 * @property bool|null $bis_registered
 * @property bool|null $whatsapp_member
 * @property string|null $comment_ciphertext
 * @property int $key_version
 * @property int|null $user_id
 * @property ContactSource $source
 * @property string|null $import_run_id
 * @property CarbonImmutable|null $reviewed_at
 * @property CarbonImmutable|null $moved_out_at
 * @property int|null $created_by
 * @property int|null $updated_by
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read Apartment $apartment
 */
class ResidentContact extends Model
{
    use BelongsToBuilding;
    use GuardedByRosterGate;
    use HasUlids;

    protected $fillable = [
        'id', 'building_id', 'apartment_id', 'relation', 'name_ciphertext', 'phones_ciphertext', 'phone_bidx',
        'email_ciphertext', 'email_bidx', 'bis_registered', 'whatsapp_member', 'comment_ciphertext', 'key_version',
        'user_id', 'source', 'import_run_id', 'reviewed_at', 'moved_out_at', 'created_by', 'updated_by',
    ];

    /** Ciphertexts and indexes never leave the model in arrays / JSON / Livewire snapshots. */
    protected $hidden = ['name_ciphertext', 'phones_ciphertext', 'phone_bidx', 'email_ciphertext', 'email_bidx', 'comment_ciphertext'];

    protected $attributes = [
        'relation' => 'contact',
        'source' => 'admin',
        'key_version' => 1,
        'phone_bidx' => '{}',
    ];

    protected function casts(): array
    {
        return [
            'relation' => ContactRelation::class,
            'source' => ContactSource::class,
            'name_ciphertext' => Bytea::class,
            'phones_ciphertext' => Bytea::class,
            'email_ciphertext' => Bytea::class,
            'email_bidx' => Bytea::class,
            'comment_ciphertext' => Bytea::class,
            'phone_bidx' => ByteaArray::class,
            'bis_registered' => 'boolean',
            'whatsapp_member' => 'boolean',
            'key_version' => 'integer',
            'reviewed_at' => 'immutable_datetime',
            'moved_out_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Apartment, $this> */
    public function apartment(): BelongsTo
    {
        return $this->belongsTo(Apartment::class);
    }

    /**
     * Contacts still living in the house (not marked "Izvācies").
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeCurrent(Builder $query): Builder
    {
        return $query->whereNull('moved_out_at');
    }

    /**
     * Rows whose e-mail blind index equals $bidx.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeWithEmailBidx(Builder $query, string $bidx): Builder
    {
        return $query->where('email_bidx', Bytea::literal($bidx));
    }

    /**
     * Rows holding a phone with blind index $bidx.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeWithPhoneBidx(Builder $query, string $bidx): Builder
    {
        return $query->whereRaw('phone_bidx @> ARRAY[?::bytea]', [Bytea::literal($bidx)]);
    }

    public function hasPhones(): bool
    {
        return $this->phones_ciphertext !== null;
    }

    public function hasEmail(): bool
    {
        return $this->email_ciphertext !== null;
    }

    public function hasComment(): bool
    {
        return $this->comment_ciphertext !== null;
    }
}
