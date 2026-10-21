<?php

declare(strict_types=1);

namespace App\Models;

use App\Casts\Bytea;
use App\Casts\PgArray;
use App\Enums\BuildingRoleType;
use App\Enums\Locale;
use App\Tenancy\BelongsToBuilding;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * E-mail invitation to a building role (ROUND2-SPEC §2.1, ADMIN-006). The e-mail is stored encrypted (APP_KEY,
 * building_admin only), as an HMAC (matching) and as a masked hint (lists, other admins, audit rendering). The token
 * exists only in the mail; the row keeps its SHA-256. Written only through TeamInvitationService.
 *
 * @property string $id
 * @property int $building_id
 * @property string $email_ciphertext
 * @property string $email_hmac
 * @property string $email_hint
 * @property BuildingRoleType $role
 * @property list<int> $section_ids
 * @property string|null $mandate_ref
 * @property Locale $locale
 * @property string $token_hash
 * @property int|null $invited_by
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $expires_at
 * @property CarbonImmutable|null $accepted_at
 * @property int|null $accepted_user_id
 * @property CarbonImmutable|null $revoked_at
 * @property int|null $revoked_by
 * @property CarbonImmutable|null $resent_at
 * @property int $resend_count
 */
class RoleInvitation extends Model
{
    use BelongsToBuilding;
    use HasUlids;

    protected $fillable = [
        'building_id', 'email_ciphertext', 'email_hmac', 'email_hint', 'role', 'section_ids', 'mandate_ref', 'locale',
        'token_hash', 'invited_by', 'expires_at', 'accepted_at', 'accepted_user_id', 'revoked_at', 'revoked_by',
        'resent_at', 'resend_count',
    ];

    protected $hidden = ['email_ciphertext', 'email_hmac', 'token_hash'];

    protected function casts(): array
    {
        return [
            'email_ciphertext' => 'encrypted',
            'email_hmac' => Bytea::class,
            'token_hash' => Bytea::class,
            'role' => BuildingRoleType::class,
            'section_ids' => PgArray::class.':int',
            'locale' => Locale::class,
            'expires_at' => 'immutable_datetime',
            'accepted_at' => 'immutable_datetime',
            'revoked_at' => 'immutable_datetime',
            'resent_at' => 'immutable_datetime',
            'resend_count' => 'integer',
        ];
    }

    /**
     * Neither accepted nor revoked (may still be expired).
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereNull('accepted_at')->whereNull('revoked_at');
    }

    /**
     * Open and not expired — the ones listed under "Gaida apstiprinājumu".
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopePending(Builder $query): Builder
    {
        return $query->open()->where('expires_at', '>', now());
    }

    public function isUsable(): bool
    {
        return $this->accepted_at === null && $this->revoked_at === null && $this->expires_at->isFuture();
    }

    public function email(): string
    {
        return (string) $this->email_ciphertext;
    }

    /**
     * "m•••a@g•••.com" — enough to recognise one's own address, never the full one.
     */
    public static function hint(string $email): string
    {
        $email = mb_strtolower(trim($email));
        [$local, $domain] = array_pad(explode('@', $email, 2), 2, '');
        $dot = mb_strrpos($domain, '.');
        $host = $dot === false ? $domain : mb_substr($domain, 0, $dot);
        $tld = $dot === false ? '' : mb_substr($domain, $dot);

        $mask = static fn (string $s): string => $s === '' ? '' : (mb_strlen($s) <= 2 ? mb_substr($s, 0, 1).'•••' : mb_substr($s, 0, 1).'•••'.mb_substr($s, -1));

        return $mask($local).'@'.($host !== '' ? mb_substr($host, 0, 1).'•••' : '').$tld;
    }
}
