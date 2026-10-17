<?php

declare(strict_types=1);

namespace App\Filament\Platform\Pages;

use App\Audit\AuditLogger;
use App\Models\Building;
use App\Models\User;
use BackedEnum;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * Manual per-building PIN-login pause after a `pin-global` alert (MVP-SPEC A.6): the global monitor never
 * blocks; the platform admin decides. E-mail login is unaffected. Audited in the platform chain.
 */
final class PinPausePage extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPauseCircle;

    protected static ?int $navigationSort = 31;

    protected static ?string $slug = 'pin-pause';

    protected string $view = 'filament.platform.pages.pin-pause';

    public static function getNavigationGroup(): string
    {
        return __('admin.nav.platform');
    }

    public static function getNavigationLabel(): string
    {
        return __('admin.pin_pause.title');
    }

    public function getTitle(): string
    {
        return __('admin.pin_pause.title');
    }

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user instanceof User && $user->isPlatformAdmin();
    }

    public function pause(int $buildingId, int $hours): void
    {
        abort_unless(self::canAccess(), 403);
        $building = Building::query()->findOrFail($buildingId);
        $hours = max(1, min(72, $hours));

        $building->forceFill(['settings' => $building->settings->with(['pin_paused_until' => now()->addHours($hours)->toIso8601String()])])->save();
        AuditLogger::record('platform.pin_pause', $building, data: ['hours' => $hours], platform: true, actorRole: 'platform_admin');
        Notification::make()->success()->title(__('admin.pin_pause.paused'))->send();
    }

    public function resume(int $buildingId): void
    {
        abort_unless(self::canAccess(), 403);
        $building = Building::query()->findOrFail($buildingId);

        $building->forceFill(['settings' => $building->settings->with(['pin_paused_until' => null])])->save();
        Cache::forget('pin-building-paused:'.$building->id);
        AuditLogger::record('platform.pin_resume', $building, platform: true, actorRole: 'platform_admin');
        Notification::make()->success()->title(__('admin.pin_pause.resumed'))->send();
    }

    /**
     * @return Collection<int, array{id: int, name: string, paused: bool, auto: bool, until: ?string}>
     */
    public function rows(): Collection
    {
        return Building::query()->orderBy('name')->get()->map(fn (Building $b): array => [
            'id' => (int) $b->id,
            'name' => $b->name,
            'paused' => $b->settings->pinPaused(),
            'auto' => Cache::has('pin-building-paused:'.$b->id),
            'until' => $b->settings->pinPausedUntil,
        ]);
    }
}
