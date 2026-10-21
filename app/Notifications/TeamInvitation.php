<?php

declare(strict_types=1);

namespace App\Notifications;

use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * "Jūs aicina palīdzēt pārvaldīt māju" — the team invitation mail (ROUND2-SPEC §2.2). The link carries the token in the
 * URL FRAGMENT (`/team-invite/{slug}#t=…`, never a query or path), so it never reaches a server log. Sent in the
 * invitation's language to the invited address (on-demand route — the person may not have an account yet).
 */
final class TeamInvitation extends Notification
{
    use Queueable;

    /**
     * @param  list<int>  $sectionNumbers
     */
    public function __construct(
        public readonly string $buildingName,
        public readonly string $slug,
        public readonly string $role,
        public readonly array $sectionNumbers,
        #[\SensitiveParameter] private readonly string $token,
        public readonly CarbonImmutable $expiresAt,
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
        return route('team.accept', ['building' => $this->slug]).'#t='.$this->token;
    }

    public function toMail(object $notifiable): MailMessage
    {
        $role = __('enum.building_role.'.$this->role);
        $mail = (new MailMessage)
            ->subject(__('mail.team_invite.subject', ['building' => $this->buildingName]))
            ->greeting(__('mail.login.greeting'))
            ->line(__('mail.team_invite.intro', ['building' => $this->buildingName, 'role' => $role]));

        if ($this->sectionNumbers !== []) {
            $mail->line(__('mail.team_invite.sections', ['sections' => implode(', ', array_map(static fn (int $n): string => __('admin.section.short', ['n' => $n]), $this->sectionNumbers))]));
        }

        return $mail
            ->line(__('mail.team_invite.role_help.'.$this->role))
            ->action(__('mail.team_invite.button'), $this->url())
            ->line(__('mail.team_invite.expires', ['date' => $this->expiresAt->setTimezone('Europe/Riga')->format('d.m.Y')]))
            ->line(__('mail.team_invite.ignore'))
            ->salutation(__('mail.salutation'));
    }
}
