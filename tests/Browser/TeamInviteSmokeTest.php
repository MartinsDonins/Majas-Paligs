<?php

declare(strict_types=1);

use App\Auth\StepUpService;
use App\Enums\BuildingRoleType;
use App\Enums\Locale;
use App\Models\BuildingRole;
use App\Models\User;
use App\Services\Team\TeamInvitationService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;

/*
 * ROUND2-SPEC §12 Browser/TeamInviteSmokeTest (AC-R2-04): invite → real SMTP into Mailpit → the fragment link opens in
 * Chromium (CSP Alpine `fragmentAction` clears the address bar and hands the token to Livewire as an argument) → e-mail
 * code from Mailpit → 18+ and the account notice → "Pieņemt lomu" → passkey offer. Synthetic data only.
 */

/**
 * The invitation link (https://…/team-invite/{slug}#t=…) from the newest Mailpit message to $to.
 */
function mailpitInviteLink(string $to, int $seconds = 15): string
{
    $deadline = microtime(true) + $seconds;
    do {
        $id = Http::timeout(5)->get(mailpitUrl().'/api/v1/search', ['query' => 'to:"'.$to.'"', 'limit' => 5])->json('messages');
        foreach ((array) $id as $message) {
            $text = (string) Http::timeout(5)->get(mailpitUrl().'/api/v1/message/'.$message['ID'])->json('Text');
            if (preg_match('~(/team-invite/[a-z0-9-]+#t=[A-Za-z0-9_-]{40,})~', $text, $m) === 1) {
                return $m[1];
            }
        }
        usleep(250_000);
    } while (microtime(true) < $deadline);

    throw new RuntimeException("No invitation mail for {$to} in Mailpit.");
}

it('lets an invited person without an account accept a stairwell elder role in a real browser', function (): void {
    useMailpit();
    $b = makeBuilding('team-smoke', 5, 2);
    $admin = makeUser(browserEmail('team-admin'));
    grantRole($admin, $b, BuildingRoleType::BuildingAdmin);
    $email = browserEmail('team-elder');

    // The admin's side (strong step-up of the admin's session) — issued in-process, mailed through Mailpit.
    Auth::login($admin);
    session()->put(StepUpService::SESSION_KEY, ['user' => $admin->id, 'at' => now()->getTimestamp(), 'seen' => now()->getTimestamp(), 'method' => 'passkey']);
    inBuilding($b, fn () => app(TeamInvitationService::class)->invite($admin, $email, BuildingRoleType::SectionElder, [(int) section($b, 5)->id], null, Locale::Lv));
    Auth::logout();
    session()->flush();

    $link = mailpitInviteLink($email);
    // A real navigation to the mailed URL, fragment included (as when the link is tapped in a mail app).
    $page = phone('/');
    openPath($page, $link);
    $page->waitForText(__('team.accept.confirm_email'));
    $page->assertSee(__('enum.building_role.section_elder'))
        ->assertSee(__('admin.section.short', ['n' => 5]))
        ->assertNoJavaScriptErrors();
    // The token left the address bar at once (no history entry, nothing to share by accident).
    expect($page->script('location.hash'))->toBe('')
        ->and((string) $page->script('document.documentElement.outerHTML'))->not->toContain(explode('#t=', $link)[1]);

    $page->click('[data-testid="invite-send-code"]')->waitForText(__('auth.email.code_label'));
    $page->type('#invite-code', mailpitCode($email))
        ->click('[data-testid="invite-verify"]')
        ->waitForText(__('team.accept.create'));
    $page->check('[data-testid="invite-adult"]')
        ->check('[data-testid="invite-notice"]')
        ->click('[data-testid="invite-create"]')
        ->waitForText(__('team.accept.accept'));
    $page->assertNoJavaScriptErrors()->click('[data-testid="invite-accept"]');

    waitUntil($page, "location.pathname === '/me/passkey'", 15); // accept + login + redirect; slower under a loaded full run
    $page->assertNoJavaScriptErrors();

    $user = User::query()->where('email', $email)->firstOrFail();
    $roles = inBuilding($b, fn () => BuildingRole::query()->current()->where('user_id', $user->id)->get());
    expect($roles)->toHaveCount(1)
        ->and($roles[0]->role)->toBe(BuildingRoleType::SectionElder)
        ->and((int) $roles[0]->section_id)->toBe((int) section($b, 5)->id);

    // The used link is now just "no longer valid".
    $again = phone('/');
    openPath($again, $link);
    $again->waitForText(__('team.accept.invalid'));
});

