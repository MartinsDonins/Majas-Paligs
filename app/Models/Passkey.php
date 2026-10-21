<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Spatie\LaravelPasskeys\Models\Passkey as BasePasskey;

/**
 * WebAuthn credential ("ieejas atslēga": fingerprint / Face ID / screen lock) of one account (ADR 0006).
 * A global identity row (no building_id). Holds only public material: credential id, public key, counter.
 *
 * @property int $id
 * @property int $authenticatable_id
 * @property string $name
 * @property string $credential_id
 * @property CarbonImmutable|null $last_used_at
 * @property CarbonImmutable|null $created_at
 */
class Passkey extends BasePasskey
{
    protected $table = 'passkeys';

    public function casts(): array
    {
        return [
            'last_used_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
