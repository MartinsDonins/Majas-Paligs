<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Support\Copy;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * "1 kontakts dzēsts pēc iedzīvotāja pieprasījuma" (ROUND2-SPEC §5.5) — to the building's admins after an account
 * erasure removed roster rows. A count only: never who, which apartment or what.
 */
final class RosterContactsErased extends Notification
{
    use Queueable;

    public function __construct(
        public readonly string $buildingName,
        public readonly int $count,
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
        return (new MailMessage)
            ->subject(__('roster.erased.subject', ['building' => $this->buildingName]))
            ->line(Copy::count('roster.erased.body', $this->count, ['building' => $this->buildingName]))
            ->salutation(__('mail.salutation'));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return ['building' => $this->buildingName, 'count' => $this->count];
    }
}
