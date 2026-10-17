<?php

declare(strict_types=1);

use App\Models\Apartment;
use App\Models\ApartmentInvite;
use App\Tenancy\CurrentBuilding;
use App\Tenancy\Exceptions\HouseClosed;
use App\Tenancy\InviteResolver;
use Illuminate\Support\Facades\Log;

it('sets the current building to the invite building and returns the DTO', function (): void {
    $a = makeBuilding('ir-a', 1, 2, open: true);
    $b = makeBuilding('ir-b', 1, 2, open: true);
    $code = makeInvite($a, 6);

    $resolved = app(InviteResolver::class)->resolve(strtolower($code));

    expect($resolved)->not->toBeNull()
        ->and($resolved->buildingId)->toBe($a->id)
        ->and($resolved->apartmentNumber)->toBe(6)
        ->and(CurrentBuilding::id())->toBe($a->id)
        // building B stays unreachable afterwards
        ->and(Apartment::query()->whereKey(apartment($b, 6)->id)->exists())->toBeFalse();
});

it('returns the identical null path for unknown, revoked, expired and used-up codes', function (string $case): void {
    $a = makeBuilding('ir-c', 1, 1, open: true);

    $code = match ($case) {
        'unknown' => 'ABCDE23456',
        'revoked' => makeInvite($a, 1, ['revoked_at' => now()]),
        'expired' => makeInvite($a, 1, ['expires_at' => now()->subMinute()]),
        'used up' => makeInvite($a, 1, ['uses' => 3, 'max_uses' => 3]),
        'malformed' => 'not-a-code',
    };

    expect(app(InviteResolver::class)->resolve($code))->toBeNull()
        ->and(CurrentBuilding::has())->toBeFalse();
})->with(['unknown', 'revoked', 'expired', 'used up', 'malformed']);

it('throws HouseClosed for a valid code below gate L-core, leaving no context', function (): void {
    $a = makeBuilding('ir-d', 1, 1); // gate closed
    $code = makeInvite($a, 2);

    try {
        app(InviteResolver::class)->resolve($code);
        $this->fail('expected HouseClosed');
    } catch (HouseClosed) {
        expect(CurrentBuilding::has())->toBeFalse();
    }
});

it('does not resolve codes of archived (absorbed) apartments', function (): void {
    $a = makeBuilding('ir-e', 1, 1, open: true);
    $code = makeInvite($a, 3);
    inBuilding($a, fn () => Apartment::query()->where('number', 3)->update(['archived_at' => now()]));

    expect(app(InviteResolver::class)->resolve($code))->toBeNull();
});

it('never logs or stores the typed code', function (): void {
    Log::spy();
    $a = makeBuilding('ir-f', 1, 1, open: true);
    $code = makeInvite($a, 1);

    app(InviteResolver::class)->resolve($code);

    Log::shouldNotHaveReceived('info');
    Log::shouldNotHaveReceived('debug');
    expect(ApartmentInvite::withoutBuildingScope()->get()->toJson())->not->toContain($code);
});
