<?php

declare(strict_types=1);

use App\Livewire\Parking\SightingSheet;
use App\Models\ParkingLot;
use App\Models\Sighting;
use App\Models\User;
use App\Notifications\LimiterAlert;
use App\Services\Parking\SightingResult;
use App\Services\Parking\SightingService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Livewire;

/*
 * MVP-SPEC C.8 Feature/Parking/SightingQuotaTest (AC 17): 10/day per user, 3/day per plate, 100/day and 50 photos/day
 * per building, 500 MB photo storage — calm message; over the photo caps the report goes through without a photo;
 * building alerts at 50 % / 100 % carry counts only.
 */
beforeEach(function (): void {
    Storage::fake('local');
    $this->b = parkingHouse('quota-house');
    $this->b->forceFill(['settings' => $this->b->settings->with(['lookup_quota' => ['user_hour' => 500, 'user_day' => 500, 'building_day' => 5000]])])->save();
    $this->officer = parkingOfficer($this->b, 'quota-officer@example.test');
    $this->resident = parkingResident($this->b, 1);
    $this->lot = inBuilding($this->b, fn () => ParkingLot::query()->create(['name' => 'Pagalms']));
});

function sight($test, string $plate, ?User $user = null, ?string $photo = null): SightingResult
{
    $user ??= $test->resident;

    return inBuilding($test->b, fn () => app(SightingService::class)->submit($user, apartment($test->b, 1), $test->lot, $plate, null, null, $photo));
}

function smallJpeg(): string
{
    $path = tempnam(sys_get_temp_dir(), 'mpj').'.jpg';
    $img = imagecreatetruecolor(40, 30);
    imagejpeg($img, $path);

    return $path;
}

it('limits one user to 3 reports of the same plate and 10 reports a day', function (): void {
    foreach ([1, 2, 3] as $i) {
        expect(sight($this, 'AB1234')->status)->toBe(SightingResult::SUBMITTED);
    }
    expect(sight($this, 'ab-1234')->status)->toBe(SightingResult::LIMIT);

    foreach (range(1, 7) as $i) {
        expect(sight($this, 'CD000'.$i)->status)->toBe(SightingResult::SUBMITTED);
    }
    expect(sight($this, 'EF0001')->status)->toBe(SightingResult::LIMIT);

    inBuilding($this->b, fn () => Livewire::actingAs($this->resident)->test(SightingSheet::class)
        ->call('submit', 'GH0001')
        ->assertSee(__('parking.sighting.limit')));
});

it('limits the building to 100 a day and alerts officer + admin at 50 % and 100 % (counts only)', function (): void {
    Notification::fake();
    $this->b->forceFill(['settings' => $this->b->settings->with(['sighting_quota' => ['building_day' => 4, 'user_day' => 100, 'user_plate_day' => 100]])])->save();
    $other = parkingResident($this->b, 2);

    foreach (['A1', 'A2', 'A3'] as $p) {
        sight($this, $p);
    }
    sight($this, 'A4', $other);
    expect(sight($this, 'A5')->status)->toBe(SightingResult::LIMIT);

    $alerts = [];
    Notification::assertSentTo($this->officer, LimiterAlert::class, function (LimiterAlert $n) use (&$alerts): bool {
        $alerts[] = $n;

        return $n->limiter === 'sighting-building';
    });
    expect(collect($alerts)->pluck('count')->all())->toBe([2, 4]);
    foreach ($alerts as $n) {
        expect(json_encode($n->toArray($this->officer)))->not->toContain('A1')->not->toContain('apartment');
    }
});

it('accepts the report without a photo over the daily photo cap and the storage cap', function (): void {
    $this->b->forceFill(['settings' => $this->b->settings->with(['sighting_quota' => ['building_photos_day' => 1]])])->save();

    expect(sight($this, 'AB0001', photo: smallJpeg()))->toMatchObject(['status' => SightingResult::SUBMITTED, 'photoDropped' => false]);
    expect(sight($this, 'AB0002', photo: smallJpeg()))->toMatchObject(['status' => SightingResult::SUBMITTED, 'photoDropped' => true]);

    // Storage cap: pretend 500 MB of undeleted photos exist.
    $this->b->forceFill(['settings' => $this->b->settings->with(['sighting_quota' => ['building_photos_day' => 50]])])->save();
    inBuilding($this->b, fn () => DB::table('sightings')->where('building_id', $this->b->id)->whereNotNull('photo_path')->update(['photo_bytes' => 8388608]));
    inBuilding($this->b, function (): void {
        $id = Sighting::query()->whereNotNull('photo_path')->value('id');
        foreach (range(1, 63) as $i) {
            DB::table('sightings')->insert(DB::table('sightings')->where('id', $id)->get()->map(fn ($r) => array_merge((array) $r, [
                'id' => strtolower((string) Str::ulid()), 'created_at' => now()->subDays(2), 'reporter_user_id' => null,
            ]))->all());
        }
    });
    expect(sight($this, 'AB0003', photo: smallJpeg()))->toMatchObject(['status' => SightingResult::SUBMITTED, 'photoDropped' => true]);

    inBuilding($this->b, fn () => Livewire::actingAs($this->resident)->test(SightingSheet::class)
        ->call('submit', 'AB0004')
        ->assertSee(__('parking.sighting.thanks')));
});