/*
 * Karen R2: the first GET stored the phone language as `mp_locale`, so the invitation language never applied. A fresh
 * phone (no cookies) with the OTHER browser language opens the mailed link in the invitation language; the LV/RU
 * toggle still wins and keeps the invitation.
 */
it('opens the accept page in the invitation language on a phone set to the other language', function (string $invite, string $phoneLocale, string $other): void {
    useMailpit();
    $b = makeBuilding('team-lang-'.$invite, 5, 2);
    $admin = makeUser(browserEmail('team-lang-admin-'.$invite));
    grantRole($admin, $b, BuildingRoleType::BuildingAdmin);
    $email = browserEmail('team-lang-'.$invite);

    Auth::login($admin);
    session()->put(StepUpService::SESSION_KEY, ['user' => $admin->id, 'at' => now()->getTimestamp(), 'seen' => now()->getTimestamp(), 'method' => 'passkey']);
    inBuilding($b, fn () => app(TeamInvitationService::class)->invite($admin, $email, BuildingRoleType::SectionElder, [(int) section($b, 5)->id], null, Locale::from($invite)));
    Auth::logout();
    session()->flush();

    $link = mailpitInviteLink($email);
    $page = phone('/', locale: $phoneLocale);
    expect($page->script('document.documentElement.lang'))->toBe($other); // the phone language was detected first
    openPath($page, $link);
    $page->waitForText(trans('team.accept.confirm_email', [], $invite));
    expect($page->script('document.documentElement.lang'))->toBe($invite)
        ->and($page->script('location.hash'))->toBe('');
    $page->assertSee(trans('enum.building_role.section_elder', [], $invite))->assertNoJavaScriptErrors();

    // The person's own choice wins and the invitation stays open.
    $page->press(strtoupper($other));
    waitUntil($page, 'document.documentElement.lang === '.json_encode($other)." && document.readyState === 'complete'");
    $page->waitForText(trans('team.accept.confirm_email', [], $other));
    $page->assertSee(trans('enum.building_role.section_elder', [], $other))->assertNoJavaScriptErrors();
})->with([
    'RU invitation, LV phone' => ['ru', 'lv-LV', 'lv'],
    'LV invitation, RU phone' => ['lv', 'ru-RU', 'ru'],
]);

it('opens the /admin chooser and moves an old /manage bookmark into /admin/b in a real browser', function (): void {
    useMailpit();
    $a = makeBuilding('smoke-admin-a', 1, 1, open: true);
    $c = makeBuilding('smoke-admin-c', 1, 1);
    $email = browserEmail('chooser');
    $user = makeUser($email);
    member($user, $a, 1);
    grantRole($user, $a, BuildingRoleType::SectionElder, 1);
    grantRole($user, $c, BuildingRoleType::SectionElder, 1);

    $page = phone('/login');
    $page->type('email', $email)->press(__('auth.email.send'))->waitForText(__('auth.email.sent_title'));
    $page->type('code', mailpitCode($email))->press(__('auth.login'));
    waitUntil($page, "location.pathname.startsWith('/b/')", 10);

    $page->navigate('/admin')->waitForText(__('admin.entry.title'));
    $page->assertSee($a->name)->assertSee($c->name)->assertNoJavaScriptErrors();
    assertNoHorizontalScroll($page, '/admin');

    $page->navigate('/manage/smoke-admin-a/memberships');
    waitUntil($page, "location.pathname === '/admin/b/smoke-admin-a/memberships' || location.pathname === '/step-up'", 10);
});
