<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\MembershipRole;
use App\Enums\MembershipStatus;
use App\Gdpr\CoreGate;
use App\Tenancy\BelongsToBuilding;
use App\Tenancy\CurrentBuilding;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Link user ↔ apartment (owner/tenant/family/contact). House data (house = controller).
 * Model guard: no new membership while the building's core gate (L-core) is closed — from any path.
 *
 * @property int $id
 * @property int $building_id
 * @property int $apartment_id
 * @property int $user_id
 * @property MembershipRole $role
 * @property MembershipStatus $status
 * @property string|null $invite_id
 * @property int|null $approved_by
 * @property CarbonImmutable|null $approved_at
 * @property string|null $rejected_reason
 * @property CarbonImmutable|null $ended_at
 * @property CarbonImmutable|null $reconfirm_due_at
 * @property int|null $helper_consent_by_membership_id
 * @property int|null $building_notice_id
 * @property CarbonImmutable $created_at
 * @property-read Apartment $apartment
 * @property-read User $user
 */
class ApartmentMembership extends Model
{
    use BelongsToBuilding;

    protected $fillable = [
        'building_id', 'apartment_id', 'user_id', 'role', 'status', 'invite_id', 'approved_by', 'approved_at',
        'rejected_reason', 'ended_at', 'reconfirm_due_at', 'helper_consent_by_membership_id', 'building_notice_id',
    ];

    protected static function booted(): void
    {
        static::creating(static function (self $membership): void {
            CoreGate::assertOpen(CurrentBuilding::get());
        });
    }

    protected function casts(): array
    {
        return [
            'role' => MembershipRole::class,
            'status' => MembershipStatus::class,
            'approved_at' => 'immutable_datetime',
            'ended_at' => 'immutable_datetime',
            'reconfirm_due_at' => 'immutable_datetime',
        ];
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereIn('status', [MembershipStatus::Pending->value, MembershipStatus::Approved->value]);
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeApproved(Builder $query): Builder
    {
        return $query->where('status', MembershipStatus::Approved->value);
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', MembershipStatus::Pending->value);
    }

    /** @return BelongsTo<Apartment, $this> */
    public function apartment(): BelongsTo
    {
        return $this->belongsTo(Apartment::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isApproved(): bool
    {
        return $this->status === MembershipStatus::Approved;
    }

    public function isPending(): bool
    {
        return $this->status === MembershipStatus::Pending;
    }
}
