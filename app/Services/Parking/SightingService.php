<?php

declare(strict_types=1);

namespace App\Services\Parking;

use App\Audit\AuditLogger;
use App\Casts\Bytea;
use App\Enums\BuildingRoleType;
use App\Enums\LookupReason;
use App\Enums\SightingStatus;
use App\Models\Apartment;
use App\Models\ParkingLot;
use App\Models\Sighting;
use App\Models\User;
use App\Notifications\Alerts;
use App\Tenancy\CurrentBuilding;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

/**
 * Unknown-car reports (PARK-003, MVP-SPEC C.5). Order of checks: plate → sighting quotas (settings.sighting_quota:
 * 10/day per user, 3/day per plate per user, 100/day per building) → photo hardening (only while the building has
 * photo capacity: 50 photos/day, 500 MB stored) → ONE unit of the shared lookup quota (a sighting is a lookup and
 * is logged as one) → store. The reporter never learns `matched_registered` or the repeat flag.
 * Alerts (counts only) at 50 % and 100 % of the building quotas → parking_officer + building_admin.
 */
final class SightingService
{
    public const PHOTO_RETENTION_DAYS = 30;

    public const RECORD_RETENTION_MONTHS = 12;

    public function __construct(
        private readonly PlateVault $vault,
        private readonly LookupQuota $quota,
        private readonly SightingPhotoProcessor $photos,
        private readonly SightingStats $stats,
        private readonly PlateLookupService $lookups,
        private readonly Alerts $alerts,
    ) {}

    public function submit(
        User $user,
        Apartment $reporterApartment,
        ParkingLot $lot,
        string $typedPlate,
        ?string $country = null,
        ?CarbonImmutable $seenAt = null,
        ?string $photoPath = null,
    ): SightingResult {
        $plate = PlateNormalizer::normalize($typedPlate);
        if ($plate === null) {
            return new SightingResult(SightingResult::INVALID);
        }

        $building = CurrentBuilding::get();
        $q = $building->settings->sightingQuota;
        $bidx = $this->vault->currentBidx($building, $plate);
        $dayAgo = now()->subDay();

        $mineToday = Sighting::query()->where('reporter_user_id', $user->id)->where('created_at', '>', $dayAgo);
        $buildingToday = Sighting::query()->where('created_at', '>', $dayAgo)->count();

        if ((clone $mineToday)->count() >= $q['user_day']
            || (clone $mineToday)->where('plate_bidx', Bytea::literal($bidx))->count() >= $q['user_plate_day']
            || $buildingToday >= $q['building_day']) {
            return new SightingResult(SightingResult::LIMIT);
        }

        // Photo: only while the building has capacity; over the cap the report is still accepted without it.
        $jpeg = null;
        $photoDropped = false;
        if ($photoPath !== null) {
            $photosToday = Sighting::query()->where('created_at', '>', $dayAgo)->whereNotNull('photo_path')->count();
            $storedBytes = (int) Sighting::query()->whereNotNull('photo_path')->whereNull('photo_deleted_at')->sum('photo_bytes');

            if ($photosToday >= $q['building_photos_day'] || $storedBytes >= $q['building_photo_mb'] * 1024 * 1024) {
                $photoDropped = true;
            } else {
                try {
                    $jpeg = $this->photos->process($photoPath);
                } catch (PhotoRejected $e) {
                    return new SightingResult(SightingResult::PHOTO_REJECTED, photoReason: $e->reason);
                }
            }
        }

        $matched = $this->lookups->isRegistered($bidx);
        $retry = $this->quota->record($user, $bidx, LookupReason::Sighting, $matched);
        if ($retry !== null) {
            return new SightingResult(SightingResult::RATE_LIMITED, retryAfter: $retry);
        }

        $seen = $this->clampSeenAt($seenAt);
        $id = strtolower((string) Str::ulid());
        $sealed = $this->vault->seal($plate, new VaultContext((int) $building->id, 'sightings', $id), $building->settings->plateIndexKeyVersion);
        $path = $jpeg !== null ? "building/{$building->id}/sightings/{$id}.jpg" : null;

        DB::transaction(function () use ($id, $lot, $sealed, $country, $seen, $user, $reporterApartment, $matched, $path, $jpeg): void {
            $sighting = Sighting::query()->create([
                'id' => $id,
                'parking_lot_id' => $lot->id,
                'plate_ciphertext' => $sealed->ciphertext,
                'plate_bidx' => $sealed->bidx,
                'plate_key_version' => $sealed->keyVersion,
                'country' => PlateNormalizer::country($country),
                'seen_at' => $seen,
                'reporter_user_id' => $user->id,
                'reporter_apartment_id' => $reporterApartment->id,
                'matched_registered' => $matched,
                'photo_path' => $path,
                'photo_bytes' => $jpeg !== null ? strlen($jpeg) : null,
                'status' => SightingStatus::Open,
            ]);

            if ($path !== null && $jpeg !== null) {
                self::disk()->put($path, $jpeg);
            }

            AuditLogger::record('sighting.create', $sighting, data: ['photo' => $path !== null]);
        });

        $this->stats->refresh($sealed->bidx);
        $this->alertOnQuota($buildingToday + 1, $q['building_day'], 'sighting-building');
        if ($path !== null) {
            $photos = Sighting::query()->where('created_at', '>', $dayAgo)->whereNotNull('photo_path')->count();
            $this->alertOnQuota($photos, $q['building_photos_day'], 'sighting-photos');
        }

        return new SightingResult(SightingResult::SUBMITTED, photoDropped: $photoDropped);
    }

