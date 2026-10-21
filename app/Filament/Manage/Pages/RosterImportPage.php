<?php

declare(strict_types=1);

namespace App\Filament\Manage\Pages;

use App\Filament\Manage\Pages\Concerns\InteractsWithImportPipeline;
use App\Filament\Manage\Resources\ResidentContactResource;
use Filament\Pages\Page;

/**
 * "Iedzīvotāju saraksta imports" (ADMIN-007 on DB-003, ROUND2-SPEC §4) — slug `roster/import`, building_admin only,
 * gate L-R for every stage including the upload (D6). Commit needs the mfa step-up and the Art. 14 confirmation
 * ("Saprotu, ka kaimiņi jāinformē").
 */
final class RosterImportPage extends Page
{
    use InteractsWithImportPipeline;

    protected static ?string $slug = 'roster/import';

    protected string $view = 'filament.manage.pages.import-pipeline';

    protected static function importKind(): string
    {
        return 'roster';
    }

    public static function getNavigationGroup(): string
    {
        return __('admin.nav.people');
    }

    public function getTitle(): string
    {
        return __('roster.import.title');
    }

    /**
     * @return array<string, string>
     */
    public function getBreadcrumbs(): array
    {
        return [ResidentContactResource::getUrl() => __('roster.title'), __('roster.import.title')];
    }

    public function backUrl(): string
    {
        return ResidentContactResource::getUrl();
    }
}
