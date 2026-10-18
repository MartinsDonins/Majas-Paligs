<?php

declare(strict_types=1);

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * "Stāvvietas atbildīgais apskatīja Jūsu dzīvokļa auto datus" — to the approved members of the apartment after a
 * reveal (PARK-002.1). Building name + reason code only: never the plate, the actor or the apartment number.
 */
final class VehicleRevealedNotice extends Notification
{
    use Queueable;

    public function __construct(
        public readonly string $buildingName,
        public readonly string $reason,
    ) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        $channels = ['database'];

        if (isset($notifiable->email)) {
            $channels[] = 'mail';
        }

        return $channels;
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(__('mail.vehicle_reveal.subject'))
            ->line(__('mail.vehicle_reveal.body', ['building' => $this->buildingName, 'reason' => __('enum.reveal_reason.'.$this->reason)]))
            ->line(__('mail.vehicle_reveal.where'))
            ->salutation(__('mail.salutation'));
    }

    /**
     * @return array<string, string>
     */
    public function toArray(object $notifiable): array
    {
        return ['building' => $this->buildingName, 'reason' => $this->reason];
    }
}
