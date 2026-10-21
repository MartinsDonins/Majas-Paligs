<?php

declare(strict_types=1);

use App\Http\Controllers\Admin\AdminEntryController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\PostLoginRedirect;
use App\Http\Controllers\BuildingExportDownloadController;
use App\Http\Controllers\DataExportDownloadController;
use App\Http\Controllers\HealthController;
use App\Http\Controllers\InviteLetterController;
use App\Http\Controllers\LegacyPanelRedirect;
use App\Http\Controllers\LocaleController;
use App\Http\Controllers\ManagerReportDownloadController;
use App\Http\Controllers\ParkingExportDownloadController;
use App\Http\Controllers\ParkingSignController;
use App\Http\Controllers\PublicPageController;
use App\Http\Controllers\PwaController;
use App\Http\Controllers\SightingPhotoController;
use App\Http\Middleware\EnsurePinChanged;
use App\Http\Middleware\EnsureSessionEpoch;
use App\Http\Middleware\PreventRequestForgery;
use App\Livewire\Auth\EmailLogin;
use App\Livewire\Auth\PinLogin;
use App\Livewire\Auth\SetNewPin;
use App\Livewire\Auth\StepUp;
use App\Livewire\Elder\Panel as ElderPanel;
use App\Livewire\Heating\Grid as HeatingGrid;
use App\Livewire\Home\Dashboard;
use App\Livewire\Me\Apartment as MeApartment;
use App\Livewire\Me\PasskeyOffer;
use App\Livewire\Me\Privacy as MePrivacy;
use App\Livewire\Me\Security as MeSecurity;
use App\Livewire\Onboarding\ChooseAccess;
use App\Livewire\Onboarding\ChooseRole;
use App\Livewire\Onboarding\LanguagePick;
use App\Livewire\Onboarding\RedeemInvite;
use App\Livewire\Outage\Detail as OutageDetail;
use App\Livewire\Parking\CampaignProgress;
use App\Livewire\Parking\DeclarationWizard;
use App\Livewire\Parking\Lookup as ParkingLookup;
use App\Livewire\Parking\MyPermits;
use App\Livewire\Parking\SightingSheet;
use App\Livewire\Team\AcceptInvite;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Route;
use Illuminate\View\Middleware\ShareErrorsFromSession;

/*
 * Mājas Palīgs routes (MVP-SPEC A.6). Bearer secrets never travel in a query string or path:
 * `/join#c=CODE` and `/login/confirm#t=TOKEN` (fragments). Legacy `?c=` / `?t=` / path tokens are answered with
 * an immediate 303 to the bare route and the value is discarded (never echoed).
 */

/*
 * Session-less endpoints: fetched by the browser itself (service-worker update checks, the manifest, health probes,
 * a 404 favicon) with the session cookie. With a session they overwrote `_previous.url` — the LV/RU toggle then sent
 * people to the raw /sw.js — and, finishing after a step-up request, could write back a stale session payload and
 * drop the fresh step-up (QA 2026-10-20). Cookies stay readable (EncryptCookies) for the manifest language.
 */
$sessionless = [StartSession::class, ShareErrorsFromSession::class, PreventRequestForgery::class, EnsureSessionEpoch::class, EnsurePinChanged::class];

Route::get('/up', HealthController::class)->withoutMiddleware($sessionless)->name('health');
Route::get('/up/ip', [HealthController::class, 'ip'])->middleware('throttle:locale')->withoutMiddleware($sessionless)->name('health.ip');
Route::get('/manifest.webmanifest', [PwaController::class, 'manifest'])->withoutMiddleware($sessionless)->name('pwa.manifest');
Route::get('/sw.js', [PwaController::class, 'serviceWorker'])->withoutMiddleware($sessionless)->name('pwa.sw');

Route::get('/', LanguagePick::class)->name('landing');
Route::get('/privacy', [PublicPageController::class, 'privacy'])->name('privacy');
Route::get('/terms', [PublicPageController::class, 'terms'])->name('terms');
Route::get('/privacy/{building:slug}', [PublicPageController::class, 'buildingPrivacy'])->middleware('tenant')->name('building.privacy');
Route::post('/locale/{locale}', LocaleController::class)->whereIn('locale', ['lv', 'ru'])->middleware('throttle:locale')->name('locale.set');

// ---- Onboarding (/join) — guest or authenticated ----
Route::get('/join', RedeemInvite::class)->name('join')->middleware('discard.query');
Route::get('/join/role', ChooseRole::class)->name('join.role')->middleware('discard.query');
Route::get('/join/access', ChooseAccess::class)->name('join.access')->middleware('discard.query');

// ---- Team invitation (ROUND2-SPEC §2.2): guest or signed in; context from the bound slug like /privacy/{slug}. The
// token is in the fragment (#t=…), never in the URL the server sees. ----
Route::get('/team-invite/{building:slug}', AcceptInvite::class)->middleware(['tenant', 'discard.query'])->name('team.accept');

// ---- Login ----
Route::middleware('guest')->group(function (): void {
    Route::get('/login', EmailLogin::class)->name('login')->middleware('discard.query');
    Route::get('/login/pin', PinLogin::class)->name('login.pin')->middleware('discard.query');
});
Route::get('/login/confirm', [LoginController::class, 'confirmPage'])->name('login.confirm')->middleware('discard.query');
Route::get('/login/confirm/{token}', fn () => redirect('/login/confirm', 303))->where('token', '.*')->name('login.confirm.legacy');
Route::post('/login/confirm', [LoginController::class, 'confirm'])->middleware('throttle:login-confirm')->name('login.confirm.submit');
Route::post('/login/code', [LoginController::class, 'code'])->middleware('throttle:login-code')->name('login.code');

