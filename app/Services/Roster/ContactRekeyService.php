<?php

declare(strict_types=1);

namespace App\Services\Roster;

use App\Audit\AuditLogger;
use App\Casts\Bytea;
use App\Casts\ByteaArray;
use App\Tenancy\CurrentBuilding;
use Illuminate\Support\Facades\DB;

/**
 * contacts:rekey (SEC-011, ROUND2-SPEC §5.2): moves every roster row of the CURRENT building to ContactVault key
 * version + 1 in ONE transaction holding pg_advisory_xact_lock('contacts:'||building_id) — every field re-sealed with
 * the current CONTACT_ENC_KEY (the old one may be configured as CONTACT_ENC_KEY_PREVIOUS during a master rotation) and
 * every blind index recomputed with the current CONTACT_INDEX_KEY. A row that does not open aborts the whole building.
 */
final class ContactRekeyService
{
    public function __construct(private readonly ContactVault $vault) {}

    /**
     * @return array{from: int, to: int, contacts: int}
     */
    public function rekey(): array
    {
        $building = CurrentBuilding::get();
        $buildingId = (int) $building->id;

        return DB::transaction(function () use ($building, $buildingId): array {
            DB::select('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', ['contacts:'.$buildingId]);

            $rows = DB::table('resident_contacts')->where('building_id', $buildingId)->get();
            $from = max(1, (int) $rows->max('key_version'));
            $to = $from + 1;

            foreach ($rows as $row) {
                $id = (string) $row->id;
                $version = (int) $row->key_version;
                $update = ['key_version' => $to];

                foreach (ContactVault::FIELDS as $field) {
                    $cipher = Bytea::decode($row->{$field.'_ciphertext'});
                    if ($cipher === null || $cipher === '') {
                        continue;
                    }
                    $plain = $this->vault->open($cipher, $buildingId, $id, $field, $version);
                    $update[$field.'_ciphertext'] = Bytea::literal($this->vault->seal($plain, $buildingId, $id, $field, $to));

                    if ($field === 'email') {
                        $update['email_bidx'] = Bytea::literal($this->vault->bidx($buildingId, 'email', $plain));
                    }
                    if ($field === 'phones') {
                        $phones = (array) json_decode($plain, true);
                        $update['phone_bidx'] = ByteaArray::literal(array_map(fn ($p): string => $this->vault->bidx($buildingId, 'phone', (string) $p), array_values($phones)));
                    }
                }

                DB::table('resident_contacts')->where('building_id', $buildingId)->where('id', $id)->update($update);
            }

            AuditLogger::record('contacts.rekey', $building, data: ['from' => $from, 'to' => $to, 'contacts' => $rows->count()]);

            return ['from' => $from, 'to' => $to, 'contacts' => $rows->count()];
        });
    }
}
