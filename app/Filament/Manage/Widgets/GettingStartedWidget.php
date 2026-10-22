<?php

declare(strict_types=1);

namespace App\Filament\Manage\Widgets;

use App\Models\User;
use App\Services\Opening\GettingStarted;
use App\Tenancy\CurrentBuilding;
use Filament\Widgets\Widget;

/**
 * "Sākt šeit" (FIRSTRUN-001) — the first card on the house dashboard for a building_admin: the getting-started steps
 * with a status chip (icon + word) and one link each (GettingStarted). Collapses to a one-line summary once every
 * required step is done, so it never pushes the daily cards down for long.
 */
final class GettingStartedWidget extends Widget
{
    protected static ?int $sort = -20;

    /** Rendered with the page: it is the first thing a newcomer must see (no lazy placeholder). */
    protected static bool $isLazy = false;

    protected int|string|array $columnSpan = 'full';

    protected string $view = 'filament.manage.widgets.getting-started';

    public static function canView(): bool
    {
        $building = CurrentBuilding::find();
        $user = auth()->user();

        return $building !== null && $user instanceof User && GettingStarted::visibleTo($user, $building);
    }

    /**
     * @return array{steps: list<array{key: string, status: string, detail: string, url: string, action: string}>, progress: array{done: int, total: int}}
     */
    public function data(): array
    {
        /** @var User $user */
        $user = auth()->user();
        $steps = GettingStarted::for(CurrentBuilding::get(), $user);

        return ['steps' => $steps, 'progress' => GettingStarted::progress($steps)];
    }
}
