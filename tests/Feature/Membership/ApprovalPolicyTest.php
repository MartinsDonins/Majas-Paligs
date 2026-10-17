<?php

declare(strict_types=1);

use App\Enums\BuildingRoleType;
use App\Enums\MembershipStatus;
use App\Models\BuildingRole;
use Illuminate\Support\Facades\Gate;

beforeEach(function (): void {
    $this->b = makeBuilding('pol-a', 2, 1, open: true);
    $this->elder1 = makeUser('pol-elder1@example.test');
    grantRole($this->elder1, $this->b, BuildingRoleType::SectionElder, 1);
    $this->admin = makeUser('pol-admin@example.test');
    grantRole($this->admin, $this->b, BuildingRoleType::BuildingAdmin);
    $this->resident = makeUser('pol-res@example.test');
    member($this->resident, $this->b, 2);
});

it('lets the elder approve only in their own stairwell', function (): void {
    $own = member(makeUser(), $this->b, 3, MembershipStatus::Pending);    // stairwell 1 (1..4)
    $other = member(makeUser(), $this->b, 7, MembershipStatus::Pending);  // stairwell 2 (5..8)

    inBuilding($this->b, function () use ($own, $other): void {
        expect(Gate::forUser($this->elder1)->allows('approve', $own))->toBeTrue()
            ->and(Gate::forUser($this->elder1)->allows('approve', $other))->toBeFalse()
            ->and(Gate::forUser($this->admin)->allows('approve', $other))->toBeTrue()
            ->and(Gate::forUser($this->resident)->allows('approve', $own))->toBeFalse();
    });
});

it('never lets anyone approve their own membership', function (): void {
    $self = member($this->elder1, $this->b, 4, MembershipStatus::Pending);
    $selfAdmin = member($this->admin, $this->b, 1, MembershipStatus::Pending);

    inBuilding($this->b, function () use ($self, $selfAdmin): void {
        expect(Gate::forUser($this->elder1)->allows('approve', $self))->toBeFalse()
            ->and(Gate::forUser($this->admin)->allows('approve', $selfAdmin))->toBeFalse();
    });
});

it('ignores expired and revoked roles', function (): void {
    $pending = member(makeUser(), $this->b, 2, MembershipStatus::Pending);
    $expired = makeUser('pol-old@example.test');
    $role = grantRole($expired, $this->b, BuildingRoleType::SectionElder, 1);
    inBuilding($this->b, fn () => $role->forceFill(['expires_at' => now()->subDay()])->save());

    expect(inBuilding($this->b, fn () => Gate::forUser($expired)->allows('approve', $pending)))->toBeFalse();
});

it('lets only building_admin grant roles', function (): void {
    inBuilding($this->b, function (): void {
        expect(Gate::forUser($this->admin)->allows('create', BuildingRole::class))->toBeTrue()
            ->and(Gate::forUser($this->elder1)->allows('create', BuildingRole::class))->toBeFalse();
    });
});
