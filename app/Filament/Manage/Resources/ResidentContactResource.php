<?php

declare(strict_types=1);

namespace App\Filament\Manage\Resources;

use App\Auth\StepUpService;
use App\Enums\ContactRelation;
use App\Filament\Manage\Resources\Concerns\ElderSectionScope;
use App\Filament\Manage\Resources\ResidentContactResource\Pages;
use App\Gdpr\RosterGate;
use App\Models\Apartment;
use App\Models\ResidentContact;
use App\Models\Section;
use App\Models\User;
use App\Services\Roster\RosterActionFailed;
use App\Services\Roster\RosterViewer;
use App\Support\PhoneNormalizer;
use App\Tenancy\CurrentBuilding;
use BackedEnum;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\ToggleButtons;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ViewColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Grouping\Group;
use Filament\Tables\Table;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use Livewire\Component;

/**
 * "Iedzīvotāju saraksts" (ADMIN-007, ROUND2-SPEC §5.3) — the house's admin-only contact register (decision D1).
 *
 * The list is one row per APARTMENT grouped by stairwell (apartments without a contact are listed too, so gaps are
 * visible), contacts stacked inside the row with MASKED phones / e-mails. "Rādīt kontaktus" unmasks one apartment
 * (mfa step-up, audited, 100 per day); "Labot" opens one contact (counts as a reveal). Building admins see every
 * stairwell, section elders only their own (404 for others); nobody else reaches the pages. Below gate L-R the page
 * shows why the roster is closed instead of any data. Global search is off.
 */
final class ResidentContactResource extends Resource
{
    use ElderSectionScope;

    protected static ?string $model = ResidentContact::class;

    protected static ?string $slug = 'roster';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedIdentification;

    protected static ?int $navigationSort = 12;

    protected static bool $isGloballySearchable = false;

    public static function getNavigationGroup(): string
    {
        return __('admin.nav.people');
    }

    public static function getNavigationLabel(): string
    {
        return __('roster.title');
    }

