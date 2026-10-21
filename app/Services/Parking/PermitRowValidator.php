<?php

declare(strict_types=1);

namespace App\Services\Parking;

use App\Enums\PermitStatus;
use App\Enums\PermitType;
use App\Models\ImportRun;
use App\Models\Permit;
use App\Models\Vehicle;
use App\Services\Import\RowResult;
use App\Services\Import\RowValidator;
use App\Tenancy\CurrentBuilding;
use Carbon\CarbonImmutable;

/**
 * Permit rows (PARK-012, ROUND2-SPEC §4.5 / §6.3) — codes, never values:
 *
 * - errors: required_missing, too_long, type_unknown, apartment_not_found, plate_invalid (main), main_without_plate,
 *   plate_conflict (the plate is active in another apartment — or another apartment's row earlier in the file; the
 *   apartment is never named), number_overlap (an active permit with the same number + type elsewhere — the EXCLUDE
 *   constraint would fire), date_invalid, period_invalid, status_unknown, apartment_change_refused (update mode never
 *   moves a permit), duplicate_in_file.
 * - warnings: apartment_merged, date_serial, flag_unparsed, plate_invalid on a guest row (no observation), exists_skip
 *   (create_only: the same permit already exists → skipped), exists_update (create_or_update), inactive_skip (a
 *   returned / lost / revoked / expired permit that does not exist yet is not imported).
 */
final class PermitRowValidator extends RowValidator
{
    public const TYPES = [
        'main' => ['main', 'm', 'pamata', 'p', 'pamatcaurlaide', 'основной', 'осн', 'постоянный'],
        'guest' => ['guest', 'g', 'viesu', 'v', 'viesis', 'viesu caurlaide', 'гостевой', 'гост', 'гость'],
    ];

    public const STATUSES = [
        'active' => ['active', 'aktīva', 'aktiva', 'aktīvs', 'derīga', 'deriga', 'a', 'действует', 'активный', 'активен'],
        'returned' => ['returned', 'nodota', 'atdota', 'сдан', 'возвращён', 'возвращен'],
        'lost' => ['lost', 'pazaudēta', 'pazaudeta', 'nozaudēta', 'утерян', 'потерян'],
        'revoked' => ['revoked', 'atsaukta', 'anulēta', 'отозван', 'аннулирован'],
        'expired' => ['expired', 'beigusies', 'nederīga', 'istekusi', 'истёк', 'истек'],
    ];

    /** @var array<string, string> normalised plate => apartment key, from earlier rows of this file */
    private array $platesInFile = [];

    public function __construct(
        ImportRun $run,
        private readonly PlateVault $vault,
    ) {
        parent::__construct($run);
    }

    public function duplicateKey(array $cells): ?string
    {
        $type = self::type($cells['type'] ?? '');
        $number = trim($cells['permit_number'] ?? '');

        return $type !== null && $number !== '' && mb_strlen($number) <= 32 ? $type->value.'|'.self::number($number) : null;
    }

    public function prime(array $earlier): void
    {
        parent::prime($earlier);

        foreach ($earlier as $cells) {
            $this->rememberPlate($cells);
        }
    }

    public function validate(int $line, array $cells): RowResult
    {
        $r = new RowResult($line);
        $mode = $this->run->mode();

        $number = $this->required($cells, 'permit_number', $r);
        if ($number !== null && $this->maxLength($number, 32, $r) !== null) {
            $number = self::number($number);
        } else {
            $number = null;
        }

        $typeRaw = $this->required($cells, 'type', $r);
        $type = $typeRaw !== null ? self::type($typeRaw) : null;
        if ($typeRaw !== null && $type === null) {
            $r->fail('type_unknown');
        }

        $apartmentRaw = $this->required($cells, 'apartment_number', $r);
        $apartment = $apartmentRaw !== null ? $this->apartment($apartmentRaw, $r) : null;

        $from = self::date($cells['valid_from'] ?? '', $r);
        $to = self::date($cells['valid_to'] ?? '', $r);
        if ($from === false || $to === false) {
            $r->fail('date_invalid');
        }
        $from = $from instanceof CarbonImmutable ? $from : null;
        $to = $to instanceof CarbonImmutable ? $to : null;
        if ($to !== null && $to->lessThan($from ?? CarbonImmutable::now('Europe/Riga')->startOfDay())) {
            $r->fail('period_invalid');
        }

        $status = PermitStatus::Active;
        if (trim($cells['status'] ?? '') !== '') {
            $status = self::status($cells['status']) ?? PermitStatus::Active;
            if (self::status($cells['status']) === null) {
                $r->fail('status_unknown');
            }
        }

        $issuedBy = $this->maxLength(($cells['issued_by'] ?? '') !== '' ? $cells['issued_by'] : null, 120, $r);

        $company = self::yesNo($cells['company_car'] ?? '');
        if ($company === 'unparsed') {
            $r->warn('flag_unparsed');
            $company = null;
        }

        $plateRaw = trim($cells['plate'] ?? '');
        $plate = $plateRaw !== '' ? PlateNormalizer::normalize($plateRaw) : null;
        if ($type === PermitType::Main && $plateRaw === '' && $mode === 'create_only') {
            $r->fail('main_without_plate');
        } elseif ($plateRaw !== '' && $plate === null) {
            $type === PermitType::Guest ? $r->warn('plate_invalid') : $r->fail('plate_invalid');
        }

        $key = $this->duplicateKey($cells);
        if ($key !== null && $this->duplicate($key, $line)) {
            $r->fail('duplicate_in_file');
        }

        $r->values = [
            'number' => $number, 'type' => $type, 'apartment' => $apartment, 'plate' => $plate, 'valid_from' => $from,
            'valid_to' => $to, 'status' => $status, 'issued_by' => $issuedBy, 'company_car' => $company === true,
            'existing' => null, 'vehicle' => null,
        ];

        if ($r->failed() || $number === null || $type === null || $apartment === null) {
            $this->rememberPlate($cells);

            return $r;
        }

        $this->againstDatabase($r, $mode, $number, $type, (int) $apartment->id, self::apartmentKey($cells), $plate, $from, $to, $status);
        $this->rememberPlate($cells);

        return $r;
    }

