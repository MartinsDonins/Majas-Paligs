<?php

declare(strict_types=1);

use App\Livewire\Auth\EmailLogin;
use App\Notifications\MembershipDecided;
use App\Notifications\StepUpCode;
use Livewire\Livewire;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\Email;

/*
 * Production QA 2026-10-20 (valoda-ru): mails followed the SENDER's request locale and the default Laravel layout
 * strings were English ("Hello!", "If you're having trouble clicking…", "All rights reserved.").
 */

/**
 * @return list<Email>
 */
function sentMails(): array
{
    /** @var TransportInterface $transport */
    $transport = app('mailer')->getSymfonyTransport();

    return array_map(static fn ($m) => $m->getOriginalMessage(), iterator_to_array($transport->messages()));
}

it('renders a notification in the recipient\'s language, not the approver\'s', function (): void {
    app()->setLocale('lv');
    $ru = makeUser('qa-ru@example.test', ['locale' => 'ru']);

    $ru->notify(new MembershipDecided('Pūces iela 51', 35, true));

    $mail = collect(sentMails())->last();
    expect($mail->getSubject())->toBe(trans('mail.membership_decided.approved_subject', [], 'ru'))
        ->and((string) $mail->getTextBody())->toContain('Здравствуйте!')
        ->not->toContain('Hello!')
        ->not->toContain('All rights reserved.')
        ->and(app()->getLocale())->toBe('lv');
});

it('translates the action-URL fallback line and the footer of the login mail', function (string $locale, string $fallback, string $footer): void {
    makeUser("qa-mail-{$locale}@example.test", ['locale' => $locale === 'lv' ? 'ru' : 'lv']);
    app()->setLocale($locale);

    // The person asking is looking at the page in $locale: the login mail follows the page, not the account.
    Livewire::test(EmailLogin::class)->set('email', "qa-mail-{$locale}@example.test")->call('send');

    $mail = collect(sentMails())->last();
    $text = (string) $mail->getTextBody();
    expect($mail->getSubject())->toBe(trans('mail.login.subject', [], $locale))
        ->and($text)->toContain($fallback)->toContain($footer)
        ->not->toContain("If you're having trouble")
        ->not->toContain('All rights reserved');
})->with([
    ['lv', 'nedarbojas', 'Visas tiesības aizsargātas.'],
    ['ru', 'не работает', 'Все права защищены.'],
]);

it('sends the step-up code mail with a translated greeting', function (): void {
    $user = makeUser('qa-stepup-mail@example.test', ['locale' => 'ru']);
    app()->setLocale('ru');

    $user->notify((new StepUpCode('123456'))->locale('ru'));

    expect((string) collect(sentMails())->last()->getTextBody())->toContain('Здравствуйте!')->not->toContain('Hello!');
});

it('renders a 404 in the visitor\'s language, also for an unknown building', function (): void {
    $this->withCookie('mp_locale', 'ru')->get('/nonexistent-page')->assertNotFound()->assertSee('lang="ru"', false);

    $ru = makeUser('qa-404-ru@example.test', ['locale' => 'ru']);
    $this->actingAs($ru)->get('/b/unknown-slug')->assertNotFound()->assertSee('lang="ru"', false);
});
