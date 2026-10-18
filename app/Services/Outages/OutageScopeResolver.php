<?php

declare(strict_types=1);

namespace App\Services\Outages;

use App\Enums\IncidentKind;
use App\Models\Apartment;
use App\Models\Riser;
use App\Models\RiserLink;
use App\Models\Room;

/**
 * Residents never pick a riser (MVP-SPEC B.2a). Input: apartment + kind (water_cold → cold_water,
 * water_hot → hot_water, heating_none → heating).
 *
 * 1. Load the apartment's risers of that kind via riser_links.
 * 2. Exactly one → `riser:{id}`. Several → one question with the rooms derived from the links + "Visur"
 *    (every linked riser). None / links unknown → column `col:{section}:{primary position}`.
 */
final class OutageScopeResolver
{
    public function resolve(Apartment $apartment, IncidentKind $kind): OutageScope
    {
        $risers = Riser::query()
            ->where('kind', $kind->riserKind()->value)
            ->whereIn('id', RiserLink::query()->where('apartment_id', $apartment->id)->select('riser_id'))
            ->orderBy('code')
            ->get(['id', 'section_id', 'code']);

        if ($risers->isEmpty()) {
            return new OutageScope([ScopeTarget::column((int) $apartment->section_id, (int) $apartment->position)]);
        }

        if ($risers->count() === 1) {
            $r = $risers->first();

            return new OutageScope([ScopeTarget::riser((int) $r->id, (int) $r->section_id)]);
        }

        $links = RiserLink::query()->where('apartment_id', $apartment->id)->whereIn('riser_id', $risers->pluck('id'))->get(['riser_id', 'room_id']);
        $rooms = Room::query()->whereIn('id', $links->pluck('room_id')->filter())->get()->keyBy('id');

        $options = [];
        foreach ($risers as $riser) {
            $labels = $links->where('riser_id', $riser->id)
                ->map(fn (RiserLink $l): ?string => $l->room_id !== null ? $rooms->get($l->room_id)?->getTranslation('label', app()->getLocale()) : null)
                ->filter()
                ->unique()
                ->values()
                ->all();

            $options[] = [
                'key' => 'riser-'.$riser->id,
                'label' => $labels !== [] ? implode(', ', $labels) : __('outage.scope.riser', ['code' => $riser->code]),
                'target' => ScopeTarget::riser((int) $riser->id, (int) $riser->section_id),
            ];
        }

        return new OutageScope([], $options);
    }
}
