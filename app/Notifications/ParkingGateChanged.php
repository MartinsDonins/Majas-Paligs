<?php

declare(strict_types=1);

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Parking gate level changed (GOV-001) — to building_admin(s), parking officers and platform admins. Carries the
 * levels, whether the resident module was switched off automatically and condition keys only.
 */
final class ParkingGateChanged extends Notification
{
    use Queueable;

    /**
     * @param  list<string>  $missing
     */
    public function __construct(
        public readonly string $buildingName,
        public readonly int $from,
        public readonly int $to,
        public readonly bool $moduleSwitchedOff,
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
            ->subject(__('gate.parking.mail.subject', ['building' => $this->buildingName, 'level' => $this->to]))
            ->line(__('gate.parking.mail.body', ['building' => $this->buildingName, 'from' => $this->from, 'to' => $this->to]));

        if ($this->moduleSwitchedOff) {
            $mail->line(__('gate.parking.mail.module_off'));
        }

        foreach ($this->missing as $key) {
            $mail->line('• '.__('gate.parking.missing.'.$key));
        }

        return $mail->salutation(__('mail.salutation'));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'building' => $this->buildingName, 'from' => $this->from, 'to' => $this->to,
            'module_switched_off' => $this->moduleSwitchedOff, 'missing' => $this->missing,
        ];
    }
}
