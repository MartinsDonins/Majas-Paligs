<?php

declare(strict_types=1);

namespace App\Services\Heating;

use App\Enums\AggregateStatus;
use App\Models\Building;
use App\Models\Incident;
use App\Models\Section;
use App\Services\Heating\Views\ColumnView;
use App\Services\Heating\Views\ResidentRiserView;

/**
 * WhatsApp-ready weekly digest (HEAT-009): aggregate counts of one stairwell in LV + RU, plus a link to the app.
 * No API — the elder pastes it (or opens the wa.me share link). Built only from resident-safe read models:
 * no apartments, no floors, no names.
 */
final class DigestBuilder
{
    public function __construct(private readonly HeatingGridQuery $grid) {}

    public function forSection(Building $building, Section $section): string
    {
        $view = $this->grid->residentSection($section);
        $risers = array_values(array_filter($view->risers, static fn (ResidentRiserView $r): bool => $r->kind->value === 'heating'));

        $counts = ['cold' => 0, 'lukewarm' => 0, 'warm' => 0, 'overheated' => 0, 'mixed' => 0, 'none' => 0];
        foreach ($risers as $r) {
            $key = $r->distinctApartments === 0 ? 'none' : $r->status->value;
            $counts[$key]++;
        }

        $reporting = array_sum(array_map(static fn (ColumnView $c): int => $c->distinctApartments, $view->columns));
        $total = $section->floors * $section->apartments_per_floor;
        $openOutages = Incident::query()->open()
            ->where(fn ($q) => $q->where('section_id', $section->id)->orWhereNull('section_id'))
            ->count();

        $url = rtrim((string) config('app.url'), '/').route('building.heating', $building, false);
        $from = now('Europe/Riga')->subDays($building->settings->adhocReportValidDays)->format('d.m.');
        $to = now('Europe/Riga')->format('d.m.');

        $parts = [];
        foreach (['lv', 'ru'] as $locale) {
            $lines = [
                __('digest.title', ['building' => $building->shortLabel(), 'n' => $section->number, 'from' => $from, 'to' => $to], $locale),
                __('digest.risers', [
                    'cold' => $counts['cold'], 'lukewarm' => $counts['lukewarm'], 'warm' => $counts['warm'],
                    'overheated' => $counts['overheated'], 'mixed' => $counts['mixed'], 'none' => $counts['none'],
                ], $locale),
                __('digest.reporting', ['count' => $reporting, 'total' => $total], $locale),
            ];
            if ($counts['cold'] + $counts['lukewarm'] > 0) {
                $lines[] = __('digest.air_tip', [], $locale);
            }
            $lines[] = $openOutages > 0 ? __('digest.outages', ['count' => $openOutages], $locale) : __('digest.no_outages', [], $locale);
            $lines[] = __('digest.cta', ['url' => $url], $locale);
            $parts[] = implode("\n", $lines);
        }

        return implode("\n\n— — —\n\n", $parts);
    }

    public static function shareUrl(string $text): string
    {
        return 'https://wa.me/?text='.rawurlencode($text);
    }

    /**
     * Highest-severity aggregate in a riser list (used by Home and the digest headline).
     *
     * @param  list<ResidentRiserView>  $risers
     */
    public static function worst(array $risers): ?AggregateStatus
    {
        return AggregateStatus::worst(array_map(static fn (ResidentRiserView $r): ?AggregateStatus => $r->confirmed ? $r->status : null, $risers));
    }
}