// ---- Administration (ROUND2-SPEC §1.1, ADMIN-005): /admin entry; panels at /admin/b/{slug} and /admin/platform ----
// Old panel URLs answer 301 (GET/HEAD, query kept) or 308 to the same page under /admin. Session-less: nothing to keep.
Route::any('/manage/{rest?}', LegacyPanelRedirect::class)->where('rest', '.*')->defaults('panel', 'manage')
    ->withoutMiddleware($sessionless)->name('legacy.manage');
Route::any('/platform/{rest?}', LegacyPanelRedirect::class)->where('rest', '.*')->defaults('panel', 'platform')
    ->withoutMiddleware($sessionless)->name('legacy.platform');

Route::middleware('auth')->group(function (): void {
    Route::get('/admin', AdminEntryController::class)->name('admin.entry');
    Route::post('/logout', [LoginController::class, 'logout'])->name('logout');
    Route::get('/login/pin/new', SetNewPin::class)->name('login.pin.new');
    Route::get('/home', PostLoginRedirect::class)->name('home.redirect');
    Route::get('/step-up', StepUp::class)->name('step-up');
    Route::get('/me/privacy', MePrivacy::class)->name('me.privacy');
    Route::get('/me/security', MeSecurity::class)->name('me.security');
    Route::get('/me/passkey', PasskeyOffer::class)->name('me.passkey');
    Route::get('/me/exports/{export}', DataExportDownloadController::class)->middleware('signed')->name('me.exports.download');
});

// ---- Resident app: auth → SetCurrentBuilding → member (404 for non-members) → role / step-up ----
Route::prefix('/b/{building:slug}')
    ->middleware(['auth', 'tenant', 'member'])
    ->group(function (): void {
        Route::get('/', Dashboard::class)->name('building.home');
        Route::get('/me', MeApartment::class)->name('building.me');
        Route::get('/help', ElderPanel::class)->middleware(['role:section_elder,building_admin', 'step-up'])->name('building.elder');
        Route::get('/files/invite-letters/{batch}', InviteLetterController::class)
            ->middleware(['role:building_admin', 'step-up', 'signed'])
            ->where('batch', '[0-9A-Za-z]{26}')
            ->name('building.invite-letters');
        Route::get('/files/exports/{export}', BuildingExportDownloadController::class)
            ->middleware(['role:building_admin', 'step-up', 'signed'])
            ->name('building.exports.download');

        // ---- Package B: heating & outages (MVP-SPEC B.3) ----
        Route::get('/heating', HeatingGrid::class)->middleware('module:heating')->defaults('mode', 'heating')->name('building.heating');
        Route::get('/water', HeatingGrid::class)->middleware('module:outages')->defaults('mode', 'outage')->name('building.water');
        Route::get('/incidents/{incident}', OutageDetail::class)->middleware('module:outages')
            ->where('incident', '[0-9a-z]{26}')
            ->name('building.incident');
        // Signed 10-min download; ManagerReportPolicy::download (building_admin, or elder when section_ids ⊆ own).
        Route::get('/reports/{report}.{format}', ManagerReportDownloadController::class)
            ->middleware(['module:heating,outages', 'step-up', 'signed'])
            ->where(['report' => '[0-9a-z]{26}', 'format' => 'pdf|csv'])
            ->name('building.manager-report');

        // ---- Package C: parking (MVP-SPEC C.2). `module:parking` = modules.parking ON **and** parking gate L2. ----
        Route::middleware('module:parking')->group(function (): void {
            Route::get('/parking', ParkingLookup::class)->name('building.parking');
            Route::get('/parking/mine', MyPermits::class)->name('building.parking.mine');
            Route::get('/parking/declare', DeclarationWizard::class)->name('building.parking.declare');
            Route::get('/parking/sighting', SightingSheet::class)->name('building.parking.sighting');
            Route::get('/parking/progress', CampaignProgress::class)->name('building.parking.progress');
        });
        // Staff files (gate L1 is checked by the policies inside): photo 5-min signed, export 10-min signed + step-up.
        Route::get('/files/sightings/{sighting}/photo', SightingPhotoController::class)
            ->middleware(['role:parking_officer,building_admin', 'step-up', 'signed'])
            ->where('sighting', '[0-9a-z]{26}')
            ->name('building.sighting-photo');
        Route::get('/files/parking-exports/{export}', ParkingExportDownloadController::class)
            ->middleware(['role:parking_officer,building_admin', 'step-up', 'signed'])
            ->where('export', '[0-9a-z]{26}')
            ->name('building.parking-export');
        Route::get('/files/parking-sign', ParkingSignController::class)
            ->middleware(['role:parking_officer,building_admin'])
            ->name('building.parking-sign');
    });

// Unknown URL: our 404 page in the visitor's language (SetLocale reads the mp_locale cookie). Session-less, so a
// browser-initiated miss (apple-touch-icon, favicon) never becomes the "previous URL".
Route::fallback(static fn () => response()->view('errors.404', [], 404))->withoutMiddleware($sessionless);
