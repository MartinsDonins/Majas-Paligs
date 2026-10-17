<?php

declare(strict_types=1);

namespace App\Filament\Platform\Pages;

use App\Enums\NumberingDirection;
use App\Models\Building;
use App\Models\BuildingSeries;
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
 * resident app) → generate. preview() needs no context; generate() runs inside PlatformBuildingContext.
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

    public int $sections = 5;

    public int $floors = 9;

    public int $firstApartmentNo = 1;

    public string $direction = 'ltr';

    public string $defaultLayout = '';

    /** @var array<int, string> section number => layout code */
    public array $layouts = [];

    /** @var array<string, mixed>|null */
    public ?array $preview = null;

    /** @var array<string, int>|null */
    public ?array $result = null;

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
        $b = $this->building !== null ? Building::query()->find($this->building) : null;
        $this->seriesId = $b->series_id ?? BuildingSeries::query()->where('code', '602')->value('id');
        $this->floors = $b->floors ?? 9;
        $this->syncLayouts();
    }

    public function updatedSeriesId(): void
    {
        $this->defaultLayout = '';
        $this->syncLayouts();
    }

    public function updatedSections(): void
    {
        $this->sections = max(1, min(20, $this->sections));
        $this->syncLayouts();
    }

    private function syncLayouts(): void
    {
        $template = $this->template();
        if ($template === null) {
            return;
        }

        $this->defaultLayout = $this->defaultLayout !== '' ? $this->defaultLayout : ($template->defaultLayoutCode ?? (string) array_key_first($template->layouts));
        $layouts = [];
        for ($i = 1; $i <= $this->sections; $i++) {
            $layouts[$i] = $this->layouts[$i] ?? $this->defaultLayout;
        }
        $this->layouts = $layouts;
        $this->preview = null;
    }

    private function template(): ?SeriesTemplateData
    {
        $series = $this->seriesId !== null ? BuildingSeries::query()->find($this->seriesId) : null;

        return $series !== null ? SeriesTemplateData::fromSeries($series) : null;
    }

    private function input(): GeneratorInput
    {
        return GeneratorInput::perSection(
            $this->floors,
            $this->layouts,
            $this->firstApartmentNo,
            NumberingDirection::from($this->direction),
        );
    }

    public function previewGrid(BuildingGenerator $generator): void
    {
        $template = $this->template();
        if ($template === null) {
            return;
        }

        try {
            $this->preview = $generator->preview($template, $this->input());
        } catch (InvalidArgumentException $e) {
            Notification::make()->danger()->title($e->getMessage())->send();
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

        $input = $this->input();
        $this->result = PlatformBuildingContext::run($building, PlatformPurpose::Generate, fn (Building $b) => $generator->generate($b, $template, $input));

        if ($building->series_id === null) {
            $building->forceFill(['series_id' => $template->seriesId])->save();
        }

        Notification::make()->success()->title(__('admin.generator.done', $this->result))->send();
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
