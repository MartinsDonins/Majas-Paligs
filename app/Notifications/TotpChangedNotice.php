<?php

declare(strict_types=1);

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * "Autentifikators ieslēgts / izslēgts" — sent to the account's e-mail on every enable or disable, so a takeover of
 * the session or mailbox that touches the second factor is noticed (review fix, drošība).
 */
final class TotpChangedNotice extends Notification
{
    use Queueable;

    public function __construct(public readonly bool $enabled) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return isset($notifiable->email) ? ['mail', 'database'] : ['database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $state = $this->enabled ? 'enabled' : 'disabled';

        return (new MailMessage)
            ->subject(__('mail.totp.'.$state.'.subject'))
            ->line(__('mail.totp.'.$state.'.body'))
            ->line(__('mail.totp.not_you'))
            ->salutation(__('mail.salutation'));
    }

    /**
     * @return array<string, bool>
     */
    public function toArray(object $notifiable): array
    {
        return ['enabled' => $this->enabled];
    }
}
