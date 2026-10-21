<?php

declare(strict_types=1);

use App\Audit\AuditChainVerifier;
use App\Auth\StepUpService;
use App\Enums\BuildingRoleType;
use App\Enums\DocumentKind;
use App\Enums\DocumentStatus;
use App\Filament\Manage\Pages\HouseOpeningWizardPage;
use App\Filament\Platform\Pages\OpeningRequestsPage;
use App\Gdpr\CoreGate;
use App\Gdpr\ParkingGate;
use App\Livewire\Admin\DocumentEditor;
use App\Livewire\Onboarding\RedeemInvite;
use App\Models\AuditEntry;
use App\Models\Building;
use App\Models\BuildingDocument;
use App\Models\BuildingPrivacyNotice;
use App\Models\User;
use App\Notifications\CoreGateChanged;
use App\Notifications\DocumentRecorded;
use App\Notifications\OpeningRequestSent;
use App\Services\Opening\OpeningChecklist;
use Database\Seeders\DocumentTemplateSeeder;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

/*
 * House opening wizard (ROUND2-SPEC §3.2, AC-R2-08): an EMPTY building reaches L-core open through the wizard —
 * controller → DPA generated, edited, linted, finalised, sent → CoreDigify records it from "Atvēršanas pieprasījumi"
 * → notice published → /join accepts codes. Every step audited; CoreGateMonitor fires on opening.
 */

beforeEach(function (): void {
    Notification::fake();
    (new DocumentTemplateSeeder)->run();
    $this->b = makeBuilding('open-house', 2, 2);
    $this->admin = makeUser('open-admin@example.test');
    grantRole($this->admin, $this->b, BuildingRoleType::BuildingAdmin);
    $this->platform = makeUser('open-platform@example.test', ['platform_role' => 'platform_admin']);
    asOpeningAdmin();
});

function asOpeningAdmin(?User $user = null): void
{
    $user ??= test()->admin;
    test()->actingAs($user);
    app(StepUpService::class)->markFresh($user, 'totp');
    Filament::setCurrentPanel(Filament::getPanel('manage'));
    Filament::setTenant(test()->b);
}

function wizard(): mixed
{
    return Livewire::actingAs(test()->admin)->test(HouseOpeningWizardPage::class);
}

function openingDoc(DocumentKind $kind): ?BuildingDocument
{
    return inBuilding(test()->b, fn () => BuildingDocument::latestOf($kind));
}

