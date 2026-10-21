<?php

declare(strict_types=1);

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * "Komandai pievienots jauns …" — to the inviter and the other building_admins after an invitation was accepted.
 * No name, no e-mail, no count (ROUND2-SPEC §2.2).
 */
final class TeamMemberJoined extends Notification
{
    use Queueable;

    public function __construct(
        public readonly string $buildingName,
        public readonly string $role,
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
            ->subject(__('mail.team_joined.subject', ['building' => $this->buildingName]))
            ->line(__('mail.team_joined.body', ['building' => $this->buildingName, 'role' => __('enum.building_role.'.$this->role)]))
            ->line(__('mail.team_joined.where'))
            ->salutation(__('mail.salutation'));
    }

    /**
     * @return array<string, string>
     */
    public function toArray(object $notifiable): array
    {
        return ['building' => $this->buildingName, 'role' => $this->role];
    }
}
