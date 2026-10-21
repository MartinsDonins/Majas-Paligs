<?php

declare(strict_types=1);

namespace App\Filament\Manage\Pages;

use App\Filament\Manage\Pages\Concerns\InteractsWithImportPipeline;
use App\Filament\Manage\Resources\PermitResource;
use App\Gdpr\ParkingGate;
use App\Models\User;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;

/**
 * Permit issuer's list import (PARK-012 on DB-003, ROUND2-SPEC §6.3): the shared 5-step pipeline UI (Augšupielāde →
 * Kartēšana → Pārbaude → Izmēģinājums → Imports, resumable for 24 h) for the kind `permits`.
 *
 * Parking staff reach the page at any level; below parking gate L1 it shows the explanation card with the missing
 * items instead of the uploader, and every action — including the Livewire signed-upload request — is refused
 * server-side (D6). Commit: a dry-run younger than 30 min, the issuer-endorsement confirmation and a fresh MFA
 * step-up. Preview cells mask plates (`AB••34`); no cell value is ever a public property.
 */
final class PermitImportPage extends Page
{
    use InteractsWithImportPipeline;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowUpTray;

    protected static ?int $navigationSort = 56;

    protected static ?string $slug = 'permit-import';

    protected string $view = 'filament.manage.pages.import-pipeline';

    protected static function importKind(): string
    {
        return 'permits';
    }

    public static function getNavigationGroup(): string
    {
        return __('admin.nav.parking');
    }

    public static function getNavigationLabel(): string
    {
        return __('admin.parking.import.title');
    }

    /**
     * Navigation shows the import only from L1 (ROUND2-SPEC §1.2); the status page links here below it.
     */
    public static function shouldRegisterNavigation(): bool
    {
        return (ParkingGate::current()?->l1() ?? false) && auth()->user() instanceof User && self::canAccess();
    }

    public function getTitle(): string
    {
        return __('admin.parking.import.title');
    }

    /**
     * @return array<string, string>
     */
    public function getBreadcrumbs(): array
    {
        return [PermitResource::getUrl() => __('admin.parking.permit.plural'), __('admin.parking.import.title')];
    }

    public function backUrl(): string
    {
        return PermitResource::getUrl();
    }
}
