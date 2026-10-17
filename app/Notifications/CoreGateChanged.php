<?php

declare(strict_types=1);

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Gate L-core closed (regression) or opened — to building_admin(s) and platform admins (PLANNING §5).
 * Carries condition keys only.
 */
final class CoreGateChanged extends Notification
{
    use Queueable;

    /**
     * @param  list<string>  $missing
     */
    public function __construct(
        public readonly string $buildingName,
        public readonly bool $open,
        public readonly array $missing,
    ) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return method_exists($notifiable, 'getKey') ? ['mail', 'database'] : ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->subject(__($this->open ? 'gate.core.mail.opened_subject' : 'gate.core.mail.closed_subject', ['building' => $this->buildingName]))
            ->line(__($this->open ? 'gate.core.mail.opened' : 'gate.core.mail.closed', ['building' => $this->buildingName]));

        foreach ($this->missing as $key) {
            $mail->line('• '.__('gate.core.missing.'.$key));
        }

        return $mail->salutation(__('mail.salutation'));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return ['building' => $this->buildingName, 'open' => $this->open, 'missing' => $this->missing];
    }
}
