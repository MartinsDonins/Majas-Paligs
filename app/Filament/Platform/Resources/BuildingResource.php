<?php

declare(strict_types=1);

namespace App\Filament\Platform\Resources;

use App\Enums\BuildingRoleType;
use App\Enums\BuildingStatus;
use App\Filament\Platform\Pages\BuildingGeneratorPage;
use App\Filament\Platform\Resources\BuildingResource\Pages;
use App\Models\Building;
use App\Models\BuildingSeries;
use App\Models\User;
use App\Platform\PlatformBuildingContext;
use App\Platform\PlatformPurpose;
use App\Services\Memberships\BuildingRoleService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * Buildings (non-tenant columns only). Structure is created by the generator; the first building_admin is
 * bootstrapped here — both inside PlatformBuildingContext (audited platform-chain entry).
 */
final class BuildingResource extends Resource
{
    protected static ?string $model = Building::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingOffice2;

    protected static ?int $navigationSort = 20;

    protected static ?string $recordTitleAttribute = 'name';

    public static function getNavigationGroup(): string
    {
        return __('admin.nav.buildings');
    }

    public static function getModelLabel(): string
    {
        return __('admin.building.label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('admin.building.plural');
    }

    public static function canViewAny(): bool
    {
        return auth()->user() instanceof User && auth()->user()->isPlatformAdmin();
    }

    public static function canCreate(): bool
    {
        return self::canViewAny();
    }

    public static function canEdit(Model $record): bool
    {
        return self::canViewAny();
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()->columns(2)->schema([
                TextInput::make('name')->label(__('admin.building.name'))->required()->maxLength(200),
                TextInput::make('slug')->label(__('admin.building.slug'))->required()->maxLength(64)
                    ->regex('/^[a-z0-9]+(-[a-z0-9]+)*$/')->unique(ignoreRecord: true),
                TextInput::make('address_line')->label(__('admin.building.address'))->required()->maxLength(200),
                TextInput::make('city')->label(__('admin.building.city'))->required()->maxLength(100),
                TextInput::make('postcode')->label(__('admin.building.postcode'))->maxLength(10),
                TextInput::make('address_code')->label(__('admin.building.address_code'))->maxLength(20),
                TextInput::make('cadastre_designation')->label(__('admin.building.cadastre'))->maxLength(20),
                Select::make('series_id')->label(__('admin.series.label'))->options(fn () => BuildingSeries::query()->pluck('code', 'id')->all()),
                TextInput::make('floors')->label(__('admin.section.floors'))->numeric()->required()->minValue(1)->maxValue(30),
                TextInput::make('year_built')->label(__('admin.building.year'))->numeric(),
                TextInput::make('manager_name')->label(__('admin.settings.manager_name'))->maxLength(200),
                Select::make('status')->label(__('admin.building.status'))->options(BuildingStatus::options())->required(),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->label(__('admin.building.name'))->searchable()->sortable(),
                TextColumn::make('slug')->label(__('admin.building.slug')),
                TextColumn::make('city')->label(__('admin.building.city')),
                TextColumn::make('series.code')->label(__('admin.series.label')),
                TextColumn::make('status')->label(__('admin.building.status'))->formatStateUsing(fn (BuildingStatus $state) => $state->label())->badge(),
            ])
            ->recordActions([
                EditAction::make(),
                Action::make('generate')
                    ->label(__('admin.generator.open'))
                    ->icon(Heroicon::OutlinedSparkles)
                    ->url(fn (Building $record): string => BuildingGeneratorPage::getUrl(['building' => $record->id])),
                Action::make('bootstrapAdmin')
                    ->label(__('admin.building.bootstrap_admin'))
                    ->icon(Heroicon::OutlinedKey)
                    ->schema([
                        TextInput::make('email')->label(__('auth.email.label'))->email()->required()->maxLength(254),
                        TextInput::make('mandate_ref')->label(__('admin.role.mandate'))->maxLength(200),
                    ])
                    ->requiresConfirmation()
                    ->action(function (Building $record, array $data): void {
                        /** @var User $actor */
                        $actor = auth()->user();
                        $email = mb_strtolower(trim((string) $data['email']));
                        $user = User::query()->where('email', $email)->whereNull('anonymised_at')->first()
                            ?? User::query()->create(['email' => $email]);

                        PlatformBuildingContext::run($record, PlatformPurpose::RoleBootstrap, function () use ($actor, $user, $data): void {
                            app(BuildingRoleService::class)->grant($actor, $user, BuildingRoleType::BuildingAdmin, null, $data['mandate_ref'] ?? null);
                        });

                        Notification::make()->success()->title(__('admin.role.granted'))->send();
                    }),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListBuildings::route('/'),
            'create' => Pages\CreateBuilding::route('/create'),
            'edit' => Pages\EditBuilding::route('/{record}/edit'),
        ];
    }
}
