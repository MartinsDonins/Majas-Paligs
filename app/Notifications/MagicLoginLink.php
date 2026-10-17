<?php

declare(strict_types=1);

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Login mail: link with the token in the URL FRAGMENT (`/login/confirm#t=…`, never a query or path) plus the
 * 6-digit code for the same login (MVP-SPEC A.6/A.6a). Sent synchronously after the token row is committed.
 */
final class MagicLoginLink extends Notification
{
    use Queueable;

    public function __construct(
        #[\SensitiveParameter] private readonly string $token,
        #[\SensitiveParameter] private readonly string $code,
    ) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function url(): string
    {
        return url('/login/confirm').'#t='.$this->token;
    }

    public function code(): string
    {
        return $this->code;
    }

    public function toMail(object $notifiable): MailMessage
    {
        $minutes = (int) config('majas.magic_link.ttl_minutes', 15);

        return (new MailMessage)
            ->subject(__('mail.login.subject'))
            ->greeting(__('mail.login.greeting'))
            ->line(__('mail.login.intro'))
            ->action(__('mail.login.button'), $this->url())
            ->line(__('mail.login.code', ['code' => chunk_split($this->code, 3, ' ')]))
            ->line(__('mail.login.expires', ['minutes' => $minutes]))
            ->line(__('mail.login.ignore'))
            ->salutation(__('mail.salutation'));
    }
}
