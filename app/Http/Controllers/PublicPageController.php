<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\PrivacyNoticeKind;
use App\Models\Building;
use App\Models\BuildingPrivacyNotice;
use App\Models\PrivacyNoticeVersion;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Public Art. 13 notices (PLANNING §5): /privacy (CoreDigify platform account notice) and
 * /privacy/{slug} (the building's current notice, house = controller, plus the platform notice).
 * The building notice is public by design: printed invite letters point to it.
 */
final class PublicPageController
{
    public function privacy(Request $request): View|RedirectResponse
    {
        $slug = $request->query('b');

        if (is_string($slug) && preg_match('/^[a-z0-9]+(-[a-z0-9]+)*$/', $slug) === 1 && strlen($slug) <= 64) {
            return redirect()->route('building.privacy', ['building' => $slug]);
        }

        return view('pages.privacy', $this->platform() + ['building' => null, 'buildingText' => null]);
    }

    /**
     * Runs with the `tenant` middleware only (context from the bound slug) — no authentication.
     */
    public function buildingPrivacy(Building $building): View
    {
        $notice = BuildingPrivacyNotice::current();

        return view('pages.privacy', $this->platform() + [
            'building' => $building,
            'buildingText' => $notice?->render(app()->getLocale()),
            'buildingHtml' => $notice?->renderHtml(app()->getLocale()),
        ]);
    }

    public function terms(): View
    {
        return view('pages.terms');
    }

    /**
     * @return array{platformText: ?string, platformVersion: ?string}
     */
    private function platform(): array
    {
        $platform = PrivacyNoticeVersion::current(PrivacyNoticeKind::PlatformAccount);

        return [
            'platformText' => $platform?->render(app()->getLocale()),
            'platformVersion' => $platform?->version,
        ];
    }
}
