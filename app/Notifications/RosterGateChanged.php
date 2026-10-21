<?php

declare(strict_types=1);

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Roster gate L-R closed again (GOV-012) — to building_admin(s) and platform admins. Condition keys only, never a
 * count of contacts or any contact data.
 */
final class RosterGateChanged extends Notification
{
    use Queueable;

    /**
     * @param  list<string>  $missing
     */
    public function __construct(
        public readonly string $buildingName,
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
            ->subject(__('roster.gate.mail.subject', ['building' => $this->buildingName]))
            ->line(__('roster.gate.mail.body', ['building' => $this->buildingName]));

        foreach ($this->missing as $key) {
            $mail->line('• '.__('roster.gate.missing.'.$key));
        }

        return $mail->salutation(__('mail.salutation'));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return ['building' => $this->buildingName, 'missing' => $this->missing];
    }
}
