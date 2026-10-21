<?php

declare(strict_types=1);

namespace App\Filament\Manage\Pages;

use App\Audit\AuditLogger;
use App\Gdpr\CoreGate;
use App\Gdpr\CoreGateStatus;
use App\Models\Apartment;
use App\Models\ApartmentInvite;
use App\Models\Section;
use App\Models\User;
use App\Services\Invites\InviteLetterService;
use App\Tenancy\CurrentBuilding;
use BackedEnum;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\URL;

/**
 * Invite letters (AUTH-002.1): DISABLED with the missing L-core conditions until the gate is open. Printing a
 * batch generates new codes in memory only (previous active codes of those apartments are revoked) and serves
 * the PDF once through a 10-minute signed URL. Audit-logged.
 */
final class InviteBatchPage extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPrinter;

    protected static ?int $navigationSort = 12;

    protected static ?string $slug = 'invites';

    protected string $view = 'filament.manage.pages.invite-batch';

    public string $scope = 'section';

    public ?int $sectionId = null;

    public string $apartments = '';

    public string $elderContact = '';

    public string $order = 'lv';

    public ?string $downloadUrl = null;

    public static function getNavigationGroup(): string
    {
        return __('admin.nav.people');
    }

    public static function getNavigationLabel(): string
    {
        return __('admin.invites.title');
    }

    public function getTitle(): string
    {
        return __('admin.invites.title');
    }

    public static function canAccess(): bool
    {
        $b = CurrentBuilding::find();
        /** @var User|null $user */
        $user = auth()->user();

        return $b !== null && $user !== null && $user->isBuildingAdmin($b);
    }

    public function mount(): void
    {
        abort_unless(self::canAccess(), 403);
        $this->sectionId = Section::query()->orderByDesc('number')->value('id');
    }

    public function gate(): CoreGateStatus
    {
        return CoreGate::status(CurrentBuilding::get());
    }

    public function generate(InviteLetterService $letters): void
    {
        $this->downloadUrl = null;

        if (! Gate::allows('print', ApartmentInvite::class)) {
            Notification::make()->danger()->title(__('admin.invites.blocked'))->send();

            return;
        }

        $ids = $this->selectedApartmentIds();
        if ($ids === []) {
            Notification::make()->warning()->title(__('admin.invites.none_selected'))->send();

            return;
        }

        /** @var User $user */
        $user = auth()->user();
        $batch = $letters->prepare($user, $ids, $this->elderContact !== '' ? $this->elderContact : null, $this->order);

        $this->downloadUrl = URL::temporarySignedRoute(
            'building.invite-letters',
            now()->addMinutes((int) config('majas.exports.signed_minutes', 10)),
            ['building' => CurrentBuilding::get()->slug, 'batch' => $batch],
        );

        Notification::make()->success()->title(__('admin.invites.ready', ['count' => count($ids)]))->send();
    }

    public function revokeAll(): void
    {
        abort_unless(self::canAccess(), 403);
        $count = ApartmentInvite::query()->whereNull('revoked_at')->whereIn('apartment_id', $this->selectedApartmentIds())->update(['revoked_at' => now()]);
        AuditLogger::record('invite.revoke', ['invite_scope', $this->scope], data: ['count' => $count]);
        Notification::make()->success()->title(trans_choice('admin.invites.revoked', $count, ['count' => $count]))->send();
    }

    /**
     * @return list<int>
     */
    private function selectedApartmentIds(): array
    {
        $query = Apartment::query()->active();

        if ($this->scope === 'section' && $this->sectionId !== null) {
            $query->where('section_id', $this->sectionId);
        } elseif ($this->scope === 'numbers') {
            $numbers = array_values(array_filter(array_map('intval', preg_split('/[\s,;]+/', $this->apartments) ?: [])));
            $query->whereIn('number', $numbers === [] ? [-1] : $numbers);
        }

        return $query->orderBy('number')->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    /**
     * @return array<int, int>
     */
    public function sections(): array
    {
        return Section::query()->orderBy('number')->pluck('number', 'id')->all();
    }

    /**
     * Active invite coverage per stairwell (counts only).
     *
     * @return list<array{section: int, apartments: int, active: int}>
     */
    public function coverage(): array
    {
        $out = [];
        foreach (Section::query()->orderBy('number')->get() as $section) {
            $aptIds = Apartment::query()->active()->where('section_id', $section->id)->pluck('id');
            $out[] = [
                'section' => $section->number,
                'apartments' => $aptIds->count(),
                'active' => ApartmentInvite::query()->whereIn('apartment_id', $aptIds)->whereNull('revoked_at')
                    ->where('expires_at', '>', now())->whereColumn('uses', '<', 'max_uses')->distinct()->count('apartment_id'),
            ];
        }

        return $out;
    }
}
