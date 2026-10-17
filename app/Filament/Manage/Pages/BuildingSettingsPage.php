<?php

declare(strict_types=1);

namespace App\Filament\Manage\Pages;

use App\Audit\AuditLogger;
use App\Gdpr\CoreGate;
use App\Gdpr\CoreGateMonitor;
use App\Gdpr\CoreGateStatus;
use App\Models\Building;
use App\Support\BuildingSettings;
use App\Tenancy\CurrentBuilding;
use BackedEnum;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;

/**
 * Building settings (building_admin + step-up): house controller, DPA (ref/date/scope), DPIA, mandate, modules,
 * manager-sharing flags, manager contact — with the gate status L-core / L1 / L2 and its missing conditions.
 * Every save is audited; gate transitions alert and audit (CoreGateMonitor).
 *
 * @property-read Schema $form
 */
final class BuildingSettingsPage extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCog6Tooth;

    protected static ?int $navigationSort = 80;

    protected static ?string $slug = 'settings';

    protected string $view = 'filament.manage.pages.building-settings';

    /** @var array<string, mixed> */
    public ?array $data = [];

    public static function getNavigationGroup(): string
    {
        return __('admin.nav.governance');
    }

    public static function getNavigationLabel(): string
    {
        return __('admin.settings.title');
    }

    public function getTitle(): string
    {
        return __('admin.settings.title');
    }

    public static function canAccess(): bool
    {
        $building = CurrentBuilding::find();

        return $building !== null && Gate::allows('update', $building);
    }

    public function mount(): void
    {
        abort_unless(self::canAccess(), 403);
        $b = CurrentBuilding::get();
        $s = $b->settings;

        $this->form->fill([
            'controller_name' => $s->controller['name'],
            'controller_contact' => $s->controller['contact'],
            'controller_basis_ref' => $s->controller['basis_ref'],
            'dpa_signed_at' => $s->dpaSignedAt,
            'dpa_ref' => $s->dpaRef,
            'dpa_scope' => $s->dpaScope,
            'dpia_completed_at' => $s->dpiaCompletedAt,
            'mandate_ref' => $s->mandateRef,
            'module_heating' => $s->modules['heating'],
            'module_outages' => $s->modules['outages'],
            'share_heating_floors' => $s->shareWithManager['heating_floors'],
            'share_apartment_numbers' => $s->shareWithManager['apartment_numbers'],
            'share_decided_ref' => $s->shareWithManager['decided_ref'],
            'manager_name' => $b->manager_name,
            'manager_emergency_phone' => $b->manager_emergency_phone,
            'default_locale' => $b->default_locale,
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->components([
                Section::make(__('admin.settings.controller'))
                    ->description(__('admin.settings.controller_help'))
                    ->columns(2)
                    ->schema([
                        TextInput::make('controller_name')->label(__('admin.settings.controller_name'))->maxLength(200),
                        TextInput::make('controller_contact')->label(__('admin.settings.controller_contact'))->maxLength(200)
                            ->helperText(__('admin.settings.controller_contact_help')),
                        TextInput::make('controller_basis_ref')->label(__('admin.settings.controller_basis'))->maxLength(200),
                    ]),
                Section::make(__('admin.settings.dpa'))
                    ->columns(2)
                    ->schema([
                        DatePicker::make('dpa_signed_at')->label(__('admin.settings.dpa_signed_at'))->native(false)->displayFormat('d.m.Y'),
                        TextInput::make('dpa_ref')->label(__('admin.settings.dpa_ref'))->maxLength(120),
                        CheckboxList::make('dpa_scope')->label(__('admin.settings.dpa_scope'))
                            ->options(collect(BuildingSettings::DPA_SCOPES)->mapWithKeys(fn ($m) => [$m => __('enum.module.'.$m)])->all())
                            ->columns(4),
                        DatePicker::make('dpia_completed_at')->label(__('admin.settings.dpia'))->native(false)->displayFormat('d.m.Y'),
                        TextInput::make('mandate_ref')->label(__('admin.settings.mandate'))->maxLength(200),
                    ]),
                Section::make(__('admin.settings.modules'))
                    ->columns(2)
                    ->schema([
                        Toggle::make('module_heating')->label(__('enum.module.heating')),
                        Toggle::make('module_outages')->label(__('enum.module.outages')),
                    ]),
                Section::make(__('admin.settings.sharing'))
                    ->description(__('admin.settings.sharing_help'))
                    ->columns(2)
                    ->schema([
                        Toggle::make('share_heating_floors')->label(__('admin.settings.share_floors'))->live(),
                        Toggle::make('share_apartment_numbers')->label(__('admin.settings.share_numbers'))
                            ->disabled(fn (Get $get): bool => ! $get('share_heating_floors')),
                        TextInput::make('share_decided_ref')->label(__('admin.settings.share_ref'))->maxLength(200),
                    ]),
                Section::make(__('admin.settings.manager'))
                    ->columns(2)
                    ->schema([
                        TextInput::make('manager_name')->label(__('admin.settings.manager_name'))->maxLength(200),
                        TextInput::make('manager_emergency_phone')->label(__('admin.settings.manager_phone'))
                            ->helperText(__('admin.settings.manager_phone_help'))
                            ->regex('/^[0-9+ ()\-]{5,20}$/')->maxLength(20),
                        Select::make('default_locale')->label(__('admin.settings.default_locale'))
                            ->options(['lv' => 'Latviešu', 'ru' => 'Русский'])->required(),
                    ]),
            ]);
    }

    public function save(): void
    {
        $building = CurrentBuilding::get();
        Gate::authorize('update', $building);

        $data = $this->form->getState();
        $before = CoreGate::status($building);

        try {
            $settings = $building->settings->with([
                'controller' => [
                    'name' => $data['controller_name'] ?? null,
                    'contact' => $data['controller_contact'] ?? null,
                    'basis_ref' => $data['controller_basis_ref'] ?? null,
                ],
                'dpa_signed_at' => $data['dpa_signed_at'] ?? null,
                'dpa_ref' => $data['dpa_ref'] ?? null,
                'dpa_scope' => array_values((array) ($data['dpa_scope'] ?? [])),
                'dpia_completed_at' => $data['dpia_completed_at'] ?? null,
                'mandate_ref' => $data['mandate_ref'] ?? null,
                'modules' => [
                    'heating' => (bool) ($data['module_heating'] ?? false),
                    'outages' => (bool) ($data['module_outages'] ?? false),
                ],
                'share_with_manager' => [
                    'heating_floors' => (bool) ($data['share_heating_floors'] ?? false),
                    'apartment_numbers' => (bool) ($data['share_heating_floors'] ?? false) && (bool) ($data['share_apartment_numbers'] ?? false),
                    'decided_ref' => $data['share_decided_ref'] ?? null,
                ],
            ]);
        } catch (InvalidArgumentException $e) {
            Notification::make()->danger()->title($e->getMessage())->send();

            return;
        }

        $oldSettings = $building->settings->toArray();
        $building->forceFill([
            'settings' => $settings,
            'manager_name' => $data['manager_name'] ?? null,
            'manager_emergency_phone' => $data['manager_emergency_phone'] ?? null,
            'default_locale' => $data['default_locale'] ?? 'lv',
        ])->save();

        $changed = array_keys(array_filter(
            $settings->toArray(),
            fn ($value, $key) => ($oldSettings[$key] ?? null) !== $value,
            ARRAY_FILTER_USE_BOTH,
        ));
        AuditLogger::record('settings.update', $building, data: ['changed' => $changed]);

        CoreGateMonitor::afterChange($building, $before);

        Notification::make()->success()->title(__('admin.settings.saved'))->send();
    }

    public function gateStatus(): CoreGateStatus
    {
        return CoreGate::status(CurrentBuilding::get());
    }

    public function building(): Building
    {
        return CurrentBuilding::get();
    }
}