    public static function getModelLabel(): string
    {
        return __('roster.contact.label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('roster.contact.plural');
    }

    public static function canViewAny(): bool
    {
        return Gate::allows('openPage', ResidentContact::class);
    }

    public static function canCreate(): bool
    {
        return RosterGate::isOpen() && Gate::allows('create', ResidentContact::class);
    }

    public static function canEdit(Model $record): bool
    {
        return $record instanceof ResidentContact && Gate::allows('update', $record);
    }

    public static function canDelete(Model $record): bool
    {
        return $record instanceof ResidentContact && Gate::allows('delete', $record);
    }

    /**
     * Contacts the user may open: building_admin all, an elder only their stairwells (route binding → 404 for others);
     * nothing while gate L-R is closed.
     *
     * @return Builder<ResidentContact>
     */
    public static function getEloquentQuery(): Builder
    {
        /** @var Builder<ResidentContact> $query */
        $query = parent::getEloquentQuery();

        if (! RosterGate::isOpen()) {
            return $query->whereRaw('false');
        }

        return self::limitToElderSections($query);
    }

    /**
     * The list's rows: active apartments of the stairwells the user may see.
     *
     * @return Builder<Apartment>
     */
    public static function apartmentQuery(): Builder
    {
        $query = Apartment::query()->active();

        if (! RosterGate::isOpen() || ! Gate::allows('viewAny', ResidentContact::class)) {
            return $query->whereRaw('false');
        }

        return self::limitToElderSections($query, 'section_id');
    }

    /**
     * @return list<int>
     */
    public static function visibleSectionIds(): array
    {
        $user = auth()->user();
        if (! $user instanceof User) {
            return [];
        }
        $building = CurrentBuilding::get();
        $query = Section::query()->orderBy('number');
        if (! $user->isBuildingAdmin($building)) {
            $query->whereIn('id', $user->elderSectionIds($building));
        }

        return $query->pluck('id')->map(static fn ($id): int => (int) $id)->all();
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('apartment_id')->label(__('roster.contact.apartment'))
                ->options(fn (): array => self::apartmentQuery()->orderBy('number')->pluck('number', 'id')->all())
                ->searchable()->required()
                ->visibleOn('create'),
            Select::make('relation')->label(__('roster.contact.relation'))->options(ContactRelation::options())->default('contact')->required(),
            TextInput::make('name')->label(__('roster.contact.name'))->maxLength((int) config('majas.roster.name_max'))->autocomplete(false),
            Repeater::make('phones')->label(__('roster.contact.phones'))
                ->simple(TextInput::make('number')->label(__('roster.contact.phone'))->tel()->maxLength(32)->autocomplete(false)
                    ->placeholder('+371 …')
                    ->rule(static fn (): Closure => static function (string $attribute, mixed $value, Closure $fail): void {
                        if (trim((string) $value) !== '' && PhoneNormalizer::normalize((string) $value) === null) {
                            $fail(__('roster.error.phone_invalid'));
                        }
                    }))
                ->maxItems((int) config('majas.roster.max_phones'))
                ->defaultItems(0)
                ->addActionLabel(__('roster.contact.add_phone'))
                ->helperText(__('roster.contact.phone_help')),
            TextInput::make('email')->label(__('roster.contact.email'))->email()->maxLength(254)->autocomplete(false),
            ToggleButtons::make('bis_registered')->label(__('roster.contact.bis'))->inline()->options(self::flagOptions())->default(''),
            ToggleButtons::make('whatsapp_member')->label(__('roster.contact.whatsapp'))->inline()->options(self::flagOptions())->default(''),
            Textarea::make('comment')->label(__('roster.contact.comment'))->maxLength((int) config('majas.roster.comment_max'))->rows(3)
                ->helperText(__('roster.contact.comment_help'))->columnSpanFull(),
        ])->columns(['default' => 1, 'md' => 2]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('number')
            ->defaultGroup(Group::make('section_id')->label(__('roster.list.stairwell'))
                ->getTitleFromRecordUsing(fn (Apartment $record): string => __('roster.list.stairwell_n', ['n' => $record->section->number]))
                ->collapsible())
            ->groupingSettingsHidden()
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['section:id,number', 'residentContacts']))
            ->recordUrl(fn (): ?string => null)
            ->columns([
                TextColumn::make('number')->label(__('roster.list.apartment'))->sortable(),
                TextColumn::make('floor')->label(__('roster.list.floor'))->visibleFrom('md'),
                ViewColumn::make('contacts')->label(__('roster.list.contacts'))->view('filament.manage.roster.contacts-cell'),
            ])
            ->filters(self::filters())
            ->recordActions([
                Action::make('reveal')
                    ->label(__('roster.reveal.action'))
                    ->icon(Heroicon::OutlinedEye)
                    ->color('warning')
                    ->visible(fn (Apartment $record): bool => $record->residentContacts->isNotEmpty() && Gate::allows('revealApartment', [ResidentContact::class, $record]))
                    ->mountUsing(function (Apartment $record, Component $livewire, Action $action): void {
                        $user = self::actor();
                        if (! app(StepUpService::class)->hasFreshMfa($user)) {
                            $livewire->redirect(app(StepUpService::class)->ask('mfa', session()->previousUrl()));
                            $action->cancel();

                            return;
                        }
                        self::$revealed[(int) $record->id] = self::revealFor($user, $record);
                    })
                    ->modalHeading(fn (Apartment $record): string => __('roster.reveal.heading', ['apartment' => $record->number]))
                    ->modalDescription(__('roster.reveal.logged'))
                    ->modalContent(fn (Apartment $record): View => view('filament.manage.roster.reveal', ['result' => self::$revealed[(int) $record->id] ?? null]))
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel(__('common.close')),
                Action::make('addContact')
                    ->label(__('roster.list.add'))
                    ->icon(Heroicon::OutlinedPlus)
                    ->color('gray')
                    ->visible(fn (Apartment $record): bool => Gate::allows('create', [ResidentContact::class, $record]))
                    ->url(fn (Apartment $record): string => self::getUrl('create', ['apartment' => $record->id])),
            ])
            ->emptyStateHeading(__('roster.list.empty'))
            ->paginated([25, 50, 100])
            ->defaultPaginationPageOption(50);
    }

    /** @var array<int, array{contacts?: list<array<string, mixed>>, error?: string}> revealed in THIS request only */
    public static array $revealed = [];

    /**
     * @return array{contacts?: list<array<string, mixed>>, error?: string}
     */
    private static function revealFor(User $user, Apartment $apartment): array
    {
        try {
            return ['contacts' => app(RosterViewer::class)->reveal($user, $apartment)];
        } catch (RosterActionFailed $e) {
            return ['error' => $e->label()];
        }
    }

    /**
     * @return list<SelectFilter|TernaryFilter|Filter>
     */
    private static function filters(): array
    {
        $contacts = static fn (Builder $q, Closure $where): Builder => $q->whereHas('residentContacts', $where);

        return [
            SelectFilter::make('section_id')->label(__('roster.list.stairwell'))
                ->options(fn (): array => Section::query()->whereIn('id', self::visibleSectionIds())->orderBy('number')
                    ->get(['id', 'number'])->mapWithKeys(fn (Section $s): array => [$s->id => __('roster.list.stairwell_n', ['n' => $s->number])])->all())
                ->default(fn (): ?int => count(self::visibleSectionIds()) === 1 && ! self::actor()->isBuildingAdmin(CurrentBuilding::get()) ? self::visibleSectionIds()[0] : null),
            SelectFilter::make('floor')->label(__('roster.list.floor'))
                ->options(fn (): array => self::apartmentQuery()->distinct()->orderBy('floor')->pluck('floor', 'floor')->all()),
            SelectFilter::make('relation')->label(__('roster.contact.relation'))->options(ContactRelation::options())
                ->query(fn (Builder $query, array $data): Builder => filled($data['value'] ?? null) ? $contacts($query, fn (Builder $c) => $c->where('relation', $data['value'])) : $query),
            self::flagFilter('bis', 'bis_registered', __('roster.contact.bis')),
            self::flagFilter('whatsapp', 'whatsapp_member', __('roster.contact.whatsapp')),
            TernaryFilter::make('has_phone')->label(__('roster.filter.has_phone'))
                ->queries(
                    true: fn (Builder $query) => $contacts($query, fn (Builder $c) => $c->whereNotNull('phones_ciphertext')),
                    false: fn (Builder $query) => $query->whereDoesntHave('residentContacts', fn (Builder $c) => $c->whereNotNull('phones_ciphertext')),
                ),
            TernaryFilter::make('has_email')->label(__('roster.filter.has_email'))
                ->queries(
                    true: fn (Builder $query) => $contacts($query, fn (Builder $c) => $c->whereNotNull('email_ciphertext')),
                    false: fn (Builder $query) => $query->whereDoesntHave('residentContacts', fn (Builder $c) => $c->whereNotNull('email_ciphertext')),
                ),
            Filter::make('no_contact')->label(__('roster.filter.no_contact'))->toggle()
                ->query(fn (Builder $query) => $query->whereDoesntHave('residentContacts', fn (Builder $c) => $c->whereNull('moved_out_at'))),
            Filter::make('not_reviewed')->label(__('roster.filter.not_reviewed'))->toggle()
                ->query(fn (Builder $query) => $contacts($query, fn (Builder $c) => $c->whereNull('moved_out_at')
                    ->where(fn (Builder $r) => $r->whereNull('reviewed_at')->orWhere('reviewed_at', '<', now()->subMonths(12))))),
            Filter::make('linked')->label(__('roster.filter.linked'))->toggle()
                ->query(fn (Builder $query) => $contacts($query, fn (Builder $c) => $c->whereNotNull('user_id'))),
            Filter::make('moved_out')->label(__('roster.filter.moved_out'))->toggle()
                ->query(fn (Builder $query) => $contacts($query, fn (Builder $c) => $c->whereNotNull('moved_out_at'))),
        ];
    }

    private static function flagFilter(string $name, string $column, string $label): SelectFilter
    {
        return SelectFilter::make($name)->label($label)
            ->options(['yes' => __('roster.flag.yes'), 'no' => __('roster.flag.no'), 'unknown' => __('roster.flag.unknown')])
            ->query(fn (Builder $query, array $data): Builder => match ($data['value'] ?? null) {
                'yes' => $query->whereHas('residentContacts', fn (Builder $c) => $c->where($column, true)),
                'no' => $query->whereHas('residentContacts', fn (Builder $c) => $c->where($column, false)),
                'unknown' => $query->whereHas('residentContacts', fn (Builder $c) => $c->whereNull($column)),
                default => $query,
            });
    }

    /**
     * @return array<int|string, string>
     */
    public static function flagOptions(): array
    {
        return ['1' => __('roster.flag.yes'), '0' => __('roster.flag.no'), '' => __('roster.flag.unknown')];
    }

    public static function actor(): User
    {
        /** @var User $user */
        $user = auth()->user();

        return $user;
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListResidentContacts::route('/'),
            'create' => Pages\CreateResidentContact::route('/create'),
            'edit' => Pages\EditResidentContact::route('/{record}/edit'),
        ];
    }
}
