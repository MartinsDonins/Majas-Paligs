<?php

declare(strict_types=1);

use App\Models\ApartmentInvite;
use App\Services\Invites\InvalidInviteCode;
use App\Services\Invites\InviteCodeService;
use Illuminate\Support\Facades\DB;

it('generates 10 characters from the unambiguous alphabet', function (): void {
    $service = app(InviteCodeService::class);

    for ($i = 0; $i < 200; $i++) {
        $code = $service->generate();
        expect($code)->toHaveLength(10)
            ->and(preg_match('/^[23456789ABCDEFGHJKMNPQRSTUVWXYZ]{10}$/', $code))->toBe(1);
    }
});

it('formats as XXXXX-XXXXX', function (): void {
    expect(app(InviteCodeService::class)->format('ABCDE23456'))->toBe('ABCDE-23456');
});

it('normalises case, separators and Cyrillic look-alikes', function (string $typed): void {
    expect(app(InviteCodeService::class)->normalize($typed))->toBe('KMPCX23456');
})->with([
    'lowercase' => 'kmpcx23456',
    'dash' => 'KMPCX-23456',
    'spaces' => ' km pcx 234 56 ',
    'cyrillic К М Р С Х' => 'КМРСХ-23456',
    'cyrillic lowercase' => 'кмрсх23456',
]);

it('rejects ambiguous characters with a hint instead of mapping them', function (string $typed): void {
    try {
        app(InviteCodeService::class)->normalize($typed);
        $this->fail('expected exception');
    } catch (InvalidInviteCode $e) {
        expect($e->reason)->toBe('ambiguous');
    }
})->with(['O' => 'OBCDE23456', 'zero' => 'ABCDE03456', 'I' => 'IBCDE23456', 'one' => 'ABCDE13456', 'L' => 'LBCDE23456']);

it('rejects wrong length and charset', function (): void {
    $s = app(InviteCodeService::class);
    expect(fn () => $s->normalize('ABC'))->toThrow(InvalidInviteCode::class)
        ->and(fn () => $s->normalize('ABCDE2345!'))->toThrow(InvalidInviteCode::class);
});

it('produces a stable HMAC with the dedicated INVITE_KEY', function (): void {
    $s = app(InviteCodeService::class);
    $a = $s->hmac('KMPCX23456');

    expect($a)->toBe($s->hmac('KMPCX23456'))
        ->and($a)->not->toBe($s->hmac('KMPCX23457'))
        ->and($a)->toBe(hash_hmac('sha256', 'invite:v1:KMPCX23456', base64_decode(substr((string) config('majas.keys.invite'), 7))));
});

it('never stores the plaintext code', function (): void {
    $building = makeBuilding();
    $code = makeInvite($building, 5);

    $rows = DB::table('apartment_invites')->get()->map(fn ($r) => json_encode($r))->implode("\n");

    expect($rows)->not->toContain($code)
        ->and($rows)->not->toContain(app(InviteCodeService::class)->format($code))
        ->and(ApartmentInvite::withoutBuildingScope()->count())->toBe(1);
});
