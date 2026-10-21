<?php

declare(strict_types=1);

use App\Auth\StepUpService;
use App\Filament\Manage\Resources\SectionResource;
use App\Filament\Platform\Pages\BuildingGeneratorPage;
use App\Filament\Platform\Resources\BuildingResource\Pages\CreateBuilding;
use App\Filament\Platform\Resources\BuildingResource\Pages\EditBuilding;
use App\Filament\Platform\Resources\PrivacyNoticeResource;
use App\Models\Apartment;
use App\Models\Building;
use App\Models\BuildingSeries;
use App\Models\PrivacyNoticeVersion;
use App\Models\Section;
use App\Platform\PlatformBuildingContext;
use App\Platform\PlatformPurpose;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

/*
 * Production QA 2026-10-20 (platforma-generators): "generating the house plan failed", building edit 500, no create
 * buttons, audit verify missing, raw keys / Title Case in the panels.
 */
beforeEach(function (): void {
    $this->platform = makeUser('qa-platform@example.test', ['platform_role' => 'platform_admin']);
    $this->actingAs($this->platform);
    Filament::setCurrentPanel(Filament::getPanel('platform'));
    app(StepUpService::class)->markFresh($this->platform, 'totp');
});

function generatorFor(Building $b): mixed
{
    return Livewire::actingAs(test()->platform)->withQueryParams(['building' => $b->id])->test(BuildingGeneratorPage::class);
}

/**
 * Riser links whose riser and apartment are in different stairwells (must always be 0).
 */
function crossSectionLinks(Building $b): int
{
    return (int) DB::table('riser_links')
        ->join('risers', 'risers.id', '=', 'riser_links.riser_id')
        ->join('apartments', 'apartments.id', '=', 'riser_links.apartment_id')
        ->where('riser_links.building_id', $b->id)
        ->whereColumn('risers.section_id', '!=', 'apartments.section_id')
        ->count();
}

it('opens the building edit page (settings value object no longer breaks Livewire)', function (): void {
    $b = makeBuilding('qa-edit', 1, 1);

    $this->get("/platform/buildings/{$b->slug}/edit")->assertOk();
    Livewire::actingAs($this->platform)->test(EditBuilding::class, ['record' => $b->slug])
        ->set('data.name', 'Jauna iela 1')->call('save')->assertHasNoErrors();
    expect($b->refresh()->name)->toBe('Jauna iela 1');
});

it('creates a building from the list button and continues to the generator', function (): void {
    $this->get('/platform/buildings')->assertOk()->assertSee('/platform/buildings/create', false);
    foreach (['building-series', 'privacy-notices', 'legal-notices'] as $list) {
        $this->get("/platform/{$list}")->assertOk()->assertSee("/platform/{$list}/create", false);
    }

    $series = series602();
    Livewire::actingAs($this->platform)->test(CreateBuilding::class)
        ->fillForm(['name' => 'Jaunā māja', 'slug' => 'qa-new', 'address_line' => 'Jaunā iela 2', 'city' => 'Rīga', 'series_id' => $series->id, 'floors' => 9, 'status' => 'draft'])
        ->call('create')
        ->assertHasNoErrors()
        ->assertRedirect(BuildingGeneratorPage::getUrl(['building' => Building::query()->where('slug', 'qa-new')->value('id')]));
});

it('never answers an emptied number field or an out-of-range input with a server error', function (): void {
    $b = Building::query()->create(['slug' => 'qa-gen-empty', 'name' => 'Tukšā 1', 'address_line' => 'Tukšā 1', 'city' => 'Rīga', 'series_id' => series602()->id, 'floors' => 9]);

    // Emptying a field while typing used to be "Property [$sections] not found" (500). Livewire never rehydrates a
    // typed null, so the next request sees the default again — no error either way.
    generatorFor($b)
        ->set('sections', '')->assertHasNoErrors()
        ->set('floors', '')->set('firstApartmentNo', '')->assertHasNoErrors()
        ->set('sections', 2)->set('floors', 31)->call('generate')
        ->assertNotified(__('admin.generator.failed'))
        ->assertSet('result', null)
        ->set('floors', 0)->call('previewGrid')
        ->assertNotified(__('admin.generator.failed'))
        ->assertSet('preview', null);

    expect(inBuilding($b, fn () => Section::query()->count()))->toBe(0);
});

