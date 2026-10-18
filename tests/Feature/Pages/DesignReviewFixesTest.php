<?php

declare(strict_types=1);

use App\Enums\BuildingRoleType;
use App\Enums\MembershipStatus;
use App\Enums\TextSize;
use App\Livewire\Me\Apartment;
use App\Livewire\Me\Privacy;
use Livewire\Livewire;

/*
 * Review fixes (dizains, 0.0.14) that render server-side: elder tools reachable from "Vairāk" and in large-text mode,
 * a way back from destructive confirmations, real-modal sheets with a sticky CTA, colour-blind safe heating cells,
 * the 2 × 2 status grid on phones.
 */
beforeEach(function (): void {
    $this->b = heatingHouse('design-fix', 1, 3);
    $this->elder = makeUser('design-elder@example.test');
    member($this->elder, $this->b, 2);
    grantRole($this->elder, $this->b, BuildingRoleType::SectionElder, 1);
    member(makeUser(), $this->b, 3, MembershipStatus::Pending);
});

it('links the elder tools from "Vairāk" (with the pending count) and keeps the Home tools card in large text', function (): void {
    $this->elder->forceFill(['text_size' => TextSize::Large])->save();
    $this->actingAs($this->elder);

    $this->get('/b/design-fix/me')->assertOk()
        ->assertSee(__('home.tools.helper'))
        ->assertSee(route('building.elder', $this->b), false)
        ->assertSee(trans_choice('home.tools.pending', 1, ['count' => 1]));
    $this->get('/b/design-fix')->assertOk()->assertSee(__('home.tools.helper'));
});

it('offers "Atcelt" before the danger button for account deletion and for leaving the apartment', function (): void {
    $this->actingAs($this->elder);

    Livewire::test(Privacy::class)->call('askDelete')->assertSet('confirmDelete', true)
        ->assertSeeHtml('wire:click="cancelDelete"')
        ->set('deleteWord', 'DZĒST')->call('cancelDelete')
        ->assertSet('confirmDelete', false)->assertSet('deleteWord', '');

    inBuilding($this->b, fn () => Livewire::test(Apartment::class)->call('askLeave')
        ->assertSeeHtml('wire:click="cancelLeave"')
        ->call('cancelLeave')->assertSet('confirmLeave', false));
    expect(inBuilding($this->b, fn () => $this->elder->membershipFor($this->b)))->not->toBeNull();
});

it('renders the report CTA as a sticky child of the page and the sheets as trapped modals', function (): void {
    $this->actingAs($this->elder);
    $html = (string) $this->get('/b/design-fix/heating')->assertOk()->getContent();

    expect($html)->toContain('x-data="sheet"')
        ->and($html)->toMatch('/x-data="sheet"[^>]*class="contents"/')
        ->and($html)->toContain('x-trap.noscroll.inert="open"')
        ->and($html)->toContain('sticky-action')
        ->and($html)->toContain('class="status-grid');
});

it('shows an unconfirmed column with its own state icon plus "?" (never colour alone)', function (): void {
    residentReports($this->b, 1, 'cold'); // a single apartment → "unconfirmed", tone cold
    $resident = makeUser('design-resident@example.test');
    member($resident, $this->b, 4);
    $this->actingAs($resident); // resident view = column aggregates (staff see per-apartment cells)

    $html = (string) $this->get('/b/design-fix/heating')->assertOk()->getContent();

    expect($html)->toContain('heat-unconfirmed')
        ->and($html)->toContain('<span class="heat-flag" aria-hidden="true">?</span>')
        ->and($html)->toContain(svg('lucide-snowflake')->toHtml()); // the tone's own icon, not the generic circle-help
});
