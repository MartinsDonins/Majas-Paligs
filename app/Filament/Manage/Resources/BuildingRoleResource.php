<?php

declare(strict_types=1);

namespace App\Filament\Manage\Resources;

use App\Enums\BuildingRoleType;
use App\Filament\Manage\Resources\BuildingRoleResource\Pages;
use App\Models\Apartment;
use App\Models\ApartmentMembership;
use App\Models\BuildingRole;
use App\Models\Section;
use App\Models\User;
use App\Services\Memberships\BuildingRoleService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;

/**
 * Delegated roles (building_admin, section_elder, parking_officer, volunteer). Candidates are approved members
 * of THIS building only (scoped select), shown by apartment + role — never by name.
 */
final class BuildingRoleResource extends Resource
{
    protected static ?string $model = BuildingRole::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedKey;

    protected static ?int $navigationSort = 11;

    protected static bool $isGloballySearchable = false;

    public static function getNavigationGroup(): string
    {
        return __('admin.nav.people');
    }

    public static function getModelLabel(): string
    {
        return __('admin.role.label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('admin.role.plural');
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('granted_at', 'desc')
            ->modifyQueryUsing(fn (Builder $query) => $query->with('section'))
            ->columns([
                TextColumn::make('role')->label(__('admin.role.role'))->formatStateUsing(fn (BuildingRoleType $state) => $state->label())->badge(),
                TextColumn::make('section.number')->label(__('admin.section.label')),
                TextColumn::make('holder')->label(__('admin.role.holder'))
                    ->getStateUsing(fn (BuildingRole $record): string => self::holderLabel((int) $record->user_id)),
                TextColumn::make('mandate_ref')->label(__('admin.role.mandate')),
                TextColumn::make('expires_at')->label(__('admin.role.expires'))->date('d.m.Y', 'Europe/Riga')->sortable(),
                TextColumn::make('revoked_at')->label(__('admin.role.revoked'))->date('d.m.Y', 'Europe/Riga'),
            ])
            ->filters([
                TernaryFilter::make('revoked_at')->label(__('admin.role.revoked'))->nullable()->default(false),
            ])
            ->headerActions([
                Action::make('grant')
                    ->label(__('admin.role.grant'))
                    ->icon(Heroicon::OutlinedKey)
                    ->visible(fn (): bool => Gate::allows('create', BuildingRole::class))
                    ->schema([
                        Select::make('user_id')->label(__('admin.role.holder'))->required()->searchable()
                            ->options(fn (): array => self::candidates()),
                        Select::make('role')->label(__('admin.role.role'))->required()->live()
                            ->options(collect(BuildingRoleType::cases())
                                ->reject(fn (BuildingRoleType $r) => $r === BuildingRoleType::ManagerViewer)
                                ->mapWithKeys(fn (BuildingRoleType $r) => [$r->value => $r->label()])->all()),
                        Select::make('section_id')->label(__('admin.section.label'))
                            ->visible(fn (Get $get): bool => $get('role') === BuildingRoleType::SectionElder->value)
                            ->required(fn (Get $get): bool => $get('role') === BuildingRoleType::SectionElder->value)
                            ->options(fn () => Section::query()->orderBy('number')->pluck('number', 'id')->map(fn ($n) => __('admin.section.short', ['n' => $n]))->all()),
                        TextInput::make('mandate_ref')->label(__('admin.role.mandate'))->maxLength(200),
                    ])
                    ->action(function (array $data): void {
                        Gate::authorize('create', BuildingRole::class);
                        $candidates = self::candidates();
                        abort_unless(array_key_exists((int) $data['user_id'], $candidates), 403);

                        /** @var User $actor */
                        $actor = auth()->user();
                        app(BuildingRoleService::class)->grant(
                            $actor,
                            User::query()->findOrFail((int) $data['user_id']),
                            BuildingRoleType::from((string) $data['role']),
                            isset($data['section_id']) ? (int) $data['section_id'] : null,
                            $data['mandate_ref'] ?? null,
                        );
                        Notification::make()->success()->title(__('admin.role.granted'))->send();
                    }),
            ])
            ->recordActions([
                Action::make('revoke')
                    ->label(__('admin.role.revoke'))
                    ->color('danger')
                    ->visible(fn (BuildingRole $record): bool => Gate::allows('revoke', $record))
                    ->requiresConfirmation()
                    ->action(function (BuildingRole $record): void {
                        Gate::authorize('revoke', $record);
                        /** @var User $actor */
                        $actor = auth()->user();
                        app(BuildingRoleService::class)->revoke($actor, $record);
                    }),
            ]);
    }

    /**
     * Approved members of the current building: user_id => "Dz. 145 · Īpašnieks · e-pasts".
     *
     * @return array<int, string>
     */
    public static function candidates(): array
    {
        $memberships = ApartmentMembership::query()->approved()->with('apartment:id,number', 'user:id,email,anonymised_at')->get();

        $out = [];
        foreach ($memberships as $m) {
            if ($m->user === null || $m->user->isTombstone()) {
                continue;
            }
            $out[(int) $m->user_id] = __('onboarding.role.apartment', ['n' => $m->apartment->number]).' · '.$m->role->label()
                .' · '.($m->user->email !== null ? __('admin.membership.account_email') : __('admin.membership.account_pin'));
        }
        asort($out, SORT_NATURAL);

        return $out;
    }

    private static function holderLabel(int $userId): string
    {
        $m = ApartmentMembership::query()->approved()->where('user_id', $userId)->first();
        $number = $m !== null ? Apartment::query()->whereKey($m->apartment_id)->value('number') : null;

        return $number !== null ? __('onboarding.role.apartment', ['n' => $number]) : __('admin.role.no_apartment');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListBuildingRoles::route('/'),
        ];
    }
}