it('leads an empty building to L-core open, every step audited (AC-R2-08)', function (): void {
    $b = $this->b;
    expect(inBuilding($b, fn () => CoreGate::status($b)->open))->toBeFalse();

    // 1. Controller.
    inBuilding($b, fn () => wizard()
        ->set('controller.name', 'Testa biedrība')
        ->set('controller.contact', 'dati@example.test')
        ->set('controller.basis_ref', 'Kopības lēmums Nr. 1')
        ->call('saveController')
        ->assertHasNoErrors());
    expect($b->refresh()->settings->controller['name'])->toBe('Testa biedrība');

    // 2. DPA: prepare (scope incl. roster), edit an allowed block, finalise (lint), send.
    inBuilding($b, fn () => wizard()->set('dpaScope', ['core', 'heating', 'outages', 'roster'])->call('prepareAgreement', null)->assertSet('editingId', fn ($id) => $id !== null));
    $dpa = openingDoc(DocumentKind::Dpa);
    expect($dpa->status)->toBe(DocumentStatus::Draft)
        ->and($dpa->requestedScope())->toBe(['core', 'heating', 'outages', 'roster']);

    inBuilding($b, fn () => Livewire::actingAs($this->admin)->test(DocumentEditor::class, ['documentId' => $dpa->id])
        ->set('lv.contacts', 'Pārziņa kontaktpersona: valdes priekšsēdētājs.')
        ->set('ru.contacts', 'Контактное лицо контролёра: председатель правления.')
        ->call('save')
        ->assertDispatched('document-saved'));
    expect(openingDoc(DocumentKind::Dpa)->body_lv)->toContain('valdes priekšsēdētājs');

    inBuilding($b, fn () => wizard()->call('finalize', $dpa->id)->assertSet('problems', []));
    $dpa = openingDoc(DocumentKind::Dpa);
    expect($dpa->status)->toBe(DocumentStatus::Final)
        ->and($dpa->meta['snapshot']['lv']['controller.name'])->toBe('Testa biedrība');

    inBuilding($b, fn () => wizard()->call('send', $dpa->id));
    expect(openingDoc(DocumentKind::Dpa)->status)->toBe(DocumentStatus::Sent)
        ->and(inBuilding($b, fn () => OpeningChecklist::for($b->refresh())->status('dpa')))->toBe(OpeningChecklist::WAITING);
    Notification::assertSentTo($this->platform, OpeningRequestSent::class);

    // 3. CoreDigify records the signed DPA from "Atvēršanas pieprasījumi".
    $this->actingAs($this->platform);
    app(StepUpService::class)->markFresh($this->platform, 'totp');
    Filament::setCurrentPanel(Filament::getPanel('platform'));
    Livewire::actingAs($this->platform)->test(OpeningRequestsPage::class)
        ->assertSee($b->name)
        ->callAction('record', data: ['dpa_signed_at' => '2026-10-22', 'dpa_ref' => 'DPA-OPEN-1', 'dpa_scope' => ['core', 'heating', 'outages', 'roster']], arguments: ['building' => $b->id, 'document' => $dpa->id, 'scope' => $dpa->requestedScope()])
        ->assertHasNoActionErrors();

    $b->refresh();
    expect($b->settings->dpaRef)->toBe('DPA-OPEN-1')
        ->and($b->settings->dpaScope)->toBe(['core', 'heating', 'outages', 'roster'])
        ->and(openingDoc(DocumentKind::Dpa)->status)->toBe(DocumentStatus::Recorded)
        ->and(AuditEntry::query()->where('action', 'platform.building_context')->where('data->purpose', 'opening_review')->exists())->toBeTrue();
    Notification::assertSentTo($this->admin, DocumentRecorded::class);

    // 4. Notice: prepare from the master, publish (lint + finalise) → L-core opens.
    asOpeningAdmin();
    // Every recorded module is pre-selected; the house leaves the roster section out for now.
    inBuilding($b, fn () => wizard()->assertSet('noticeModules', ['core', 'heating', 'outages', 'roster'])
        ->set('noticeModules', ['core', 'heating', 'outages'])->call('prepareNotice', null));
    $notice = openingDoc(DocumentKind::PrivacyNotice);
    expect($notice->coversModules())->toBe(['core', 'heating', 'outages']);
    inBuilding($b, fn () => wizard()->call('publishNotice', $notice->id)->assertSet('problems', []));

    $b->refresh();
    expect(inBuilding($b, fn () => CoreGate::status($b)->open))->toBeTrue()
        ->and($b->settings->openingCompletedAt)->not->toBeNull()
        ->and(inBuilding($b, fn () => BuildingPrivacyNotice::current()?->document_id))->toBe($notice->id);
    Notification::assertSentTo($this->admin, CoreGateChanged::class, fn (CoreGateChanged $n): bool => $n->open);

    // The public notice is the house's own text with the controller filled in; the roster section is not shown
    // (the notice does not cover the roster yet).
    $this->get('/privacy/open-house')->assertOk()
        ->assertSee('Testa biedrība')->assertSee('Kas atbild par Jūsu datiem')->assertDontSee('Iedzīvotāju saraksts');

    // 5. /join accepts codes.
    $code = makeInvite($b, 1);
    Livewire::test(RedeemInvite::class)->set('code', $code)->call('submit')->assertSet('closed', false);

    $actions = AuditEntry::query()->where('building_id', $b->id)->pluck('action')->all();
    expect($actions)->toContain('opening.controller', 'document.create', 'document.update', 'document.finalize', 'document.send', 'document.record', 'settings.dpa', 'privacy_notice.publish', 'gate.core.opened')
        ->and(app(AuditChainVerifier::class)->verify((int) $b->id)->valid)->toBeTrue();
});

