<?php

declare(strict_types=1);

namespace App\Filament\Platform\Widgets;

use App\Enums\DocumentKind;
use App\Enums\PrivacyNoticeKind;
use App\Filament\Platform\Pages\OpeningRequestsPage;
use App\Filament\Platform\Resources\DocumentTemplateResource;
use App\Filament\Platform\Resources\PrivacyNoticeResource;
use App\Models\DocumentTemplate;
use App\Models\PrivacyNoticeVersion;
use App\Models\User;
use App\Platform\PlatformHouses;
use Filament\Widgets\Widget;

/**
 * "Sākt šeit" on the platform dashboard (FIRSTRUN-001) — it used to be just the heading "Panelis". Two parts:
 * - the platform checklist: CoreDigify account notice published (without it /join shows "Paziņojums tiek gatavots"),
 *   the house privacy-notice MASTER published in LV and RU (DocumentTemplate privacy_notice — what the opening wizard
 *   prepares a house's notice from, DocumentService::prepare; the legacy PrivacyNoticeKind::BuildingTemplate is read by
 *   nothing), DPA requests waiting for CoreDigify;
 * - one row per house (PlatformHouses::overview, no resident data): opening status (L-core x of 4, roster, parking),
 *   "Jūsu loma", "Jūsu dzīvoklis" and the next action — "Atvērt mājas paneli" or "Kļūt par administratoru" (on the
 *   platform house page, behind a strong step-up).
 */
final class PlatformStartWidget extends Widget
{
    protected static ?int $sort = -20;

    /** Rendered with the page: it is the first thing a newcomer must see (no lazy placeholder). */
    protected static bool $isLazy = false;

    protected int|string|array $columnSpan = 'full';

    protected string $view = 'filament.platform.widgets.platform-start';

    public static function canView(): bool
    {
        $user = auth()->user();

        return $user instanceof User && $user->isPlatformAdmin();
    }

    /**
     * @return array{checklist: list<array{key: string, done: bool, url: string}>, houses: list<array<string, mixed>>, requests: int}
     */
    public function data(): array
    {
        /** @var User $user */
        $user = auth()->user();
        $houses = PlatformHouses::overview($user);
        $requests = (int) array_sum(array_column($houses, 'pending_requests'));
        $notices = PrivacyNoticeResource::getUrl('index');

        return [
            'checklist' => [
                ['key' => 'account_notice', 'done' => PrivacyNoticeVersion::current(PrivacyNoticeKind::PlatformAccount) !== null, 'url' => $notices],
                ['key' => 'house_notice_master', 'done' => DocumentTemplate::publishedPair(DocumentKind::PrivacyNotice) !== null, 'url' => DocumentTemplateResource::getUrl('index')],
                ['key' => 'requests', 'done' => $requests === 0, 'url' => OpeningRequestsPage::getUrl()],
            ],
            'houses' => $houses,
            'requests' => $requests,
        ];
    }
}
