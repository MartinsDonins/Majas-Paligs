<?php

declare(strict_types=1);

namespace App\Services\Parking;

use App\Enums\PermitStatus;
use App\Enums\PermitType;
use App\Services\Import\RowResult;
use Carbon\CarbonImmutable;
use Throwable;

/**
 * Cell parsers of the permit import (PARK-012, ROUND2-SPEC §4.5 / §6.3), used by PermitImportHandler. Pure functions:
 * no database, no values in any code. The handler's codes:
 *
 * - errors: type_unknown, plate_invalid (main), main_without_plate, plate_conflict (the plate is active in another
 *   apartment — or another apartment's row earlier in the file; the apartment is never named), number_overlap (an
 *   active permit with the same number + type elsewhere — the EXCLUDE constraint would fire), date_invalid,
 *   period_invalid, status_unknown, apartment_change_refused (update mode never moves a permit); plus the shared
 *   required_missing, too_long, apartment_not_found, duplicate_in_file.
 * - warnings: date_serial, flag_unparsed, plate_invalid_guest (no observation), exists_update; shared apartment_merged.
 * - skips: exists_skip (create_only: the same permit already exists), inactive_skip (a returned / lost / revoked /
 *   expired permit that does not exist yet is not imported).
 */
final class PermitRowValidator
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

    /**
     * Dates: dd.mm.yyyy, d.m.yy, yyyy-mm-dd, dd/mm/yyyy; Excel serial numbers are accepted with the warning
     * `date_serial`. Returns null for empty, false for unparseable.
     */
    public static function date(string $value, ?RowResult $result = null): CarbonImmutable|false|null
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }

        if (preg_match('/^\d{5}$/', $value) === 1 && (int) $value > 20000 && (int) $value < 80000) {
            $result?->add('date_serial');

            return CarbonImmutable::create(1899, 12, 30, 0, 0, 0, 'Europe/Riga')->addDays((int) $value);
        }

        foreach (['d.m.Y', 'j.n.Y', 'd.m.y', 'j.n.y', 'Y-m-d', 'd/m/Y', 'j/n/Y'] as $format) {
            try {
                $d = CarbonImmutable::createFromFormat('!'.$format, $value, 'Europe/Riga');
            } catch (Throwable) {
                $d = null;
            }
            if ($d instanceof CarbonImmutable && $d->format($format) === $value && $d->year >= 1990 && $d->year <= 2100) {
                return $d;
            }
        }

        return false;
    }

    /**
     * Yes / no (LV, RU, EN, ticks): true | false | null (empty = unknown) | 'unparsed'.
     */
    public static function yesNo(string $value): bool|string|null
    {
        $v = mb_strtolower(trim($value), 'UTF-8');

        return match (true) {
            $v === '' => null,
            in_array($v, ['jā', 'ja', 'yes', 'y', '1', 'x', '+', '✓', '✔', 'да', 'true'], true) => true,
            in_array($v, ['nē', 'ne', 'no', 'n', '0', 'нет', 'false'], true) => false,
            default => 'unparsed',
        };
    }
}
