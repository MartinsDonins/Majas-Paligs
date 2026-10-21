<?php

declare(strict_types=1);

namespace App\Filament\Manage\Resources\ResidentContactResource\Pages;

use App\Enums\ContactSource;
use App\Filament\Manage\Resources\ResidentContactResource;
use App\Gdpr\RosterGate;
use App\Models\Apartment;
use App\Services\Roster\ContactData;
use App\Services\Roster\RosterActionFailed;
use App\Services\Roster\RosterService;
use App\Tenancy\CurrentBuilding;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Filament\Support\Exceptions\Halt;
use Illuminate\Database\Eloquent\Model;

/**
 * Add a roster contact (admin: any apartment; elder: own stairwells — the apartment select only offers those and the
 * policy re-checks). Written through RosterService (sealed, audited `roster.create`).
 */
final class CreateResidentContact extends CreateRecord
{
    protected static string $resource = ResidentContactResource::class;

    protected function authorizeAccess(): void
    {
        abort_unless(RosterGate::isOpen() && ResidentContactResource::canCreate(), 403);
    }

    protected function fillForm(): void
    {
        $apartment = (int) request()->query('apartment', '0');
        $allowed = $apartment > 0 && ResidentContactResource::apartmentQuery()->whereKey($apartment)->exists();

        $this->form->fill(['apartment_id' => $allowed ? $apartment : null, 'relation' => 'contact', 'bis_registered' => '', 'whatsapp_member' => '']);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordCreation(array $data): Model
    {
        $apartment = ResidentContactResource::apartmentQuery()->whereKey((int) ($data['apartment_id'] ?? 0))->first();
        abort_if(! $apartment instanceof Apartment, 404);

        $user = ResidentContactResource::actor();
        $source = $user->isBuildingAdmin(CurrentBuilding::get()) ? ContactSource::Admin : ContactSource::Elder;

        try {
            return app(RosterService::class)->create($user, $apartment, ContactData::fromForm($data), $source);
        } catch (RosterActionFailed $e) {
            Notification::make()->danger()->title($e->label())->send();

            throw new Halt;
        }
    }

    protected function getRedirectUrl(): string
    {
        return ResidentContactResource::getUrl('index');
    }
}
