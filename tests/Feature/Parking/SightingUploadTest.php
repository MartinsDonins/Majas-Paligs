<?php

declare(strict_types=1);

use App\Livewire\Parking\SightingSheet;
use App\Models\ParkingLot;
use App\Models\Sighting;
use App\Services\Parking\SightingResult;
use App\Services\Parking\SightingService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

/*
 * MVP-SPEC C.8 Feature/Parking/SightingUploadTest — AC 7 / AC 15: MIME by content (spoofed extension rejected), HEIC
 * rejected with the friendly message, oversize and decompression-bomb pre-checks, re-encode to JPEG ≤ 1600 px with
 * EXIF/GPS stripped, private disk, 5-minute signed URL for officer/admin only.
 */
beforeEach(function (): void {
    Storage::fake('local');
    $this->b = parkingHouse('upload-house');
    $this->officer = parkingOfficer($this->b, 'photo-officer@example.test');
    $this->resident = parkingResident($this->b, 1);
    $this->lot = inBuilding($this->b, fn () => ParkingLot::query()->create(['name' => 'Pagalms']));
    $this->dir = sys_get_temp_dir().'/mp-upload-'.uniqid();
    @mkdir($this->dir);
});

/**
 * A JPEG of $w × $h with an APP1 Exif segment (incl. a fake GPS tag marker) injected after SOI.
 */
function jpegWithExif(string $path, int $w = 2400, int $h = 1800): string
{
    $img = imagecreatetruecolor($w, $h);
    imagefilledrectangle($img, 0, 0, $w, $h, imagecolorallocate($img, 120, 160, 200));
    ob_start();
    imagejpeg($img, null, 80);
    $jpeg = (string) ob_get_clean();

    $tiff = "MM\x00\x2A\x00\x00\x00\x08\x00\x00GPSLatitude-test";
    $app1 = "\xFF\xE1".pack('n', strlen($tiff) + 8)."Exif\x00\x00".$tiff;
    file_put_contents($path, substr($jpeg, 0, 2).$app1.substr($jpeg, 2));

    return $path;
}

function submitSighting($test, ?string $photo): SightingResult
{
    return inBuilding($test->b, fn () => app(SightingService::class)->submit($test->resident, apartment($test->b, 1), $test->lot, 'AB1234', 'LV', null, $photo));
}

it('re-encodes to JPEG ≤ 1600 px, strips EXIF/GPS and stores on the private disk', function (): void {
    $src = jpegWithExif($this->dir.'/in.jpg');
    expect(file_get_contents($src))->toContain('Exif')->toContain('GPSLatitude');

    expect(submitSighting($this, $src)->status)->toBe(SightingResult::SUBMITTED);

    $s = inBuilding($this->b, fn () => Sighting::query()->firstOrFail());
    expect($s->photo_path)->toBe("building/{$this->b->id}/sightings/{$s->id}.jpg");
    Storage::disk('local')->assertExists($s->photo_path);

    $stored = Storage::disk('local')->get($s->photo_path);
    $info = getimagesizefromstring($stored);
    expect($info['mime'])->toBe('image/jpeg')
        ->and(max($info[0], $info[1]))->toBeLessThanOrEqual(1600)
        ->and($stored)->not->toContain('Exif')->not->toContain('GPSLatitude')
        ->and($s->photo_bytes)->toBe(strlen($stored));
});

it('rejects HEIC, spoofed MIME, oversize and decompression bombs', function (): void {
    // HEIC (ftyp heic box) → format.
    file_put_contents($this->dir.'/p.heic', "\x00\x00\x00\x18ftypheic\x00\x00\x00\x00mif1heic".str_repeat("\x00", 64));
    expect(submitSighting($this, $this->dir.'/p.heic'))->toMatchObject(['status' => SightingResult::PHOTO_REJECTED, 'photoReason' => 'format']);

    // A script renamed to .jpg → format (finfo looks at the bytes).
    file_put_contents($this->dir.'/evil.jpg', "<?php echo 'x';\n");
    expect(submitSighting($this, $this->dir.'/evil.jpg')->photoReason)->toBe('format');

    // > 8 MB → size.
    file_put_contents($this->dir.'/big.jpg', "\xFF\xD8\xFF\xE0".random_bytes(8 * 1024 * 1024 + 10));
    expect(submitSighting($this, $this->dir.'/big.jpg')->photoReason)->toBe('size');

    // PNG whose header claims 10000 × 10000 (100 MP) → pixels, rejected BEFORE decoding.
    $img = imagecreatetruecolor(1, 1);
    ob_start();
    imagepng($img);
    $png = (string) ob_get_clean();
    $png = substr($png, 0, 16).pack('N', 10000).pack('N', 10000).substr($png, 24);
    file_put_contents($this->dir.'/bomb.png', $png);
    expect(submitSighting($this, $this->dir.'/bomb.png')->photoReason)->toBe('pixels');

    // A rejected photo stores nothing (the sheet asks for a camera photo instead).
    expect(inBuilding($this->b, fn () => Sighting::query()->count()))->toBe(0);
});

it('shows the friendly HEIC message in the sheet and accepts a client-converted JPEG upload', function (): void {
    $heic = UploadedFile::fake()->createWithContent('IMG_0001.HEIC', "\x00\x00\x00\x18ftypheic\x00\x00\x00\x00mif1heic".str_repeat("\x00", 64));

    inBuilding($this->b, fn () => Livewire::actingAs($this->resident)->test(SightingSheet::class)
        ->set('photo', $heic)
        ->call('submit', 'AB1234')
        ->assertSet('state', SightingResult::PHOTO_REJECTED)
        ->assertSee(__('parking.sighting.photo_error.format')));

    $jpeg = UploadedFile::fake()->createWithContent('photo.jpg', (string) file_get_contents(jpegWithExif($this->dir.'/ok.jpg', 800, 600)));
    inBuilding($this->b, fn () => Livewire::actingAs($this->resident)->test(SightingSheet::class)
        ->set('photo', $jpeg)
        ->call('submit', 'AB1234')
        ->assertSet('state', SightingResult::SUBMITTED));

    expect(inBuilding($this->b, fn () => Sighting::query()->whereNotNull('photo_path')->count()))->toBe(1);
});

it('serves the photo only to officer/admin through a 5-minute signed URL', function (): void {
    submitSighting($this, jpegWithExif($this->dir.'/in.jpg', 600, 400));
    $s = inBuilding($this->b, fn () => Sighting::query()->firstOrFail());
    $url = inBuilding($this->b, fn () => SightingService::photoUrl($s));

    steppedUp($this->officer);
    $this->actingAs($this->officer)->get($url)->assertOk()->assertHeader('Content-Type', 'image/jpeg')->assertHeader('Cache-Control', 'no-store, private');

    steppedUp($this->resident);
    $this->actingAs($this->resident)->get($url)->assertForbidden();

    $this->travel(6)->minutes();
    steppedUp($this->officer);
    $this->actingAs($this->officer)->get($url)->assertForbidden();
});
