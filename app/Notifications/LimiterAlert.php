<?php

declare(strict_types=1);

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Count-only security alert (MVP-SPEC A.6 "Alert payloads"): building, limiter name, window, count, threshold —
 * never a handle, plate, bidx, code, IP, user id or apartment (Feature/Notifications/AlertPayloadTest).
 */
final class LimiterAlert extends Notification
{
    use Queueable;

    public function __construct(
        public readonly ?string $buildingName,
        public readonly string $limiter,
        public readonly string $window,
        public readonly int $count,
        public readonly int $threshold,
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
            ->subject(__('alerts.subject', ['limiter' => __('alerts.limiter.'.$this->limiter)]))
            ->line(__('alerts.body', [
                'building' => $this->buildingName ?? __('alerts.platform'),
                'limiter' => __('alerts.limiter.'.$this->limiter),
                'count' => $this->count,
                'threshold' => $this->threshold,
                'window' => __('alerts.window.'.$this->window),
            ]))
            ->line(__('alerts.no_details'));
    }

    /**
     * @return array<string, int|string|null>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'building' => $this->buildingName,
            'limiter' => $this->limiter,
            'window' => $this->window,
            'count' => $this->count,
            'threshold' => $this->threshold,
        ];
    }
}
