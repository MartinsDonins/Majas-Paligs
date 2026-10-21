<?php

declare(strict_types=1);

use App\Auth\StepUpService;
use App\Enums\BuildingRoleType;
use App\Enums\DocumentKind;
use App\Enums\DocumentStatus;
use App\Enums\Locale;
use App\Filament\Manage\Pages\BuildingSettingsPage;
use App\Filament\Platform\Pages\OpeningRequestsPage;
use App\Filament\Platform\Resources\DocumentTemplateResource\Pages\ListDocumentTemplates;
use App\Models\AuditEntry;
use App\Models\BuildingDocument;
use App\Models\DocumentTemplate;
use App\Services\Documents\DocumentService;
use Database\Seeders\DocumentTemplateSeeder;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

/*
 * Platform side (ROUND2-SPEC §3.6): "Atvēršanas pieprasījumi" lists sent DPAs of every building through
 * BuildingIterator; recording runs inside PlatformBuildingContext (OpeningReview) — audited on the platform chain;
 * only platform_admin. Document masters: new version → draft → publish (immutable after). Settings: gate inputs are
 * read-only with a link to the wizard.
 */

beforeEach(function (): void {
    Notification::fake();
    (new DocumentTemplateSeeder)->run();
    $this->platform = makeUser('req-platform@example.test', ['platform_role' => 'platform_admin']);
});

function sentDpa(string $slug): array
{
    $b = makeBuilding($slug, 1, 1);
    $admin = makeUser("{$slug}-admin@example.test");
    grantRole($admin, $b, BuildingRoleType::BuildingAdmin);
    test()->actingAs($admin);
    app(StepUpService::class)->markFresh($admin, 'totp');
    $b->forceFill(['settings' => $b->settings->with(['controller' => ['name' => 'Testa biedrība', 'contact' => 'dati@example.test']])])->save();

    $doc = inBuilding($b, function () use ($admin) {
        $service = app(DocumentService::class);
        $doc = $service->prepare($admin, DocumentKind::Dpa, ['requested_scope' => ['core', 'heating', 'outages', 'parking']]);
        $service->finalize($admin, $doc);

        return $service->send($admin, $doc);
    });

    return [$b->refresh(), $doc, $admin];
}

function asPlatform(): void
{
    test()->actingAs(test()->platform);
    app(StepUpService::class)->markFresh(test()->platform, 'totp');
    Filament::setCurrentPanel(Filament::getPanel('platform'));
}

it('lists the sent documents of every building and records one inside the named platform context', function (): void {
    [$a, $docA] = sentDpa('req-a');
    [$b] = sentDpa('req-bbb'); // another name length → another synthetic street name
    asPlatform();

    Livewire::actingAs($this->platform)->test(OpeningRequestsPage::class)
        ->assertSee($a->name)->assertSee($b->name)
        ->callAction('record', data: ['dpa_signed_at' => '2026-10-21', 'dpa_ref' => 'DPA-A', 'dpa_scope' => ['core', 'heating', 'outages', 'parking']], arguments: ['building' => $a->id, 'document' => $docA->id, 'scope' => ['core', 'heating', 'outages', 'parking']])
        ->assertHasNoActionErrors();

    expect($a->refresh()->settings->dpaSignedAt)->toBe('2026-10-21')
        ->and($a->settings->dpaScope)->toBe(['core', 'heating', 'outages', 'parking'])
        ->and(inBuilding($a, fn () => BuildingDocument::query()->find($docA->id)->status))->toBe(DocumentStatus::Recorded)
        ->and($b->refresh()->settings->dpaSignedAt)->toBeNull();

    $context = AuditEntry::query()->where('action', 'platform.building_context')->where('data->purpose', 'opening_review')->get();
    expect($context)->not->toBeEmpty()
        ->and($context->every(fn (AuditEntry $e): bool => $e->building_id === null))->toBeTrue()
        ->and(AuditEntry::query()->where('building_id', $a->id)->where('action', 'document.record')->where('actor_role', 'platform_admin')->exists())->toBeTrue();

    // Recorded documents leave the queue; the PDF preview works inside the same context.
    Livewire::actingAs($this->platform)->test(OpeningRequestsPage::class)->assertDontSee($a->name)->assertSee($b->name);
});

