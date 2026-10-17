<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\BuildingRoleType;
use App\Enums\Locale;
use App\Enums\MembershipStatus;
use App\Enums\PlatformRole;
use App\Enums\TextSize;
use App\Enums\Theme;
use App\Tenancy\CurrentBuilding;
use App\Tenancy\Exceptions\MissingBuildingContext;
use App\Tenancy\UserScopedQuery;
use Carbon\CarbonImmutable;
use Filament\Models\Contracts\FilamentUser;
use Filament\Models\Contracts\HasDefaultTenant;
use Filament\Models\Contracts\HasName;
use Filament\Models\Contracts\HasTenants;
use Filament\Panel;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Collection;

/**
 * Global platform account (CoreDigify = controller of identity/credential columns, PLANNING §5).
 * Deletion = tombstone (identifiers and credentials nulled, anonymised_at + deleted_at set); audit rows keep
 * pointing at the tombstone.
 *
 * @property int $id
 * @property string|null $email
 * @property CarbonImmutable|null $email_verified_at
 * @property string|null $login_handle
 * @property string|null $pin_hash
 * @property int $pin_failed_count
 * @property CarbonImmutable|null $pin_locked_until
 * @property bool $pin_must_change
 * @property string|null $pin_temp_code_hash
 * @property CarbonImmutable|null $pin_temp_code_expires_at
 * @property Locale $locale
 * @property string|null $display_name
 * @property PlatformRole|null $platform_role
 * @property TextSize $text_size
 * @property Theme $theme
 * @property CarbonImmutable|null $is_adult_confirmed_at
 * @property string|null $notice_version_accepted
 * @property CarbonImmutable|null $last_login_at
 * @property string|null $app_authentication_secret
 * @property CarbonImmutable|null $anonymised_at
 */
class User extends Authenticatable implements FilamentUser, HasDefaultTenant, HasName, HasTenants
{
    use Notifiable;
    use SoftDeletes;

    protected $fillable = [
        'email', 'email_verified_at', 'login_handle', 'locale', 'display_name', 'text_size', 'theme',
        'is_adult_confirmed_at', 'notice_version_accepted',
    ];

    protected $hidden = [
        'pin_hash', 'pin_temp_code_hash', 'remember_token', 'app_authentication_secret',
        'app_authentication_recovery_codes',
    ];

    protected $attributes = [
        'locale' => 'lv',
        'text_size' => 'normal',
        'theme' => 'system',
        'pin_failed_count' => 0,
        'pin_must_change' => false,
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'immutable_datetime',
            'pin_locked_until' => 'immutable_datetime',
            'pin_temp_code_expires_at' => 'immutable_datetime',
            'pin_must_change' => 'boolean',
            'pin_failed_count' => 'integer',
            'locale' => Locale::class,
            'platform_role' => PlatformRole::class,
            'text_size' => TextSize::class,
            'theme' => Theme::class,
            'is_adult_confirmed_at' => 'immutable_datetime',
            'last_login_at' => 'immutable_datetime',
            'anonymised_at' => 'immutable_datetime',
            'app_authentication_secret' => 'encrypted',
            'app_authentication_recovery_codes' => 'encrypted:array',
        ];
    }

    public function isPlatformAdmin(): bool
    {
        return $this->platform_role === PlatformRole::PlatformAdmin && $this->anonymised_at === null;
    }

    public function isTombstone(): bool
    {
        return $this->anonymised_at !== null;
    }

    public function hasTotp(): bool
    {
        return filled($this->app_authentication_secret);
    }

    /**
     * Membership of this user in the CURRENT building (context must be set to $building).
     * Prefers an approved membership over a pending one.
     */
    public function membershipFor(Building $building): ?ApartmentMembership
    {
        $this->assertContext($building);

        return ApartmentMembership::query()
            ->where('user_id', $this->id)
            ->active()
            ->orderByRaw("CASE status WHEN 'approved' THEN 0 ELSE 1 END")
            ->orderBy('id')
            ->first();
    }

    /**
     * Active (not revoked, not expired) roles of this user in the current building.
     *
     * @return EloquentCollection<int, BuildingRole>
     */
    public function rolesIn(Building $building): EloquentCollection
    {
        $this->assertContext($building);

        return BuildingRole::query()->where('user_id', $this->id)->current()->get();
    }

    public function hasRoleIn(Building $building, BuildingRoleType $role, ?int $sectionId = null): bool
    {
        return $this->rolesIn($building)->contains(
            static fn (BuildingRole $r): bool => $r->role === $role && ($sectionId === null || (int) $r->section_id === $sectionId),
        );
    }

    /**
     * Section ids where this user is section_elder in the current building.
     *
     * @return list<int>
     */
    public function elderSectionIds(Building $building): array
    {
        return $this->rolesIn($building)
            ->filter(static fn (BuildingRole $r): bool => $r->role === BuildingRoleType::SectionElder && $r->section_id !== null)
            ->map(static fn (BuildingRole $r): int => (int) $r->section_id)
            ->values()
            ->all();
    }

    public function isBuildingAdmin(Building $building): bool
    {
        return $this->hasRoleIn($building, BuildingRoleType::BuildingAdmin);
    }

    /**
     * Approved apartment membership in the current building (not pending).
     */
    public function approvedMembershipFor(Building $building): ?ApartmentMembership
    {
        $m = $this->membershipFor($building);

        return $m !== null && $m->status === MembershipStatus::Approved ? $m : null;
    }

    private function assertContext(Building $building): void
    {
        if (! CurrentBuilding::has() || CurrentBuilding::id() !== (int) $building->id) {
            throw new MissingBuildingContext('User membership/role lookups require the building context of that building.');
        }
    }

    // ---- Filament (via UserScopedQuery only — no tenant model is touched before the tenant middleware) ----

    public function canAccessPanel(Panel $panel): bool
    {
        if ($this->isTombstone()) {
            return false;
        }

        return match ($panel->getId()) {
            'platform' => $this->isPlatformAdmin(),
            'manage' => UserScopedQuery::for($this)->manageBuildingIds() !== [],
            default => false,
        };
    }

    /**
     * @return Collection<int, Building>
     */
    public function getTenants(Panel $panel): Collection
    {
        if ($panel->getId() !== 'manage') {
            return collect();
        }

        return Building::query()
            ->whereIn('id', UserScopedQuery::for($this)->manageBuildingIds())
            ->orderBy('name')
            ->get();
    }

    public function canAccessTenant(Model $tenant): bool
    {
        return $tenant instanceof Building
            && in_array((int) $tenant->getKey(), UserScopedQuery::for($this)->manageBuildingIds(), true);
    }

    public function getDefaultTenant(Panel $panel): ?Model
    {
        $ids = UserScopedQuery::for($this)->manageBuildingIds();

        return $ids === [] ? null : Building::query()->find($ids[0]);
    }

    public function getFilamentName(): string
    {
        return $this->display_name ?? ($this->email ?? __('common.user'));
    }
}
