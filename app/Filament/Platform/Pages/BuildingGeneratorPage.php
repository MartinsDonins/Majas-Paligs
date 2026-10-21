<?php

declare(strict_types=1);

namespace App\Filament\Platform\Pages;

use App\Enums\NumberingDirection;
use App\Models\Apartment;
use App\Models\Building;
use App\Models\BuildingSeries;
use App\Models\Section;
use App\Models\User;
use App\Platform\PlatformBuildingContext;
use App\Platform\PlatformPurpose;
use App\Services\Buildings\BuildingGenerator;
use App\Services\Buildings\GeneratorInput;
use App\Services\Buildings\SeriesTemplateData;
use BackedEnum;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use InvalidArgumentException;
use Livewire\Attributes\Url;

/**
 * Building generator wizard (SERIES-002, DESIGN-SYSTEM §6.10): series → stairwells → floors → first apartment
 * no. → numbering direction → layout preset per stairwell → preview grid (the SAME Blade grid component as the
 * resident app) → generate. Preview needs no context; reading the stored structure and generate() run inside
 * PlatformBuildingContext.
 *
 * QA 2026-10-20 ("generating the house plan failed"): number fields may be emptied while typing (nullable, a
 * validation message instead of a 500), every input error is a translated notification, switching the series resets
 * the presets, the form starts from the building's STORED stairwells, and an input that conflicts with them is
 * refused before anything is written (BuildingGenerator::conflicts()).
 */
