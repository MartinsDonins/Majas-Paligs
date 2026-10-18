<?php

declare(strict_types=1);

namespace App\Services\Outages;

use App\Models\Incident;
use App\Services\Heating\RiserLabel;

/**
 * Human scope label of an incident for residents: "Aukstais ūdens · kreisā puse (5. k.t.)", "5. k.t. · kolonna 2",
 * "5. kāpņu telpa", "Visa māja" (+ floor range for notices). Structure only — never apartments.
 */
final class IncidentPresenter
{
    public function scope(Incident $incident, ?string $locale = null): string
    {
        $key = $incident->scope_key;
        $section = $incident->section?->number;

        $label = match (true) {
            str_starts_with($key, 'riser:') => __('outage.scope.riser_in', [
                'riser' => RiserLabel::for((string) $incident->riser?->code, $locale),
                'section' => (string) $section,
            ], $locale),
            str_starts_with($key, 'col:') => __('outage.scope.column', ['section' => (string) $section, 'position' => (string) $incident->column_position], $locale),
            str_starts_with($key, 'section:') => __('outage.scope.section', ['n' => (string) $section], $locale),
            default => __('outage.scope.building', [], $locale),
        };

        $range = $incident->floorRange();
        if ($range !== null) {
            $label .= ' · '.__('outage.scope.floors', ['from' => $range[0], 'to' => $range[1]], $locale);
        }

        return $label;
    }
}
