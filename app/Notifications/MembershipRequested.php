<?php

declare(strict_types=1);

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * "A new member asked to join apartment Dz. N" — to the stairwell elder(s) and the apartment's existing
 * approved members (PLANNING §4.5 item 1). No name, e-mail or handle of the requester.
 */
final class MembershipRequested extends Notification
{
    use Queueable;

    public function __construct(
        public readonly string $buildingName,
        public readonly int $apartmentNumber,
        public readonly string $role,
        public readonly bool $forElder,
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
        $key = $this->forElder ? 'mail.membership_requested.elder' : 'mail.membership_requested.member';

        return (new MailMessage)
            ->subject(__('mail.membership_requested.subject', ['apartment' => $this->apartmentNumber]))
            ->line(__($key, [
                'building' => $this->buildingName,
                'apartment' => $this->apartmentNumber,
                'role' => __('enum.membership_role.'.$this->role),
            ]))
            ->line(__('mail.membership_requested.not_you'))
            ->salutation(__('mail.salutation'));
    }

    /**
     * @return array<string, int|string|bool>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'building' => $this->buildingName,
            'apartment' => $this->apartmentNumber,
            'role' => $this->role,
            'for_elder' => $this->forElder,
        ];
    }
}