it('resets the presets when the series changes', function (): void {
    series602();
    $other = BuildingSeries::query()->create(['code' => 'QA-EMPTY', 'name' => ['lv' => 'QA', 'ru' => 'QA'], 'status' => 'draft']);
    $b = Building::query()->create(['slug' => 'qa-gen-series', 'name' => 'Sērija 1', 'address_line' => 'Sērija 1', 'city' => 'Rīga', 'series_id' => series602()->id, 'floors' => 5]);

    $page = generatorFor($b)->set('layouts.1', '2-2-3-3')->set('seriesId', $other->id);
    expect($page->get('layouts'))->not->toContain('2-2-3-3');

    // A series without presets: generate explains instead of a 500.
    $page->call('generate')->assertSet('result', null);
});

it('starts from the stored stairwells, refuses a conflicting re-run and never links across stairwells', function (): void {
    $b = makeBuilding('qa-gen-a', 2, 3, '2-2-3-3'); // apartments 1..24, 2 stairwells × 3 floors × 4

    $page = generatorFor($b)
        ->assertSet('sections', 2)->assertSet('floors', 3)->assertSet('firstApartmentNo', 1)
        ->assertSee('1–12')->assertSee('13–24');

    $before = inBuilding($b, fn () => Apartment::query()->count());

    // 5 floors instead of 3 → both stored stairwells conflict; nothing is written.
    $page->set('floors', 5)->call('previewGrid')->assertSee(__('admin.generator.conflicts_title'));
    $page->call('generate')->assertSet('result', null);
    expect(inBuilding($b, fn () => Apartment::query()->count()))->toBe($before);

    // Same parameters + one more stairwell → only stairwell 3 (25..36) is added.
    $page->set('floors', 3)->set('sections', 3)->call('generate');
    expect($page->get('result')['sections'])->toBe(1)
        ->and(inBuilding($b, fn () => Apartment::query()->count()))->toBe($before + 12)
        ->and(inBuilding($b, fn () => Apartment::query()->where('section_id', Section::query()->where('number', 3)->value('id'))->min('number')))->toBe(25)
        ->and(crossSectionLinks($b))->toBe(0);

    // QA case: another first number (+ one more stairwell) is refused as a whole — nothing is added.
    $page->set('sections', 4)->set('firstApartmentNo', 13)->call('generate')->assertSet('result', null);
    expect(inBuilding($b, fn () => Section::query()->count()))->toBe(3)
        ->and(crossSectionLinks($b))->toBe(0);
});

it('shows the platform chain verify button and names purpose and building', function (): void {
    $b = makeBuilding('qa-audit', 1, 1);
    PlatformBuildingContext::run($b, PlatformPurpose::StructureView, static fn () => null);

    $html = (string) $this->get('/platform/audit')->assertOk()->getContent();
    expect($html)->toContain('wire:click="verifyPlatform"')
        ->toContain(__('admin.audit.purpose.structure_view'))
        ->toContain(e($b->name));
});

it('does not link published privacy notices to a 403 edit page and lists the modules once', function (): void {
    $published = PrivacyNoticeVersion::query()->create(['kind' => 'platform_account', 'version' => 'qa-1', 'covers_modules' => ['core', 'heating'], 'body' => ['lv' => 'x', 'ru' => 'x'], 'published_at' => now()]);

    expect(PrivacyNoticeResource::canEdit($published))->toBeFalse();
    $html = $this->get('/platform/privacy-notices')->assertOk()->getContent();
    expect(substr_count((string) $html, __('enum.module.heating')))->toBe(1)
        ->and($html)->not->toContain("/platform/privacy-notices/{$published->id}/edit");
});

it('has an LV/RU switch and a local avatar in the panels, no Title Case and no raw Filament keys', function (): void {
    $html = (string) $this->get('/platform/buildings')->assertOk()->getContent();

    expect($html)->toContain('data-testid="panel-language-switch"')
        ->toContain('action="'.route('locale.set', 'ru').'"')
        ->not->toContain('ui-avatars.com')
        ->not->toContain('http://localhost/icons/icon.svg')
        ->not->toContain('filament-panels::layout')
        ->not->toContain('filament-tables::table');

    app()->setLocale('ru');
    expect(__('filament-tables::table.loading'))->toBe('Загрузка…')
        ->and(__('filament-forms::components.select.required_description'))->toBe('Выберите вариант.');
    app()->setLocale('lv');
    expect(__('filament-panels::layout.avatar.alt', ['name' => 'A']))->toBe('A attēls')
        ->and(SectionResource::getNavigationLabel())->toBe(__('admin.section.plural'));
});
