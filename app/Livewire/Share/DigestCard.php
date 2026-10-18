<?php

declare(strict_types=1);

namespace App\Livewire\Share;

use App\Models\Section;
use App\Models\User;
use App\Services\Heating\DigestBuilder;
use App\Tenancy\CurrentBuilding;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Component;

/**
 * WhatsApp-ready weekly digest (HEAT-009): aggregate LV + RU text for the elder's stairwell, copy button and a
 * https://wa.me/?text= share link. No API, no personal data.
 */
final class DigestCard extends Component
{
    public ?int $stairwell = null;

    public function mount(): void
    {
        $this->stairwell = $this->sections()->first()?->number;
    }

    public function render(DigestBuilder $digests): View
    {
        $building = CurrentBuilding::get();
        $section = $this->sections()->firstWhere('number', $this->stairwell);
        $text = $section !== null && $building->settings->moduleEnabled('heating') ? $digests->forSection($building, $section) : null;

        return view('livewire.share.digest-card', [
            'sections' => $this->sections(),
            'text' => $text,
            'shareUrl' => $text !== null ? DigestBuilder::shareUrl($text) : null,
        ]);
    }

    /**
     * @return Collection<int, Section>
     */
    private function sections(): Collection
    {
        /** @var User $user */
        $user = auth()->user();
        $building = CurrentBuilding::get();

        return Section::query()
            ->when(! $user->isBuildingAdmin($building), fn ($q) => $q->whereIn('id', $user->elderSectionIds($building)))
            ->orderBy('number')->get();
    }
}
