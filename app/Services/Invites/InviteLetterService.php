<?php

declare(strict_types=1);

namespace App\Services\Invites;

use App\Audit\AuditLogger;
use App\Gdpr\CoreGate;
use App\Gdpr\CoreGateClosed;
use App\Models\Apartment;
use App\Models\ApartmentInvite;
use App\Models\BuildingPrivacyNotice;
use App\Models\User;
use App\Tenancy\CurrentBuilding;
use BaconQrCode\Renderer\GDLibRenderer;
use BaconQrCode\Writer;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Invite letters (AUTH-002.1, DESIGN-SYSTEM §8): A4 (folds to DL) LV + RU per apartment, one page per language, QR to `/join#c=…` (fragment), code
 * XXXXX-XXXXX, controller + notice line, "NAV BIS / NAV RNP", detachable "Mans PIN" card.
 *
 * Plaintext codes exist ONLY in memory while the PDF renders: printing a batch creates new invites (HMAC only)
 * and revokes the apartments' previous active invites. Only with gate L-core open. Audit-logged.
 */
final class InviteLetterService
{
    public const CACHE_PREFIX = 'invite-batch:';

    public function __construct(private readonly InviteCodeService $codes) {}

    /**
     * Prepare a single-use print batch (10 min) and return its id for the signed URL.
     *
     * @param  list<int>  $apartmentIds
     */
    public function prepare(User $admin, array $apartmentIds, ?string $elderContact, string $order = 'lv'): string
    {
        $building = CurrentBuilding::get();
        $status = CoreGate::status($building);
        if (! $status->open) {
            throw new CoreGateClosed($status->missing);
        }

        $batch = (string) Str::ulid();
        Cache::put(self::CACHE_PREFIX.$batch, [
            'building_id' => (int) $building->id,
            'user_id' => (int) $admin->id,
            'apartment_ids' => array_values(array_unique(array_map('intval', $apartmentIds))),
            'elder_contact' => $elderContact !== null ? mb_substr(trim($elderContact), 0, 120) : null,
            'order' => $order === 'ru' ? 'ru' : 'lv',
        ], now()->addMinutes((int) config('majas.exports.signed_minutes', 10)));

        return $batch;
    }

    /**
     * Consume the batch and stream the PDF (codes generated now, kept in memory only).
     */
    public function render(User $admin, string $batch): ?Response
    {
        $spec = Cache::pull(self::CACHE_PREFIX.$batch);
        $building = CurrentBuilding::get();

        if (! is_array($spec) || $spec['building_id'] !== (int) $building->id || $spec['user_id'] !== (int) $admin->id) {
            return null;
        }

        $status = CoreGate::status($building);
        if (! $status->open) {
            throw new CoreGateClosed($status->missing);
        }

        /** @var Collection<int, Apartment> $apartments */
        $apartments = Apartment::query()->active()->whereIn('id', $spec['apartment_ids'])->with('section')->orderBy('number')->get();
        $notice = BuildingPrivacyNotice::query()->whereKey($status->noticeId)->first();

        $letters = DB::transaction(function () use ($apartments, $admin, $batch): array {
            $letters = [];

            foreach ($apartments as $apartment) {
                ApartmentInvite::query()->where('apartment_id', $apartment->id)->whereNull('revoked_at')->update(['revoked_at' => now()]);

                $code = $this->codes->generate();
                ApartmentInvite::query()->create([
                    'apartment_id' => $apartment->id,
                    'code_hmac' => $this->codes->hmac($code),
                    'max_uses' => (int) config('majas.invites.max_uses', 3),
                    'expires_at' => now()->addDays((int) config('majas.invites.valid_days', 60)),
                    'created_by' => $admin->id,
                    'batch_ref' => $batch,
                ]);

                $letters[] = [
                    'number' => $apartment->number,
                    'section' => $apartment->section->number,
                    'floor' => $apartment->floor,
                    'code' => $this->codes->format($code),
                    'qr' => $this->qr($code),
                ];
            }

            AuditLogger::record('invite.print', ['invite_batch', $batch], data: [
                'apartments' => count($letters),
                'sections' => $apartments->pluck('section.number')->unique()->values()->all(),
            ]);

            return $letters;
        });

        $host = (string) config('majas.public_host');

        $pdf = Pdf::loadView('pdf.invite-letters', [
            'building' => $building,
            'letters' => $letters,
            'order' => $spec['order'] === 'ru' ? ['ru', 'lv'] : ['lv', 'ru'],
            'elderContact' => $spec['elder_contact'],
            'controller' => $notice?->controller_snapshot['name'] ?? $building->settings->controller['name'],
            'joinUrl' => $host.'/join',
            'privacyUrl' => $host.'/privacy/'.$building->slug,
            'validUntil' => now('Europe/Riga')->addDays((int) config('majas.invites.valid_days', 60))->format('d.m.Y'),
        ])->setPaper('a4', 'portrait');

        $filename = 'uzaicinajumi-'.$building->slug.'-'.now('Europe/Riga')->format('Ymd-Hi').'.pdf';

        // Wipe plaintext codes from memory as far as PHP allows.
        foreach ($letters as $i => $letter) {
            $letters[$i]['code'] = str_repeat('X', 11);
        }

        return $pdf->download($filename)->withHeaders(['Cache-Control' => 'no-store, private']);
    }

    private function qr(#[\SensitiveParameter] string $code): string
    {
        $url = rtrim((string) config('app.url'), '/').'/join#c='.$this->codes->format($code);
        $writer = new Writer(new GDLibRenderer(400, 1));

        return 'data:image/png;base64,'.base64_encode($writer->writeString($url));
    }
}
