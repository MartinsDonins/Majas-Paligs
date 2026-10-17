<?php

declare(strict_types=1);

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * 6-digit step-up confirmation code for elevated actions (valid 10 minutes).
 */
final class StepUpCode extends Notification
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
            ->subject(__('mail.step_up.subject'))
            ->line(__('mail.step_up.intro'))
            ->line(__('mail.step_up.code', ['code' => chunk_split($this->code, 3, ' ')]))
            ->line(__('mail.step_up.ignore'))
            ->salutation(__('mail.salutation'));
    }
}
