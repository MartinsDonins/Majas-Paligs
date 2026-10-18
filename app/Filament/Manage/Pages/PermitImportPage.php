<?php

declare(strict_types=1);

namespace App\Filament\Manage\Pages;

use App\Models\User;
use App\Services\Parking\PermitImportService;
use BackedEnum;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Gate;

/**
 * Permit issuer list import (PARK-001.2) — ONLY at parking gate L1 (controller named + DPA covering parking) and
 * with the permit issuer's endorsement. Paste CSV; the report lists counts and skipped row numbers with reasons only
 * (never a plate). Audit-logged.
 */
final class PermitImportPage extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowUpTray;

    protected static ?int $navigationSort = 56;

    protected static ?string $slug = 'permit-import';

    protected string $view = 'filament.manage.pages.permit-import';

    public string $csv = '';

    public bool $endorsed = false;

    /** @var array{permits: int, vehicles: int, skipped: list<array{row: int, reason: string}>}|null */
    public ?array $report = null;

    public static function getNavigationGroup(): string
    {
        return __('admin.nav.parking');
    }

    public static function getNavigationLabel(): string
    {
        return __('admin.parking.import.title');
    }

    public function getTitle(): string
    {
        return __('admin.parking.import.title');
    }

    public static function canAccess(): bool
    {
        return Gate::allows('parking-import');
    }

    public function mount(): void
    {
        abort_unless(self::canAccess(), 403);
    }

    public function import(PermitImportService $imports): void
    {
        abort_unless(self::canAccess(), 403);

        if (! $this->endorsed) {
            Notification::make()->warning()->title(__('admin.parking.import.endorse_first'))->send();

            return;
        }

        if (strlen($this->csv) > 200_000 || trim($this->csv) === '') {
            Notification::make()->danger()->title(__('admin.parking.import.empty'))->send();

            return;
        }

        /** @var User $user */
        $user = auth()->user();
        $this->report = $imports->import($user, $this->csv);
        $this->csv = '';

        Notification::make()->success()->title(__('admin.parking.import.done', ['permits' => $this->report['permits']]))->send();
    }
}
