<?php

declare(strict_types=1);

namespace App\Filament\Platform\Pages;

use App\Audit\AuditChainVerifier;
use App\Models\AuditEntry;
use App\Models\Building;
use App\Models\User;
use App\Platform\BreakGlass;
use BackedEnum;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Collection;
use InvalidArgumentException;

/**
 * Platform audit: the platform chain (always visible to platform admins) and a building chain only through
 * BREAK-GLASS (reason ≥ 10 chars, fresh TOTP, audited in both chains, READ ONLY transaction).
 */
final class AuditPage extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentList;

    protected static ?int $navigationSort = 50;

    protected static ?string $slug = 'audit';

    protected string $view = 'filament.platform.pages.audit';

    public ?int $buildingId = null;

    public string $reason = '';

    /** @var list<array{id: int, time: string, action: string, actor: ?string, reason: ?string}> */
    public array $buildingEntries = [];

    public static function getNavigationGroup(): string
    {
        return __('admin.nav.platform');
    }

    public static function getNavigationLabel(): string
    {
        return __('admin.audit.platform_title');
    }

    public function getTitle(): string
    {
        return __('admin.audit.platform_title');
    }

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user instanceof User && $user->isPlatformAdmin();
    }

    /**
     * @return Collection<int, AuditEntry>
     */
    public function platformEntries(): Collection
    {
        return AuditEntry::query()->platformChain()->orderByDesc('id')->limit(100)->get();
    }

    public function verifyPlatform(AuditChainVerifier $verifier): void
    {
        $r = $verifier->verify(0);
        $r->valid
            ? Notification::make()->success()->title(__('admin.audit.valid', ['count' => $r->checked]))->send()
            : Notification::make()->danger()->title(__('admin.audit.invalid', ['id' => $r->failedAtId, 'reason' => $r->reason]))->persistent()->send();
    }

    public function breakGlass(): void
    {
        abort_unless(self::canAccess(), 403);
        /** @var User $admin */
        $admin = auth()->user();
        $building = $this->buildingId !== null ? Building::query()->find($this->buildingId) : null;

        if ($building === null) {
            return;
        }

        try {
            $this->buildingEntries = BreakGlass::read($admin, $building, $this->reason, static fn (Building $b): array => AuditEntry::query()
                ->forBuilding((int) $b->id)->orderByDesc('id')->limit(200)->get()
                ->map(fn (AuditEntry $e): array => [
                    'id' => (int) $e->id,
                    'time' => $e->created_at->timezone('Europe/Riga')->format('d.m.Y H:i:s'),
                    'action' => $e->action,
                    'actor' => $e->actor_role,
                    'reason' => $e->reason_code,
                ])->all());
            $this->reason = '';
        } catch (InvalidArgumentException $e) {
            Notification::make()->danger()->title(__('admin.audit.reason_required'))->send();
        }
    }

    /**
     * @return array<int, string>
     */
    public function buildings(): array
    {
        return Building::query()->orderBy('name')->pluck('name', 'id')->all();
    }
}
