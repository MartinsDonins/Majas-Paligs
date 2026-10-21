<?php

declare(strict_types=1);

namespace App\Filament\Manage\Pages;

use App\Auth\StepUpService;
use App\Enums\BuildingRoleType;
use App\Enums\Locale;
use App\Filament\Manage\Resources\BuildingRoleResource;
use App\Models\BuildingRole;
use App\Models\RoleInvitation;
use App\Models\Section;
use App\Models\User;
use App\Services\Memberships\BuildingRoleService;
use App\Services\Team\TeamInvitationRefused;
use App\Services\Team\TeamInvitationService;
use App\Tenancy\CurrentBuilding;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Icons\Heroicon;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * "Komanda" — who helps run the house (ROUND2-SPEC §2.3, ADMIN-006): current role holders (role, stairwells, holder,
 * second-factor status, expiry), pending invitations, and one sentence per role. building_admin invites by e-mail
 * (strong step-up), resends (strong), revokes (mfa), re-confirms and removes holders; elders and parking officers see
 * the same lists read-only with e-mail hints only. The last building_admin can never be removed here — hand over first.
 */
final class TeamPage extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUserGroup;

    protected static ?int $navigationSort = 9;

    protected static ?string $slug = 'team';

    protected string $view = 'filament.manage.pages.team';

    public static function getNavigationGroup(): string
    {
        return __('admin.nav.people');
    }

    public static function getNavigationLabel(): string
    {
        return __('admin.team.title');
    }

    public function getTitle(): string
    {
        return __('admin.team.title');
    }

    public function getSubheading(): string
    {
        return __('admin.team.lead');
    }

    public static function canAccess(): bool
    {
        return CurrentBuilding::find() !== null && Gate::allows('viewAny', RoleInvitation::class);
    }

    public function mount(): void
    {
        abort_unless(self::canAccess(), 403);
    }

    public function canEdit(): bool
    {
        return Gate::allows('create', RoleInvitation::class);
    }

    /**
     * @return list<array{id: int, role: BuildingRoleType, section: ?int, holder: string, protected: bool, expires: string, self: bool, canRemove: bool, canReconfirm: bool}>
     */
    public function holders(): array
    {
        $fullEmail = Gate::allows('viewEmail', RoleInvitation::class);
        $me = (int) auth()->id();

        return BuildingRole::query()->current()
            ->where('role', '!=', BuildingRoleType::ManagerViewer->value)
            ->with(['section:id,number', 'user' => fn ($q) => $q->withCount('passkeys')])
            ->orderByRaw("CASE role WHEN 'building_admin' THEN 0 WHEN 'section_elder' THEN 1 WHEN 'parking_officer' THEN 2 ELSE 3 END")
            ->orderBy('section_id')
            ->get()
            ->map(function (BuildingRole $r) use ($fullEmail, $me): array {
                $u = $r->user;
                $email = $u->email;

                return [
                    'id' => (int) $r->id,
                    'role' => $r->role,
                    'section' => $r->section !== null ? (int) $r->section->number : null,
                    'holder' => $u->display_name ?? ($email === null ? __('admin.team.no_email') : ($fullEmail ? $email : RoleInvitation::hint($email))),
                    'protected' => (int) ($u->passkeys_count ?? 0) > 0 || $u->hasTotp(),
                    'expires' => $r->expires_at->setTimezone('Europe/Riga')->format('d.m.Y'),
                    'self' => (int) $r->user_id === $me,
                    'canRemove' => Gate::allows('revoke', $r),
                    'canReconfirm' => Gate::allows('reconfirm', $r),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * Keys: id, email (full for building_admin, else the hint), role, sections, sent, days (left), canEdit.
     *
     * @return list<array<string, mixed>>
     */
    public function pending(): array
    {
        $fullEmail = Gate::allows('viewEmail', RoleInvitation::class);
        $sections = Section::query()->pluck('number', 'id');

        return RoleInvitation::query()->pending()->orderByDesc('created_at')->get()
            ->map(fn (RoleInvitation $i): array => [
                'id' => (string) $i->id,
                'email' => $fullEmail ? $i->email() : $i->email_hint,
                'role' => $i->role,
                'sections' => (string) collect($i->section_ids)->map(fn ($id): string => (string) __('admin.section.short', ['n' => $sections[$id] ?? '?']))->join(', '),
                'sent' => ($i->resent_at ?? $i->created_at)->setTimezone('Europe/Riga')->format('d.m.Y'),
                'days' => max(1, (int) ceil(now()->diffInHours($i->expires_at) / 24)),
                'canEdit' => Gate::allows('revoke', $i),
            ])
            ->values()
            ->all();
    }

    /**
     * Elders per stairwell ("5. k.t.: nav vecākā") for the empty-state hint.
     *
     * @return list<int> section numbers without an elder
     */
    public function stairwellsWithoutElder(): array
    {
        $covered = BuildingRole::query()->current()->where('role', BuildingRoleType::SectionElder->value)->pluck('section_id')->all();

        return Section::query()->whereNotIn('id', $covered)->orderBy('number')->pluck('number')->map(fn ($n): int => (int) $n)->all();
    }

    public function grantExistingUrl(): ?string
    {
        return Gate::allows('create', BuildingRole::class) ? BuildingRoleResource::getUrl('index', tenant: CurrentBuilding::get()) : null;
    }

    /**
     * @return list<Action>
     */
    protected function getHeaderActions(): array
    {
        return [$this->inviteAction()];
    }

    public function inviteAction(): Action
    {
        return Action::make('invite')
            ->label(__('admin.team.invite'))
            ->icon(Heroicon::OutlinedEnvelope)
            ->visible(fn (): bool => $this->canEdit())
            ->modalHeading(__('admin.team.invite_heading'))
            ->modalDescription(__('admin.team.invite_help'))
            ->schema([
                TextInput::make('email')->label(__('auth.email.label'))->email()->required()->maxLength(254),
                Select::make('role')->label(__('admin.role.role'))->required()->live()
                    ->options(self::roleOptions()),
                CheckboxList::make('section_ids')->label(__('admin.section.plural'))
                    ->visible(fn (Get $get): bool => $get('role') === BuildingRoleType::SectionElder->value)
                    ->required(fn (Get $get): bool => $get('role') === BuildingRoleType::SectionElder->value)
                    ->options(fn (): array => Section::query()->orderBy('number')->pluck('number', 'id')
                        ->map(fn ($n) => __('admin.section.short', ['n' => $n]))->all())
                    ->columns(3),
                TextInput::make('mandate_ref')->label(__('admin.role.mandate'))->maxLength(200)
                    ->helperText(__('admin.team.mandate_help')),
                Select::make('locale')->label(__('admin.team.mail_locale'))->required()
                    ->options(['lv' => 'Latviešu', 'ru' => 'Русский'])
                    ->default(fn (): string => app()->getLocale() === 'ru' ? 'ru' : 'lv'),
            ])
            ->action(function (array $data): void {
                $actor = $this->actor();

                // Offering a role to someone else: one passkey tap / TOTP code of this session (ADR 0006).
                if (! app(StepUpService::class)->demandStrong($actor, $this)) {
                    return;
                }

                $this->run(fn () => app(TeamInvitationService::class)->invite(
                    $actor,
                    (string) $data['email'],
                    BuildingRoleType::from((string) $data['role']),
                    array_map('intval', (array) ($data['section_ids'] ?? [])),
                    $data['mandate_ref'] ?? null,
                    Locale::from((string) ($data['locale'] ?? 'lv')),
                ), __('admin.team.invited'));
            });
    }

    public function resendAction(): Action
    {
        return Action::make('resend')
            ->label(__('admin.team.resend'))
            ->icon(Heroicon::OutlinedArrowPath)
            ->color('gray')
            ->size('sm')
            ->requiresConfirmation()
            ->modalDescription(__('admin.team.resend_help'))
            ->action(function (array $arguments): void {
                $actor = $this->actor();
                if (! app(StepUpService::class)->demandStrong($actor, $this)) {
                    return;
                }
                $invitation = RoleInvitation::query()->findOrFail((string) ($arguments['id'] ?? ''));
                $this->run(fn () => app(TeamInvitationService::class)->resend($actor, $invitation), __('admin.team.resent'));
            });
    }

    public function revokeInviteAction(): Action
    {
        return Action::make('revokeInvite')
            ->label(__('admin.team.revoke_invite'))
            ->icon(Heroicon::OutlinedXMark)
            ->color('danger')
            ->size('sm')
            ->requiresConfirmation()
            ->action(function (array $arguments): void {
                $invitation = RoleInvitation::query()->findOrFail((string) ($arguments['id'] ?? ''));
                $this->run(fn () => app(TeamInvitationService::class)->revoke($this->actor(), $invitation), __('admin.team.revoked_invite'));
            });
    }

    public function reconfirmAction(): Action
    {
        return Action::make('reconfirm')
            ->label(__('admin.team.reconfirm'))
            ->icon(Heroicon::OutlinedCalendarDays)
            ->color('gray')
            ->size('sm')
            ->requiresConfirmation()
            ->modalDescription(__('admin.team.reconfirm_help'))
            ->action(function (array $arguments): void {
                $role = BuildingRole::query()->current()->findOrFail((int) ($arguments['id'] ?? 0));
                Gate::authorize('reconfirm', $role);
                $this->run(fn () => app(BuildingRoleService::class)->reconfirm($this->actor(), $role), __('admin.team.reconfirmed'));
            });
    }

    public function removeAction(): Action
    {
        return Action::make('remove')
            ->label(fn (array $arguments): string => ($arguments['self'] ?? false) ? __('admin.team.resign') : __('admin.team.remove'))
            ->icon(Heroicon::OutlinedUserMinus)
            ->color('danger')
            ->size('sm')
            ->requiresConfirmation()
            ->modalDescription(fn (array $arguments): string => ($arguments['self'] ?? false) ? __('admin.team.resign_help') : __('admin.team.remove_help'))
            ->action(function (array $arguments): void {
                $role = BuildingRole::query()->current()->findOrFail((int) ($arguments['id'] ?? 0));
                Gate::authorize('revoke', $role);
                $self = (int) $role->user_id === (int) auth()->id();
                $this->run(fn () => app(BuildingRoleService::class)->revoke($this->actor(), $role), __('admin.team.removed'));

                if ($self) {
                    $this->redirect(route('admin.entry'));
                }
            });
    }

    /**
     * @return array<string, string>
     */
    public static function roleOptions(): array
    {
        return collect([BuildingRoleType::BuildingAdmin, BuildingRoleType::SectionElder, BuildingRoleType::ParkingOfficer, BuildingRoleType::Volunteer])
            ->mapWithKeys(fn (BuildingRoleType $r): array => [$r->value => $r->label()])
            ->all();
    }

    /**
     * Run a team action and turn refusals into a calm notification (never an exception page).
     */
    private function run(callable $callback, string $success): void
    {
        try {
            $callback();
        } catch (TeamInvitationRefused $e) {
            Notification::make()->warning()->title($e->getMessage())->send();

            return;
        } catch (ValidationException $e) {
            Notification::make()->warning()->title(collect($e->errors())->flatten()->first() ?? __('admin.team.error.not_open'))->send();

            return;
        } catch (AuthorizationException) {
            Notification::make()->danger()->title(__('admin.team.error.step_up'))->send();

            return;
        }

        Notification::make()->success()->title($success)->send();
    }

    private function actor(): User
    {
        /** @var User $user */
        $user = auth()->user();

        return $user;
    }
}
