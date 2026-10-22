<?php

declare(strict_types=1);

namespace App\Filament\Manage\Resources\Concerns;

use App\Filament\Manage\Pages\ParkingStatusPage;
use App\Gdpr\ParkingGate;
use App\Tenancy\CurrentBuilding;
use Filament\Notifications\Notification;
use Illuminate\Http\Exceptions\HttpResponseException;
use Livewire\Livewire;

/**
 * FIRSTRUN-001 (P1): the parking staff lists (Caurlaides, Auto, Ziņojumi, Uzdevumi, Eksports) answered a bare 403
 * below gate L1 when opened by URL. Parking staff (building_admin / parking_officer) are now sent to "Stāvvietas
 * statuss", which says exactly what is missing; everyone else keeps the 403. The resources' own canAccess() (policy
 * before()) is unchanged — this only replaces the error page with the explanation, on the page GET only.
 */
trait RedirectsBelowParkingL1
{
    public static function authorizeResourceAccess(): void
    {
        $building = CurrentBuilding::find();

        if ($building !== null && ! static::getResource()::canAccess() && ! Livewire::isLivewireRequest() && request()->isMethod('GET')
            && ParkingStatusPage::canAccess() && ! ParkingGate::status($building)->l1()) {
            Notification::make()->warning()->title(__('start.locked.parking_l1'))->send();

            throw new HttpResponseException(redirect(ParkingStatusPage::getUrl(tenant: $building)));
        }

        parent::authorizeResourceAccess();
    }
}
