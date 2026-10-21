<?php

declare(strict_types=1);

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * "Māja nosūtīja līgumu ierakstīšanai" — to platform admins when a house sends its DPA / annex to CoreDigify
 * (ROUND2-SPEC §3.2 `dpa`, §3.6). Building name and document kind only.
 */
final class OpeningRequestSent extends Notification
{
    use Queueable;

    public function __construct(
        public readonly string $buildingName,
        public readonly string $kind,
    ) {}

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
            ->subject(__('mail.opening_request.subject', ['building' => $this->buildingName]))
            ->line(__('mail.opening_request.body', ['building' => $this->buildingName, 'kind' => __('enum.document_kind.'.$this->kind)]))
            ->line(__('mail.opening_request.where'))
            ->salutation(__('mail.salutation'));
    }

    /**
     * @return array<string, string>
     */
    public function toArray(object $notifiable): array
    {
        return ['building' => $this->buildingName, 'kind' => $this->kind];
    }
}