it('lets only a building_admin act, with a fresh second factor; elders and officers read', function (): void {
    $elder = makeUser('open-elder@example.test');
    grantRole($elder, $this->b, BuildingRoleType::SectionElder, 1);
    $officer = parkingOfficer($this->b, 'open-officer@example.test');

    foreach ([$elder, $officer] as $reader) {
        asOpeningAdmin($reader);
        inBuilding($this->b, fn () => Livewire::actingAs($reader)->test(HouseOpeningWizardPage::class)
            ->assertOk()
            ->assertSee(__('opening.read_only'))
            ->set('controller.name', 'Uzurpators')->set('controller.contact', 'x@example.test')
            ->call('saveController')
            ->assertNotified(__('opening.error.admin_only')));
    }
    expect($this->b->refresh()->settings->controller['name'])->toBeNull();

    // The officer sees the parking steps only.
    asOpeningAdmin($officer);
    inBuilding($this->b, fn () => Livewire::actingAs($officer)->test(HouseOpeningWizardPage::class)
        ->assertSeeHtml('id="step-dpia"')->assertDontSeeHtml('id="step-controller"'));

    // An admin without a fresh second factor is sent to the step-up first.
    asOpeningAdmin();
    app(StepUpService::class)->forget();
    inBuilding($this->b, fn () => wizard()
        ->set('controller.name', 'Testa biedrība')->set('controller.contact', 'dati@example.test')
        ->call('saveController')
        ->assertRedirect(route('step-up', absolute: false)));
    expect($this->b->refresh()->settings->controller['name'])->toBeNull();

    // A resident without a role cannot open the page at all.
    $resident = makeUser('open-resident@example.test');
    steppedUp($resident);
    $this->actingAs($resident)->get('/admin/b/open-house/opening')->assertForbidden();
});

it('shows the progress per gate and the step statuses', function (): void {
    inBuilding($this->b, fn () => wizard()
        ->assertSee(__('opening.group.core'))
        ->assertSeeHtml('data-testid="progress-core">'.__('opening.progress', ['done' => 1, 'total' => 4]))
        ->assertSeeHtml('data-testid="progress-roster">'.__('opening.progress', ['done' => 0, 'total' => 3]))
        ->assertSeeHtml('data-testid="progress-parking">'.__('opening.progress', ['done' => 0, 'total' => 6]))
        ->assertSee(__('opening.why.controller')));
});

it('refuses a notice covering modules outside the recorded DPA scope and requires the controller first', function (): void {
    openGate($this->b);
    // openGate() publishes a template notice; the house now prepares its own with parking — not in the DPA scope.
    inBuilding($this->b, fn () => wizard()->set('noticeModules', ['core', 'heating', 'outages', 'parking'])->call('prepareNotice', null)
        ->assertNotified(__('document.error.scope_outside_dpa')));
    expect(openingDoc(DocumentKind::PrivacyNotice))->toBeNull();
});

