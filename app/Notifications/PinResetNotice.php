<?php

declare(strict_types=1);

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * "PIN šim dzīvoklim tika atjaunots ar vecākā palīdzību" — to the user (if e-mail) and the apartment's other
 * approved members (MVP-SPEC A.6a Elder PIN reset).
 */
final class PinResetNotice extends Notification
{
    use Queueable;

    public function __construct(public readonly int $apartmentNumber) {}

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
            ->subject(__('mail.pin_reset.subject'))
            ->line(__('mail.pin_reset.body', ['apartment' => $this->apartmentNumber]))
            ->line(__('mail.pin_reset.not_you'))
            ->salutation(__('mail.salutation'));
    }

    /**
     * @return array<string, int>
     */
    public function toArray(object $notifiable): array
    {
        return ['apartment' => $this->apartmentNumber];
    }
}