    /**
     * Existing permits, overlap, plate conflicts — the dry-run and the commit-time re-validation.
     */
    private function againstDatabase(RowResult $r, string $mode, string $number, PermitType $type, int $apartmentId, string $apartmentKey, ?string $plate, ?CarbonImmutable $from, ?CarbonImmutable $to, PermitStatus $status): void
    {
        $start = $from ?? CarbonImmutable::now('Europe/Riga')->startOfDay();
        $same = Permit::query()->active()->where('number', $number)->where('type', $type->value)->get();

        $mine = $same->first(fn (Permit $p): bool => (int) $p->apartment_id === $apartmentId);
        $elsewhere = $same->first(fn (Permit $p): bool => (int) $p->apartment_id !== $apartmentId
            && ($p->valid_to === null || ! $p->valid_to->lessThan($start))
            && ($to === null || ! $to->lessThan($p->valid_from)));

        if ($mine !== null) {
            $r->values['existing'] = $mine;
            if ($mode === 'create_or_update') {
                $r->action = 'update';
                $r->warn('exists_update');
            } else {
                $r->action = 'skip';
                $r->warn('exists_skip');
            }

            return;
        }

        if ($elsewhere !== null) {
            $r->fail($mode === 'create_or_update' && $same->isNotEmpty() ? 'apartment_change_refused' : 'number_overlap');

            return;
        }

        if ($status !== PermitStatus::Active) {
            $r->action = 'skip';
            $r->warn('inactive_skip');

            return;
        }

        if ($type === PermitType::Main) {
            if ($plate === null) {
                $r->fail('main_without_plate');

                return;
            }

            $bidx = $this->vault->currentBidx(CurrentBuilding::get(), $plate);
            $vehicle = Vehicle::query()->active()->withBidx($bidx)->first();
            if ($vehicle !== null && (int) $vehicle->apartment_id !== $apartmentId) {
                $r->fail('plate_conflict');

                return;
            }
            $inFile = $this->platesInFile[$plate] ?? null;
            if ($inFile !== null && $inFile !== $apartmentKey) {
                $r->fail('plate_conflict');

                return;
            }
            $r->values['vehicle'] = $vehicle;
        }
    }

    /**
     * Main-permit plates of earlier rows (pure): a plate listed for two apartments in one file is a conflict even
     * before the first row is written.
     *
     * @param  array<string, string>  $cells
     */
    private function rememberPlate(array $cells): void
    {
        if (self::type($cells['type'] ?? '') !== PermitType::Main) {
            return;
        }

        $plate = PlateNormalizer::normalize($cells['plate'] ?? '');
        $apartment = self::apartmentKey($cells);

        if ($plate !== null && $apartment !== '' && ! isset($this->platesInFile[$plate])) {
            $this->platesInFile[$plate] = $apartment;
        }
    }

    /**
     * The apartment as written in the file (digits only) — compares rows of one file without the database.
     *
     * @param  array<string, string>  $cells
     */
    private static function apartmentKey(array $cells): string
    {
        return preg_match('/(\d{1,4})/', (string) ($cells['apartment_number'] ?? ''), $m) === 1 ? (string) (int) $m[1] : '';
    }

    public static function number(string $value): string
    {
        return strtoupper(trim((string) preg_replace('/\s+/', ' ', $value)));
    }

    public static function type(string $value): ?PermitType
    {
        $v = mb_strtolower(trim($value), 'UTF-8');
        foreach (self::TYPES as $type => $words) {
            if (in_array($v, $words, true)) {
                return PermitType::from($type);
            }
        }

        return null;
    }

    public static function status(string $value): ?PermitStatus
    {
        $v = mb_strtolower(trim($value), 'UTF-8');
        foreach (self::STATUSES as $status => $words) {
            if (in_array($v, $words, true)) {
                return PermitStatus::from($status);
            }
        }

        return null;
    }
}