it('completes the parking path: annex → L1, DPIA-lite → mandate → notice with parking → module on (L2)', function (): void {
    $b = $this->b;
    openGate($b);

    // Annex extends the recorded scope with parking.
    inBuilding($b, fn () => wizard()->call('prepareAgreement', 'parking'));
    $annex = openingDoc(DocumentKind::DpaAnnex);
    expect($annex->requestedScope())->toBe(['core', 'heating', 'outages', 'parking']);
    inBuilding($b, fn () => wizard()->call('finalize', $annex->id)->call('send', $annex->id));

    $this->actingAs($this->platform);
    app(StepUpService::class)->markFresh($this->platform, 'totp');
    Filament::setCurrentPanel(Filament::getPanel('platform'));
    Livewire::actingAs($this->platform)->test(OpeningRequestsPage::class)
        ->callAction('record', data: ['dpa_signed_at' => '2026-10-22', 'dpa_ref' => 'DPA-TEST-1 + A1', 'dpa_scope' => $annex->requestedScope()], arguments: ['building' => $b->id, 'document' => $annex->id, 'scope' => $annex->requestedScope()]);
    expect(inBuilding($b, fn () => ParkingGate::status($b->refresh())->level))->toBe(1);

    asOpeningAdmin();
    // DPIA-lite: incomplete without the sign-off and the acceptance.
    inBuilding($b, fn () => wizard()->call('prepareDpia')->call('completeDpia')->assertNotified(__('document.error.dpia_incomplete')));
    expect($b->refresh()->settings->dpiaCompletedAt)->toBeNull();

    $dpia = openingDoc(DocumentKind::DpiaLite);
    inBuilding($b, fn () => wizard()->call('edit', $dpia->id)
        ->set('answers.signoff.answer', 'Valdes priekšsēdētājs')
        ->set('answers.residual.accepted', true)
        ->call('completeDpia')
        ->assertNotified(__('opening.dpia.completed')));
    expect($b->refresh()->settings->dpiaCompletedAt)->not->toBeNull()
        ->and(openingDoc(DocumentKind::DpiaLite)->status)->toBe(DocumentStatus::Final);

    inBuilding($b, fn () => wizard()->set('mandateRef', 'Kopības lēmums Nr. 4 no 01.10.2026')->call('saveMandate'));
    expect($b->refresh()->settings->mandateRef)->toBe('Kopības lēmums Nr. 4 no 01.10.2026');

    // The module cannot be switched on before L2.
    inBuilding($b, fn () => wizard()->call('setParking', true)->assertNotified(__('document.error.parking_needs_l2')));

    inBuilding($b, fn () => wizard()->call('prepareNotice', 'parking'));
    $notice = openingDoc(DocumentKind::PrivacyNotice);
    expect($notice->coversModules())->toBe(['core', 'heating', 'outages', 'parking']);
    inBuilding($b, fn () => wizard()->call('publishNotice', $notice->id));
    expect(inBuilding($b, fn () => ParkingGate::status($b->refresh())->level))->toBe(2);
    $this->get('/privacy/open-house')->assertSee('Stāvvieta');

    inBuilding($b, fn () => wizard()->call('setParking', true));
    expect($b->refresh()->settings->moduleEnabled('parking'))->toBeTrue()
        ->and(inBuilding($b, fn () => OpeningChecklist::for($b)->progress('parking')))->toBe(['done' => 6, 'total' => 6]);

    expect(AuditEntry::query()->where('building_id', $b->id)->pluck('action')->all())
        ->toContain('opening.dpia', 'opening.mandate', 'opening.parking_on', 'gate.parking.changed');
});

it('marks the Art. 14 roster information date and keeps documents tenant-scoped', function (): void {
    $b = $this->b;
    openGate($b);
    inBuilding($b, fn () => wizard()->call('prepareAgreement', 'roster'));
    $annex = openingDoc(DocumentKind::DpaAnnex);

    // Another building's admin cannot reach this document by id.
    $other = makeBuilding('open-other', 1, 1);
    $otherAdmin = makeUser('open-other-admin@example.test');
    grantRole($otherAdmin, $other, BuildingRoleType::BuildingAdmin);
    test()->actingAs($otherAdmin);
    app(StepUpService::class)->markFresh($otherAdmin, 'totp');
    Filament::setTenant($other);
    inBuilding($other, fn () => Livewire::actingAs($otherAdmin)->test(HouseOpeningWizardPage::class)->call('edit', $annex->id)->assertNotFound());

    asOpeningAdmin();
    inBuilding($b, fn () => wizard()->set('rosterInformedAt', '2026-10-20')->call('markRosterInformed'));
    expect($b->refresh()->settings->rosterInformedAt)->toBe('2026-10-20');
});

it('builds the checklist cards for a building with nothing done', function (): void {
    $c = inBuilding($this->b, fn (Building $b) => OpeningChecklist::for($b));

    expect($c->status('structure'))->toBe(OpeningChecklist::DONE)
        ->and($c->status('controller'))->toBe(OpeningChecklist::TODO)
        ->and($c->status('roster_inform'))->toBe(OpeningChecklist::OPTIONAL)
        ->and($c->gateCards()['core']['step'])->toBe('controller')
        ->and($c->gateCards()['roster']['step'])->toBe('controller')
        ->and($c->gateCards()['parking']['chip'])->toBe('l0');
});
