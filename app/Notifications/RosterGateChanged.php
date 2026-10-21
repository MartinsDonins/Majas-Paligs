<?php

declare(strict_types=1);

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Gate L-R (resident roster) closed or opened — to building_admin(s) and platform admins (ROUND2-SPEC §3.1).
 * Condition keys only, never a count or a name.
 */
final class RosterGateChanged extends Notification
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
            ->subject(__($this->open ? 'gate.roster.mail.opened_subject' : 'gate.roster.mail.closed_subject', ['building' => $this->buildingName]))
            ->line(__($this->open ? 'gate.roster.mail.opened' : 'gate.roster.mail.closed', ['building' => $this->buildingName]));

        foreach ($this->missing as $key) {
            $mail->line('• '.__('gate.roster.missing.'.$key));
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
