<?php

declare(strict_types=1);

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * The requester's membership was approved or rejected.
 */
final class MembershipDecided extends Notification
{
    use Queueable;

    public function __construct(
        public readonly string $buildingName,
        public readonly int $apartmentNumber,
        public readonly bool $approved,
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
            ->subject(__($this->approved ? 'mail.membership_decided.approved_subject' : 'mail.membership_decided.rejected_subject'))
            ->line(__($this->approved ? 'mail.membership_decided.approved' : 'mail.membership_decided.rejected', [
                'building' => $this->buildingName,
                'apartment' => $this->apartmentNumber,
            ]))
            ->salutation(__('mail.salutation'));
    }

    /**
     * @return array<string, int|string|bool>
     */
    public function toArray(object $notifiable): array
    {
        return ['building' => $this->buildingName, 'apartment' => $this->apartmentNumber, 'approved' => $this->approved];
    }
}
