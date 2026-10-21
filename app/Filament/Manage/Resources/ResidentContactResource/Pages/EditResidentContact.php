<?php

declare(strict_types=1);

namespace App\Filament\Manage\Resources\ResidentContactResource\Pages;

use App\Auth\StepUpService;
use App\Filament\Manage\Resources\ResidentContactResource;
use App\Models\ResidentContact;
use App\Services\Roster\ContactData;
use App\Services\Roster\RosterActionFailed;
use App\Services\Roster\RosterService;
use App\Services\Roster\RosterViewer;
use App\Tenancy\CurrentBuilding;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Exceptions\Halt;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\URL;

/**
 * "Labot" one roster contact. Opening the form shows the plaintext, so it needs a fresh SECOND factor and counts as a
 * reveal (audited `roster.reveal`, within the 100-per-day cap). Saving re-seals every field. Header actions:
 * Pārbaudīts, Izvācies / Atpakaļ mājā, Datu izraksts (Art. 15, signed 10-min ZIP), Dzēst.
 *
 * @property ResidentContact $record
 */
final class EditResidentContact extends EditRecord
{
    protected static string $resource = ResidentContactResource::class;

    public function mount(int|string $record): void
    {
        $user = ResidentContactResource::actor();

        if (! app(StepUpService::class)->hasFreshMfa($user)) {
            throw new HttpResponseException(redirect(app(StepUpService::class)->ask('mfa', request()->getRequestUri())));
        }

        parent::mount($record);
    }

    protected function fillForm(): void
    {
        try {
            $this->form->fill(app(RosterViewer::class)->forEdit(ResidentContactResource::actor(), $this->record));
        } catch (RosterActionFailed $e) {
            Notification::make()->danger()->title($e->label())->persistent()->send();

            throw new HttpResponseException(redirect(ResidentContactResource::getUrl('index')));
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        /** @var ResidentContact $record */
        try {
            return app(RosterService::class)->update(ResidentContactResource::actor(), $record, ContactData::fromForm($data));
        } catch (RosterActionFailed $e) {
            Notification::make()->danger()->title($e->label())->send();

            throw new Halt;
        }
    }

    protected function getRedirectUrl(): string
    {
        return ResidentContactResource::getUrl('index');
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('reviewed')
                ->label(__('roster.contact.mark_reviewed'))
                ->icon(Heroicon::OutlinedCheckBadge)
                ->color('success')
                ->visible(fn (): bool => Gate::allows('update', $this->record))
                ->action(function (): void {
                    app(RosterService::class)->markReviewed(ResidentContactResource::actor(), $this->record);
                    Notification::make()->success()->title(__('roster.contact.reviewed_done'))->send();
                }),
            Action::make('movedOut')
                ->label(fn (): string => $this->record->moved_out_at === null ? __('roster.contact.mark_moved_out') : __('roster.contact.mark_back'))
                ->icon(Heroicon::OutlinedArrowRightStartOnRectangle)
                ->color('gray')
                ->requiresConfirmation()
                ->modalDescription(fn (): ?string => $this->record->moved_out_at === null ? __('roster.contact.moved_out_help') : null)
                ->visible(fn (): bool => Gate::allows('update', $this->record))
                ->action(function (): void {
                    app(RosterService::class)->markMovedOut(ResidentContactResource::actor(), $this->record, $this->record->moved_out_at === null);
                    Notification::make()->success()->title(__('roster.saved'))->send();
                    $this->redirect(ResidentContactResource::getUrl('index'));
                }),
            Action::make('extract')
                ->label(__('roster.extract.action'))
                ->icon(Heroicon::OutlinedDocumentArrowDown)
                ->color('gray')
                ->visible(fn (): bool => Gate::allows('update', $this->record))
                ->url(fn (): string => URL::temporarySignedRoute('admin.roster.extract', now()->addMinutes((int) config('majas.exports.signed_minutes', 10)), [
                    'building' => CurrentBuilding::get()->slug,
                    'contact' => $this->record->id,
                ])),
            Action::make('delete')
                ->label(__('roster.contact.delete'))
                ->icon(Heroicon::OutlinedTrash)
                ->color('danger')
                ->requiresConfirmation()
                ->modalDescription(__('roster.contact.delete_help'))
                ->visible(fn (): bool => Gate::allows('delete', $this->record))
                ->action(function (): void {
                    app(RosterService::class)->delete(ResidentContactResource::actor(), $this->record);
                    Notification::make()->success()->title(__('roster.contact.deleted'))->send();
                    $this->redirect(ResidentContactResource::getUrl('index'));
                }),
        ];
    }
}