    /**
     * "Esmu viesis / tas ir mūsu viesis": open sightings of that plate in the last 30 days become `disputed` for the
     * officer. The answer is identical whether or not such sightings exist (it consumes the lookup quota).
     */
    public function dispute(User $user, string $typedPlate): LookupResult
    {
        $plate = PlateNormalizer::normalize($typedPlate);
        if ($plate === null) {
            return LookupResult::invalid();
        }

        $bidx = $this->vault->currentBidx(CurrentBuilding::get(), $plate);
        $retry = $this->quota->record($user, $bidx, LookupReason::OwnCheck, $this->lookups->isRegistered($bidx));
        if ($retry !== null) {
            return LookupResult::limited($retry);
        }

        $count = Sighting::query()
            ->where('plate_bidx', Bytea::literal($bidx))
            ->where('status', SightingStatus::Open->value)
            ->where('seen_at', '>', now()->subDays(30))
            ->update(['status' => SightingStatus::Disputed->value]);

        AuditLogger::record('sighting.dispute', null, [(int) $user->id], data: ['count' => $count]);

        return new LookupResult(LookupResult::OK);
    }

    /**
     * Officer/admin: dismiss or resolve.
     */
    public function handle(User $officer, Sighting $sighting, SightingStatus $status): void
    {
        if (! in_array($status, [SightingStatus::Dismissed, SightingStatus::Resolved], true)) {
            throw new ParkingActionFailed('invalid_status');
        }

        $sighting->forceFill(['status' => $status, 'handled_by' => $officer->id, 'handled_at' => now()])->save();
        AuditLogger::record('sighting.handle', $sighting, data: ['status' => $status->value]);
        $this->stats->refresh($sighting->plate_bidx);
    }

    public function plateOf(Sighting $sighting): string
    {
        return $this->vault->open(
            $sighting->plate_ciphertext,
            new VaultContext((int) $sighting->building_id, 'sightings', (string) $sighting->id),
            $sighting->plate_key_version,
        );
    }

    /**
     * 5-minute signed photo URL (officer/admin; the controller checks SightingPolicy::photo again).
     */
    public static function photoUrl(Sighting $sighting): string
    {
        return URL::temporarySignedRoute('building.sighting-photo', now()->addMinutes(5), [
            'building' => CurrentBuilding::get()->slug,
            'sighting' => $sighting->id,
        ]);
    }

    public static function disk(): Filesystem
    {
        return Storage::disk((string) config('majas.parking.photo_disk', 'local'));
    }

    private function clampSeenAt(?CarbonImmutable $seenAt): CarbonImmutable
    {
        $now = CarbonImmutable::now();

        if ($seenAt === null || $seenAt->greaterThan($now->addMinutes(5)) || $seenAt->lessThan($now->subDay())) {
            return $now;
        }

        return $seenAt;
    }

    private function alertOnQuota(int $count, int $limit, string $limiter): void
    {
        if ($limit <= 0) {
            return;
        }

        if ($count === (int) ceil($limit / 2) || $count === $limit) {
            $this->alerts->building(CurrentBuilding::get(), $limiter, 'day', $count, $limit, [
                BuildingRoleType::ParkingOfficer->value, BuildingRoleType::BuildingAdmin->value,
            ]);
        }
    }
}
