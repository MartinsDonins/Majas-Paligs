<?php

declare(strict_types=1);

namespace App\Services\Buildings;

use App\Audit\AuditLogger;
use App\Models\Apartment;
use App\Models\ApartmentInvite;
use App\Models\ApartmentMembership;
use App\Models\Room;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Per-building override: two neighbouring cells → one apartment (MVP-SPEC A.4 apartment_cells). The absorbed
 * cell(s), rooms and riser links move to the primary apartment; the absorbed number is appended to
 * retired_numbers, its invites are revoked; the absorbed row is deleted if it has no memberships, otherwise
 * archived. The series template is never touched.
 */
final class ApartmentMerger
{
    public function merge(Apartment $primary, Apartment $absorbed, ?string $note = null): Apartment
    {
        if ($primary->id === $absorbed->id) {
            throw new InvalidArgumentException('Cannot merge an apartment with itself.');
        }

        if ($primary->section_id !== $absorbed->section_id) {
            throw new InvalidArgumentException('Merged apartments must be in the same stairwell.');
        }

        if ($primary->isArchived() || $absorbed->isArchived()) {
            throw new InvalidArgumentException('Archived apartments cannot be merged.');
        }

        return DB::transaction(function () use ($primary, $absorbed, $note): Apartment {
            // 1. Cells → primary (non-primary cells).
            DB::table('apartment_cells')
                ->where('building_id', $primary->building_id)
                ->where('apartment_id', $absorbed->id)
                ->update(['apartment_id' => $primary->id, 'is_primary' => false]);

            // 2. Rooms → primary (codes made unique).
            $existing = Room::query()->where('apartment_id', $primary->id)->pluck('code')->all();
            foreach (Room::query()->where('apartment_id', $absorbed->id)->get() as $room) {
                $code = $room->code;
                $i = 2;
                while (in_array($code, $existing, true)) {
                    $code = $room->code.'_'.$i++;
                }
                $existing[] = $code;
                $room->forceFill(['apartment_id' => $primary->id, 'code' => $code])->save();
            }

            // 3. Riser links → primary (whole-apartment duplicates dropped first).
            DB::table('riser_links')
                ->where('building_id', $primary->building_id)
                ->where('apartment_id', $absorbed->id)
                ->whereNull('room_id')
                ->whereIn('riser_id', DB::table('riser_links')
                    ->where('building_id', $primary->building_id)
                    ->where('apartment_id', $primary->id)
                    ->whereNull('room_id')
                    ->select('riser_id'))
                ->delete();
            DB::table('riser_links')
                ->where('building_id', $primary->building_id)
                ->where('apartment_id', $absorbed->id)
                ->update(['apartment_id' => $primary->id, 'updated_at' => now()]);

            // 4. Invites of the absorbed number can never redeem again.
            ApartmentInvite::query()->where('apartment_id', $absorbed->id)->whereNull('revoked_at')->update(['revoked_at' => now()]);

            // 5. Primary carries the override.
            $retired = array_values(array_unique([...$primary->retired_numbers, $absorbed->number, ...$absorbed->retired_numbers]));
            $primary->forceFill([
                'is_override' => true,
                'override_note' => $note ?? $primary->override_note,
                'retired_numbers' => $retired,
                'rooms_count' => (int) $primary->rooms_count + (int) $absorbed->rooms_count,
                'area_m2' => $primary->area_m2 !== null && $absorbed->area_m2 !== null
                    ? (string) ((float) $primary->area_m2 + (float) $absorbed->area_m2)
                    : $primary->area_m2,
            ])->save();

            // 6. Absorbed row: delete when it has no history, otherwise archive.
            $hasHistory = ApartmentMembership::query()->where('apartment_id', $absorbed->id)->exists();
            if ($hasHistory) {
                $absorbed->forceFill(['archived_at' => now(), 'is_override' => true, 'override_note' => 'merged into '.$primary->number])->save();
            } else {
                $absorbed->delete();
            }

            AuditLogger::record('apartment.merge', $primary, data: [
                'primary_number' => $primary->number,
                'absorbed_number' => $absorbed->number,
                'absorbed_archived' => $hasHistory,
            ]);

            return $primary->refresh();
        });
    }
}
