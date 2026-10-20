<?php

declare(strict_types=1);

namespace App\Filament\Manage\Resources;

use App\Enums\MembershipRole;
use App\Enums\MembershipStatus;
use App\Filament\Manage\Resources\Concerns\ElderSectionScope;
use App\Filament\Manage\Resources\MembershipResource\Pages;
use App\Models\Apartment;
use App\Models\ApartmentMembership;
use App\Models\User;
use App\Services\Memberships\MembershipService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Enums\RecordActionsPosition;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;

/**
 * Memberships: approve / reject / end (shared MembershipService + policy). Residents are shown by apartment,
 * role and account type only — no names; e-mails are not displayed. Section elders see their own stairwells only.
 */
final class MembershipResource extends Resource
{
    use ElderSectionScope;

    protected static ?string $model = ApartmentMembership::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUserGroup;

    protected static ?int $navigationSort = 10;

    protected static bool $isGloballySearchable = false;

    public static function getNavigationGroup(): string
    {
        return __('admin.nav.people');
    }

    public static function getModelLabel(): string
    {
        return __('admin.membership.label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('admin.membership.plural');
    }

    public static function getNavigationBadge(): ?string
    {
        /** @var Builder<ApartmentMembership> $query */
        $query = self::getEloquentQuery();
        $count = $query->pending()->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            // Approve / reject first: on a 390px phone they were at x≈717, behind a sideways scroll.
            ->recordActionsPosition(RecordActionsPosition::BeforeColumns)
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['apartment.section', 'user:id,email,login_handle,anonymised_at']))
            ->columns([
                TextColumn::make('apartment.number')->label(__('admin.apartment.number'))->sortable(),
                TextColumn::make('apartment.section.number')->label(__('admin.section.label')),
                TextColumn::make('role')->label(__('admin.membership.role'))->formatStateUsing(fn (MembershipRole $state) => $state->label()),
                TextColumn::make('status')->label(__('admin.membership.status'))
                    ->formatStateUsing(fn (MembershipStatus $state) => $state->label())
                    ->badge()
                    ->color(fn (MembershipStatus $state) => match ($state) {
                        MembershipStatus::Approved => 'success',
                        MembershipStatus::Pending => 'warning',
                        default => 'gray',
                    }),
                TextColumn::make('account')->label(__('admin.membership.account'))->visibleFrom('md')
                    ->getStateUsing(fn (ApartmentMembership $record): string => self::accountType($record->user)),
                TextColumn::make('created_at')->label(__('admin.membership.requested'))->dateTime('d.m.Y H:i', 'Europe/Riga')->sortable()->visibleFrom('md'),
                TextColumn::make('approved_at')->label(__('admin.membership.approved_at'))->dateTime('d.m.Y', 'Europe/Riga')->visibleFrom('lg'),
            ])
            ->filters([
                SelectFilter::make('status')->label(__('admin.membership.status'))->options(MembershipStatus::options())->default('pending'),
                SelectFilter::make('role')->label(__('admin.membership.role'))->options(MembershipRole::options()),
            ])
            ->recordActions([
                Action::make('approve')
                    ->label(__('elder.approvals.approve'))
                    ->icon(Heroicon::OutlinedCheck)
                    ->color('success')
                    ->visible(fn (ApartmentMembership $record): bool => Gate::allows('approve', $record))
                    ->requiresConfirmation()
                    ->action(function (ApartmentMembership $record): void {
                        app(MembershipService::class)->approve(self::actor(), $record);
                        Notification::make()->success()->title(__('elder.approvals.approved'))->send();
                    }),
                Action::make('reject')
                    ->label(__('elder.approvals.reject'))
                    ->color('gray')
                    ->visible(fn (ApartmentMembership $record): bool => Gate::allows('reject', $record))
                    ->schema([Textarea::make('reason')->label(__('admin.membership.reason'))->maxLength(200)])
                    ->action(function (ApartmentMembership $record, array $data): void {
                        app(MembershipService::class)->reject(self::actor(), $record, $data['reason'] ?? null);
                        Notification::make()->title(__('elder.approvals.rejected'))->send();
                    }),
                Action::make('end')
                    ->label(__('admin.membership.end'))
                    ->color('danger')
                    ->visible(fn (ApartmentMembership $record): bool => $record->isApproved() && Gate::allows('end', $record))
                    ->requiresConfirmation()
                    ->action(function (ApartmentMembership $record): void {
                        app(MembershipService::class)->end(self::actor(), $record, 'admin_ended');
                        Notification::make()->success()->title(__('admin.membership.ended'))->send();
                    }),
            ]);
    }

    private static function accountType(?User $user): string
    {
        if ($user === null || $user->isTombstone()) {
            return __('privacy.who_viewed.deleted_user');
        }

        return $user->email !== null ? __('admin.membership.account_email') : __('admin.membership.account_pin');
    }

    private static function actor(): User
    {
        /** @var User $user */
        $user = auth()->user();

        return $user;
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListMemberships::route('/'),
        ];
    }

    /**
     * Keep the relation query on the current building's apartments (defence in depth on top of the scope), and
     * limit section elders to their own stairwells (building_admin: whole building).
     */
    public static function getEloquentQuery(): Builder
    {
        return self::limitToElderSections(parent::getEloquentQuery()->whereIn('apartment_id', Apartment::query()->select('id')));
    }
}
