<?php

declare(strict_types=1);

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * "CoreDigify ierakstīja līgumu — nākamais solis: paziņojums" — to the building's admins (ROUND2-SPEC §3.6).
 */
final class DocumentRecorded extends Notification
{
    use Queueable;

    public function __construct(public readonly string $buildingName) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(__('mail.document_recorded.subject', ['building' => $this->buildingName]))
            ->line(__('mail.document_recorded.body', ['building' => $this->buildingName]))
            ->line(__('mail.document_recorded.next'))
            ->salutation(__('mail.salutation'));
    }

    /**
     * @return array<string, string>
     */
    public function toArray(object $notifiable): array
    {
        return ['building' => $this->buildingName];
    }
}
