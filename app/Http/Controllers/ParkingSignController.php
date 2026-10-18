<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Building;
use App\Models\ParkingLot;
use App\Models\User;
use BaconQrCode\Renderer\GDLibRenderer;
use BaconQrCode\Writer;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

/**
 * GET /b/{building}/files/parking-sign — Art. 14 parking-lot sign (PARK-007): A4 LV/RU with the controller, purpose,
 * retention and a QR code to the building privacy notice. No personal data, so it is available from gate L0
 * (sign drafts are prepared before L1) to parking_officer / building_admin.
 */
final class ParkingSignController
{
    public function __invoke(Request $request, Building $building): Response
    {
        /** @var User $user */
        $user = $request->user();
        abort_unless(Gate::forUser($user)->allows('create', ParkingLot::class), 403);

        $url = route('building.privacy', $building);
        $qr = base64_encode((new Writer(new GDLibRenderer(360, 1)))->writeString($url));

        return Pdf::loadView('pdf.parking-sign', [
            'building' => $building,
            'controller' => $building->settings->controller['name'],
            'url' => $url,
            'qr' => $qr,
            'lot' => $request->string('lot')->limit(80, '')->toString(),
        ])->setPaper('a4', 'portrait')
            ->download('stavvietas-zime-'.$building->slug.'.pdf')
            ->withHeaders(['Cache-Control' => 'no-store, private']);
    }
}
