<?php

declare(strict_types=1);

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * 6-digit code that proves the invited address before a team invitation is accepted (15 minutes, one invitation).
 */
final class TeamInviteCode extends Notification
{
    use Queueable;

    public function __construct(#[\SensitiveParameter] private readonly string $code) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function code(): string
    {
        return $this->code;
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(__('mail.team_code.subject'))
            ->line(__('mail.team_code.intro'))
            ->line(__('mail.step_up.code', ['code' => chunk_split($this->code, 3, ' ')]))
            ->line(__('mail.login.expires', ['minutes' => (int) config('majas.magic_link.ttl_minutes', 15)]))
            ->line(__('mail.login.ignore'))
            ->salutation(__('mail.salutation'));
    }
}
