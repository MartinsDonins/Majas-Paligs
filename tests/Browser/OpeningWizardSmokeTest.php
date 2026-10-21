<?php

declare(strict_types=1);

use App\Enums\BuildingRoleType;
use App\Enums\DocumentKind;
use App\Models\BuildingDocument;
use Database\Seeders\DocumentTemplateSeeder;

/*
 * ROUND2-SPEC §12 Browser/OpeningWizardSmokeTest (AC-R2-08 UI, AC-R2-18): the opening wizard in a real Chromium at a
 * phone width — admin e-mail login → passkey (counts as this session's second factor) → "Mājas atvēršana" → controller
 * saved → DPA prepared → the editor shows locked blocks read-only and editable blocks as textareas → saved; no
 * sideways scroll at 320 px, no JavaScript / CSP errors. The platform recording is covered by WizardFlowTest.
 * Synthetic data only.
 */
it('walks the first opening steps on a phone without sideways scroll', function (): void {
    useMailpit();
    (new DocumentTemplateSeeder)->run();
    $b = makeBuilding('wizard-smoke', 2, 1);
    $email = browserEmail('wizard-admin');
    $admin = makeUser($email);
    grantRole($admin, $b, BuildingRoleType::BuildingAdmin);

    $page = visit('/login')->withHost('localhost')->on()->mobile()->assertPathIs('/login');
    virtualAuthenticator($page);
    $page->type('email', $email)->press(__('auth.email.send'))->waitForText(__('auth.email.sent_title'));
    $page->type('code', mailpitCode($email))->press(__('auth.login'));
    waitUntil($page, "location.pathname === '/me/passkey'", 10);
    $page->click('[data-testid="passkey-register"]');
    waitUntil($page, "location.pathname !== '/me/passkey'", 15);

    openPath($page, "/admin/b/{$b->slug}/opening");
    waitUntil($page, "location.pathname === '/admin/b/{$b->slug}/opening'", 10);
    $page->assertSee(__('opening.title'))->assertSee(__('opening.step.controller.title'))->assertNoJavaScriptErrors();
    $page->resize(320, 720);
    $page->script('document.querySelectorAll("details").forEach(d => { d.open = true; })');
    assertNoHorizontalScroll($page, 'opening');

    // Controller.
    $page->type('[data-testid="controller-name"]', 'Testa biedrība')
        ->type('[data-testid="controller-contact"]', 'dati@example.test')
        ->click('[data-testid="controller-save"]');
    waitUntil($page, "document.querySelector('[data-testid=\"step-controller\"]').dataset.status === 'done'", 10);

    // DPA → editor with locked + editable blocks.
    $page->click('[data-testid="prepare-dpa"]');
    waitUntil($page, "!!document.querySelector('[data-testid=\"document-editor\"]')", 10);
    $page->assertSee(__('document.locked_block'))->assertSee(__('document.editable_block'))->assertNoJavaScriptErrors();
    assertNoHorizontalScroll($page, 'opening editor');

    $page->type('[data-testid="block-lv-contacts"]', 'Pārziņa kontaktpersona: valde.')
        ->click('[data-testid="editor-save"]');
    waitUntil($page, 'document.body.innerText.includes('.json_encode(__('opening.document.saved')).')', 10);

    $doc = inBuilding($b, fn () => BuildingDocument::latestOf(DocumentKind::Dpa));
    expect($doc)->not->toBeNull()->and($doc->body_lv)->toContain('Pārziņa kontaktpersona: valde.');
});