final class BuildingGeneratorPage extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSparkles;

    protected static ?int $navigationSort = 21;

    protected static ?string $slug = 'generator';

    protected string $view = 'filament.platform.pages.building-generator';

    #[Url]
    public ?int $building = null;

    public ?int $seriesId = null;

    public ?int $sections = 5;

    public ?int $floors = 9;

    public ?int $firstApartmentNo = 1;

    public string $direction = 'ltr';

    public string $defaultLayout = '';

    /** @var array<int, string> section number => layout code */
    public array $layouts = [];

    /** @var array<string, mixed>|null */
    public ?array $preview = null;

    /** @var array<string, int>|null */
    public ?array $result = null;

    /** @var list<array{number: int, first: int, last: int, floors: int, layout_code: string}> stored stairwells */
    public array $existing = [];

    /** @var list<string> why the current input cannot be generated */
    public array $conflicts = [];

    /** The building whose structure the form was last loaded from (a re-sent ?building= must not reset the form). */
    public ?int $loadedBuilding = null;

    public static function getNavigationGroup(): string
    {
        return __('admin.nav.buildings');
    }

    public static function getNavigationLabel(): string
    {
        return __('admin.generator.title');
    }

    public function getTitle(): string
    {
        return __('admin.generator.title');
    }

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user instanceof User && $user->isPlatformAdmin();
    }

    public function mount(): void
    {
        $this->seriesId = BuildingSeries::query()->where('code', '602')->value('id');
        $this->loadBuilding();
    }

    /**
     * Picking another building loads ITS series and stored structure (mount() used to read them only once).
     */
    public function updatedBuilding(): void
    {
        if ($this->building !== $this->loadedBuilding) {
            $this->loadBuilding();
        }
    }

    public function updatedSeriesId(): void
    {
        // A new series has other presets: never keep the previous series' codes (they made preview / generate fail).
        $this->defaultLayout = '';
        $this->layouts = [];
        $this->syncLayouts();
    }

    public function updatedSections(): void
    {
        if ($this->sections === null) {
            return; // the field is being retyped
        }

        $this->sections = max(1, min(20, $this->sections));
        $this->syncLayouts();
    }

    private function loadBuilding(): void
    {
        $this->preview = null;
        $this->result = null;
        $this->conflicts = [];
        $this->existing = [];

        $b = $this->building !== null ? Building::query()->find($this->building) : null;
        $this->loadedBuilding = $b?->id;
        if ($b === null) {
            $this->syncLayouts();

            return;
        }

        $this->seriesId = $b->series_id ?? $this->seriesId;
        $this->floors = $b->floors ?? 9;
        $this->defaultLayout = '';
        $this->layouts = [];

        $stored = $this->storedStructure($b);
        $this->existing = $stored['sections'];

        if ($this->existing !== []) {
            $this->sections = count($this->existing);
            $this->floors = $this->existing[0]['floors'];
            $this->firstApartmentNo = $this->existing[0]['first'];
            $this->direction = $stored['direction'];
            foreach ($this->existing as $s) {
                $this->layouts[$s['number']] = $s['layout_code'];
            }
        }

        $this->syncLayouts();
    }

    /**
     * @return array{sections: list<array{number: int, first: int, last: int, floors: int, layout_code: string}>, direction: string}
     */
    private function storedStructure(Building $building): array
    {
        return PlatformBuildingContext::run($building, PlatformPurpose::StructureView, static function (): array {
            $sections = Section::query()->orderBy('number')->get()->map(static fn (Section $s): array => [
                'number' => (int) $s->number,
                'first' => (int) $s->first_apartment_no,
                'last' => (int) $s->first_apartment_no + (int) $s->floors * (int) $s->apartments_per_floor - 1,
                'floors' => (int) $s->floors,
                'layout_code' => (string) $s->layout_code,
            ])->values()->all();

            $first = Section::query()->orderBy('number')->first();
            $corner = $first !== null
                ? Apartment::query()->where('section_id', $first->id)->where('floor', 1)->where('position', 1)->value('number')
                : null;

            return [
                'sections' => $sections,
                'direction' => $corner !== null && $first !== null && (int) $corner !== (int) $first->first_apartment_no ? 'rtl' : 'ltr',
            ];
        });
    }

    private function syncLayouts(): void
    {
        $template = $this->template();
        if ($template === null) {
            return;
        }

        if ($this->defaultLayout === '' || ! isset($template->layouts[$this->defaultLayout])) {
            $this->defaultLayout = $template->defaultLayoutCode ?? (string) array_key_first($template->layouts);
        }

        $layouts = [];
        for ($i = 1; $i <= max(1, (int) $this->sections); $i++) {
            $code = $this->layouts[$i] ?? null;
            $layouts[$i] = is_string($code) && isset($template->layouts[$code]) ? $code : $this->defaultLayout;
        }
        $this->layouts = $layouts;
        $this->preview = null;
        $this->conflicts = [];
    }

    private function template(): ?SeriesTemplateData
    {
        $series = $this->seriesId !== null ? BuildingSeries::query()->find($this->seriesId) : null;

        return $series !== null ? SeriesTemplateData::fromSeries($series) : null;
    }

    /**
     * @throws InvalidArgumentException translated, for the notification
     */
    private function input(): GeneratorInput
    {
        if ($this->sections === null || $this->floors === null || $this->firstApartmentNo === null) {
            throw new InvalidArgumentException(__('admin.generator.err_required'));
        }

        if ($this->sections < 1 || $this->sections > 20) {
            throw new InvalidArgumentException(__('admin.generator.err_sections'));
        }

        $layouts = array_slice($this->layouts, 0, $this->sections, preserve_keys: true);

        return GeneratorInput::perSection(
            $this->floors,
            $layouts,
            $this->firstApartmentNo,
            NumberingDirection::tryFrom($this->direction) ?? NumberingDirection::Ltr,
        );
    }

    public function previewGrid(BuildingGenerator $generator): void
    {
        $template = $this->template();
        if ($template === null) {
            return;
        }

        try {
            $this->syncLayouts();
            $this->preview = $generator->preview($template, $this->input());
            $building = $this->building !== null ? Building::query()->find($this->building) : null;
            $preview = $this->preview;
            $this->conflicts = $building === null ? [] : PlatformBuildingContext::run(
                $building,
                PlatformPurpose::StructureView,
                static fn () => $generator->conflicts($template, $preview),
            );
        } catch (InvalidArgumentException $e) {
            $this->preview = null;
            Notification::make()->danger()->title(__('admin.generator.failed'))->body($e->getMessage())->send();
        }
    }

    public function generate(BuildingGenerator $generator): void
    {
        abort_unless(self::canAccess(), 403);
        $building = $this->building !== null ? Building::query()->find($this->building) : null;
        $template = $this->template();

        if ($building === null || $template === null) {
            Notification::make()->danger()->title(__('admin.generator.pick_building'))->send();

            return;
        }

        try {
            $this->syncLayouts();
            $input = $this->input();
            $this->result = PlatformBuildingContext::run($building, PlatformPurpose::Generate, fn (Building $b) => $generator->generate($b, $template, $input));
        } catch (InvalidArgumentException $e) {
            // Input errors and conflicts with the stored structure: nothing was written (one transaction).
            $this->result = null;
            Notification::make()->danger()->title(__('admin.generator.failed'))->body($e->getMessage())->persistent()->send();

            return;
        }

        if ($building->series_id === null) {
            $building->forceFill(['series_id' => $template->seriesId])->save();
        }

        $this->existing = $this->storedStructure($building)['sections'];
        $this->conflicts = [];

        Notification::make()->success()
            ->title(array_sum($this->result) === 0 ? __('admin.generator.nothing_new') : __('admin.generator.done', $this->result))
            ->send();
    }

    /**
     * @return array<int, string>
     */
    public function buildings(): array
    {
        return Building::query()->orderBy('name')->pluck('name', 'id')->all();
    }

    /**
     * @return array<int, string>
     */
    public function seriesOptions(): array
    {
        return BuildingSeries::query()->orderBy('code')->pluck('code', 'id')->all();
    }

    /**
     * @return list<string>
     */
    public function layoutOptions(): array
    {
        return array_keys($this->template()->layouts ?? []);
    }
}
