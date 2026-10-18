<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Riser / column aggregate state (riser_status.status, MVP-SPEC B.1/B.2). `none` = no valid report,
 * `mixed` = conflicting reports (top share < 60 %).
 */
enum AggregateStatus: string
{
    use HasLabel;

    case Cold = 'cold';
    case Lukewarm = 'lukewarm';
    case Warm = 'warm';
    case Overheated = 'overheated';
    case Mixed = 'mixed';
    case None = 'none';

    protected static function labelPrefix(): string
    {
        return 'aggregate_status';
    }

    public static function fromHeating(HeatingStatus $status): self
    {
        return self::from($status->value);
    }

    /**
     * "Worst first" ordering: cold > lukewarm > mixed > overheated > warm > none.
     */
    public function severity(): int
    {
        return match ($this) {
            self::Cold => 5,
            self::Lukewarm => 4,
            self::Mixed => 3,
            self::Overheated => 2,
            self::Warm => 1,
            self::None => 0,
        };
    }

    public function isUnder(): bool
    {
        return $this === self::Cold || $this === self::Lukewarm;
    }

    public function icon(): string
    {
        return match ($this) {
            self::Cold => 'snowflake',
            self::Lukewarm => 'thermometer-snowflake',
            self::Warm => 'thermometer-sun',
            self::Overheated => 'flame',
            self::Mixed => 'circle-alert',
            self::None => 'circle-dashed',
        };
    }

    /**
     * @param  iterable<self|null>  $statuses
     */
    public static function worst(iterable $statuses): ?self
    {
        $worst = null;
        foreach ($statuses as $s) {
            if ($s !== null && $s !== self::None && ($worst === null || $s->severity() > $worst->severity())) {
                $worst = $s;
            }
        }

        return $worst;
    }
}
