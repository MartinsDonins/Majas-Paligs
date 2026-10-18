<?php

declare(strict_types=1);

namespace App\Livewire\Heating;

use App\Models\Apartment;
use App\Models\HeatingReport;
use App\Models\SurveyRound;
use App\Models\User;
use App\Tenancy\CurrentBuilding;
use Illuminate\Contracts\View\View;
use Livewire\Component;

/**
 * Home card for an open survey round of my stairwell: "Novembra apkures pārbaude · aizpildījuši 21 no 36" +
 * progress + "Aizpildīt (1 min)". Counts only.
 */
final class RoundCard extends Component
{
    public function render(): View
    {
        $building = CurrentBuilding::get();
        /** @var User $user */
        $user = auth()->user();
        $membership = $user->membershipFor($building);
        $apartment = $membership !== null ? Apartment::query()->with('section')->find($membership->apartment_id) : null;
        $round = $apartment !== null ? SurveyRound::openFor($apartment->section_id) : null;

        $filled = 0;
        $total = 0;
        $mine = false;
        if ($round !== null && $apartment !== null) {
            $apartmentIds = Apartment::query()->active()->where('section_id', $apartment->section_id)->pluck('id');
            $total = $apartmentIds->count();
            $reported = HeatingReport::query()->active()->where('survey_round_id', $round->id)
                ->whereIn('apartment_id', $apartmentIds)->distinct()->pluck('apartment_id');
            $filled = $reported->count();
            $mine = $reported->contains($apartment->id);
        }

        return view('livewire.heating.round-card', [
            'building' => $building,
            'round' => $round,
            'filled' => $filled,
            'total' => $total,
            'mine' => $mine,
            'section' => $apartment?->section?->number,
            'canFill' => $membership?->isApproved() ?? false,
        ]);
    }
}