it('is platform_admin only and never records a document twice', function (): void {
    [$a, $docA, $admin] = sentDpa('req-only');

    steppedUp($admin);
    $this->actingAs($admin)->get('/admin/platform/opening-requests')->assertForbidden();

    expect(fn () => inBuilding($a, fn () => app(DocumentService::class)->record($admin, $docA, '2026-10-21', 'X', ['core', 'heating', 'outages'])))
        ->toThrow(AuthorizationException::class);

    asPlatform();
    Livewire::actingAs($this->platform)->test(OpeningRequestsPage::class)
        ->callAction('record', data: ['dpa_signed_at' => '2026-10-21', 'dpa_ref' => 'DPA-1', 'dpa_scope' => ['core', 'heating', 'outages']], arguments: ['building' => $a->id, 'document' => $docA->id]);
    Livewire::actingAs($this->platform)->test(OpeningRequestsPage::class)
        ->callAction('record', data: ['dpa_signed_at' => '2026-10-22', 'dpa_ref' => 'DPA-2', 'dpa_scope' => ['core', 'heating', 'outages']], arguments: ['building' => $a->id, 'document' => $docA->id])
        ->assertNotified(__('document.error.not_sent'));

    expect($a->refresh()->settings->dpaRef)->toBe('DPA-1');
});

it('versions the masters: new version is a draft, publish freezes it, houses use the newest published', function (): void {
    asPlatform();
    $v1 = DocumentTemplate::latest(DocumentKind::Dpa, Locale::Lv);

    Livewire::actingAs($this->platform)->test(ListDocumentTemplates::class)
        ->callTableAction('newVersion', $v1);
    $v2 = DocumentTemplate::query()->where('kind', 'dpa')->where('locale', 'lv')->where('version', 2)->firstOrFail();
    expect($v2->isPublished())->toBeFalse()
        ->and(DocumentTemplate::latest(DocumentKind::Dpa, Locale::Lv)->version)->toBe(1);

    $v2->forceFill(['title' => 'Datu apstrādes līgums v2'])->save();
    Livewire::actingAs($this->platform)->test(ListDocumentTemplates::class)->callTableAction('publish', $v2);
    expect($v2->refresh()->isPublished())->toBeTrue()
        ->and(DocumentTemplate::latest(DocumentKind::Dpa, Locale::Lv)->version)->toBe(2)
        ->and(AuditEntry::query()->where('action', 'document_template.publish')->exists())->toBeTrue();

    $this->get('/admin/platform/document-templates/'.$v1->id.'/edit')->assertForbidden();
});

it('shows the controller, DPIA date and mandate read-only in Iestatījumi, with a link to the wizard', function (): void {
    $b = makeBuilding('req-settings', 1, 1, open: true);
    $admin = makeUser('req-settings-admin@example.test');
    grantRole($admin, $b, BuildingRoleType::BuildingAdmin);
    $this->actingAs($admin);
    app(StepUpService::class)->markFresh($admin, 'totp');
    Filament::setCurrentPanel(Filament::getPanel('manage'));
    Filament::setTenant($b);

    inBuilding($b, fn () => Livewire::actingAs($admin)->test(BuildingSettingsPage::class)
        ->assertSee(__('opening.settings_link'))
        ->set('data.controller_name', 'Uzlauzts')
        ->set('data.mandate_ref', 'Uzlauzts')
        ->set('data.module_heating', true)
        ->call('save')
        ->assertHasNoErrors());

    expect($b->refresh()->settings->controller['name'])->toBe('Testa biedrība')
        ->and($b->settings->mandateRef)->toBeNull()
        ->and($b->settings->moduleEnabled('heating'))->toBeTrue();
});
