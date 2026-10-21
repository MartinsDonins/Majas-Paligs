<?php

declare(strict_types=1);

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use InvalidArgumentException;

/**
 * "Pievienota ieejas atslēga / noņemta / jauna uzticama ierīce" — mailed to the account on every change of how it can
 * sign in or skip a confirmation, so a takeover is noticed (ADR 0006; same idea as TotpChangedNotice).
 */
final class SecurityChangedNotice extends Notification
{
    use Queueable;

    public const KINDS = ['passkey_added', 'passkey_removed', 'device_trusted', 'factors_reset'];

    public function __construct(public readonly string $kind)
    {
        if (! in_array($kind, self::KINDS, true)) {
            throw new InvalidArgumentException('Unknown security change: '.$kind);
        }
    }

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return isset($notifiable->email) ? ['mail', 'database'] : ['database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(__('mail.security.'.$this->kind.'.subject'))
            ->line(__('mail.security.'.$this->kind.'.body'))
            ->line(__('mail.security.manage'))
            ->line(__('mail.totp.not_you'))
            ->salutation(__('mail.salutation'));
    }

    /**
     * @return array<string, string>
     */
    public function toArray(object $notifiable): array
    {
        return ['kind' => $this->kind];
    }
}
