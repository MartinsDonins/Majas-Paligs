<?php

declare(strict_types=1);

use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\PostLoginRedirect;
use App\Http\Controllers\BuildingExportDownloadController;
use App\Http\Controllers\DataExportDownloadController;
use App\Http\Controllers\HealthController;
use App\Http\Controllers\InviteLetterController;
use App\Http\Controllers\LocaleController;
use App\Http\Controllers\ManagerReportDownloadController;
use App\Http\Controllers\ParkingExportDownloadController;
use App\Http\Controllers\ParkingSignController;
use App\Http\Controllers\PublicPageController;
use App\Http\Controllers\PwaController;
use App\Http\Controllers\SightingPhotoController;
use App\Livewire\Auth\EmailLogin;
use App\Livewire\Auth\PinLogin;
use App\Livewire\Auth\SetNewPin;
use App\Livewire\Auth\StepUp;
use App\Livewire\Elder\Panel as ElderPanel;
use App\Livewire\Heating\Grid as HeatingGrid;
use App\Livewire\Home\Dashboard;
use App\Livewire\Me\Apartment as MeApartment;
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
use Illuminate\Support\Facades\Route;

/*
 * Mājas Palīgs routes (MVP-SPEC A.6). Bearer secrets never travel in a query string or path:
 * `/join#c=CODE` and `/login/confirm#t=TOKEN` (fragments). Legacy `?c=` / `?t=` / path tokens are answered with
 * an immediate 303 to the bare route and the value is discarded (never echoed).
 */

Route::get('/up', HealthController::class)->name('health');
Route::get('/up/ip', [HealthController::class, 'ip'])->middleware('throttle:locale')->name('health.ip');
Route::get('/manifest.webmanifest', [PwaController::class, 'manifest'])->name('pwa.manifest');
Route::get('/sw.js', [PwaController::class, 'serviceWorker'])->name('pwa.sw');

Route::get('/', LanguagePick::class)->name('landing');
Route::get('/privacy', [PublicPageController::class, 'privacy'])->name('privacy');
Route::get('/terms', [PublicPageController::class, 'terms'])->name('terms');
Route::get('/privacy/{building:slug}', [PublicPageController::class, 'buildingPrivacy'])->middleware('tenant')->name('building.privacy');
Route::post('/locale/{locale}', LocaleController::class)->whereIn('locale', ['lv', 'ru'])->middleware('throttle:locale')->name('locale.set');

// ---- Onboarding (/join) — guest or authenticated ----
Route::get('/join', RedeemInvite::class)->name('join')->middleware('discard.query');
Route::get('/join/role', ChooseRole::class)->name('join.role')->middleware('discard.query');
Route::get('/join/access', ChooseAccess::class)->name('join.access')->middleware('discard.query');

// ---- Login ----
Route::middleware('guest')->group(function (): void {
    Route::get('/login', EmailLogin::class)->name('login')->middleware('discard.query');
    Route::get('/login/pin', PinLogin::class)->name('login.pin')->middleware('discard.query');
});
Route::get('/login/confirm', [LoginController::class, 'confirmPage'])->name('login.confirm')->middleware('discard.query');
Route::get('/login/confirm/{token}', fn () => redirect('/login/confirm', 303))->where('token', '.*')->name('login.confirm.legacy');
Route::post('/login/confirm', [LoginController::class, 'confirm'])->middleware('throttle:login-confirm')->name('login.confirm.submit');
Route::post('/login/code', [LoginController::class, 'code'])->middleware('throttle:login-code')->name('login.code');

Route::middleware('auth')->group(function (): void {
    Route::post('/logout', [LoginController::class, 'logout'])->name('logout');
    Route::get('/login/pin/new', SetNewPin::class)->name('login.pin.new');
    Route::get('/home', PostLoginRedirect::class)->name('home.redirect');
    Route::get('/step-up', StepUp::class)->name('step-up');
    Route::get('/me/privacy', MePrivacy::class)->name('me.privacy');
    Route::get('/me/security', MeSecurity::class)->name('me.security');
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
