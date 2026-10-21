<?php

declare(strict_types=1);

use App\Auth\StepUpService;
use App\Enums\BuildingRoleType;
use App\Enums\DocumentKind;
use App\Enums\Locale;
use App\Filament\Manage\Pages\DocumentsPage;
use App\Models\DocumentTemplate;
use App\Services\Documents\DocumentRenderer;
use App\Services\Documents\DocumentService;
use App\Services\Documents\TemplateLinter;
use App\Services\Documents\TemplateMarkup;
use Database\Seeders\DocumentTemplateSeeder;
use Illuminate\Support\Facades\Notification;

/*
 * Rendering house documents (ROUND2-SPEC §3.4): sanitised Markdown, escaped placeholder values, conditional notice
 * sections, A4 PDF with DejaVu Sans in LV and RU; the seeded masters are complete and lint-clean.
 */

beforeEach(function (): void {
    Notification::fake();
    (new DocumentTemplateSeeder)->run();
    $this->b = makeBuilding('render-house', 1, 1);
    $this->admin = makeUser('render-admin@example.test');
    grantRole($this->admin, $this->b, BuildingRoleType::BuildingAdmin);
    $this->actingAs($this->admin);
    app(StepUpService::class)->markFresh($this->admin, 'totp');
});

it('sanitises Markdown: no raw HTML, no unsafe links', function (): void {
    $html = app(DocumentRenderer::class)->html("## Virsraksts\n\n<script>alert(1)</script>\n\n[ok](https://example.test) [bad](javascript:alert(1)) <img src=x onerror=alert(1)>");

    expect($html)->toContain('<h2>Virsraksts</h2>')
        ->toContain('href="https://example.test"')
        ->not->toContain('<script')
        ->not->toContain('javascript:')
        ->not->toContain('onerror');
});

it('escapes a typed controller name, so it can never inject a link or markup', function (): void {
    $this->b->forceFill(['settings' => $this->b->settings->with(['controller' => [
        'name' => '[Klikšķini](https://evil.example) <b>Valde</b>',
        'contact' => 'dati@example.test',
    ]])])->save();

    $html = inBuilding($this->b, function () {
        $doc = app(DocumentService::class)->prepare($this->admin, DocumentKind::Dpa, ['requested_scope' => ['core', 'heating', 'outages']]);

        return app(DocumentRenderer::class)->documentHtml($doc, $this->b->refresh(), 'lv');
    });

    expect($html)->not->toContain('href="https://evil.example"')->not->toContain('<b>Valde')
        ->toContain('dati@example.test')
        ->toContain('A pielikums')->not->toContain('{dpa.scope_list}')->not->toContain('<!--');
});

it('fills the scope list and the retention table, and shows notice sections only for covered modules', function (): void {
    $this->b->forceFill(['settings' => $this->b->settings->with(['dpa_scope' => ['core', 'heating', 'outages', 'parking']])])->save();
    $renderer = app(DocumentRenderer::class);
    $body = DocumentTemplate::latest(DocumentKind::PrivacyNotice, Locale::Lv)->body;
    $values = inBuilding($this->b, fn () => $renderer->values($this->b->refresh(), 'lv', ['core', 'heating', 'outages', 'parking']));

    $withParking = $renderer->noticeMarkdown($body, $values, ['core', 'heating', 'outages', 'parking']);
    $without = $renderer->noticeMarkdown($body, $values, ['core', 'heating', 'outages']);

    expect($withParking)->toContain('## Stāvvieta')->not->toContain('## Iedzīvotāju saraksts')
        ->toContain(__('retention.row.plate_lookups', locale: 'lv'))
        ->and($without)->not->toContain('## Stāvvieta')
        ->and($values['dpa.scope_list'])->toContain(__('enum.module.parking', locale: 'lv'));
});

it('renders an A4 PDF with DejaVu Sans in LV and RU', function (string $locale): void {
    $bytes = inBuilding($this->b, function () use ($locale) {
        $doc = app(DocumentService::class)->prepare($this->admin, DocumentKind::Dpa, ['requested_scope' => ['core', 'heating', 'outages']]);

        return app(DocumentRenderer::class)->pdfBytes($doc, $this->b, $locale);
    });

    expect($bytes)->toStartWith('%PDF-')->toContain('DejaVu');
})->with(['lv', 'ru']);

it('seeds every kind in LV and RU, published, with the same blocks in both languages and lint-clean', function (): void {
    $linter = app(TemplateLinter::class);

    foreach (DocumentKind::cases() as $kind) {
        $lv = DocumentTemplate::latest($kind, Locale::Lv);
        $ru = DocumentTemplate::latest($kind, Locale::Ru);

        expect($lv)->not->toBeNull()->and($ru)->not->toBeNull()
            ->and($lv->required_markers)->toBe($ru->required_markers)
            ->and(array_keys(TemplateMarkup::editable($lv->body)))->toBe(array_keys(TemplateMarkup::editable($ru->body)))
            ->and($linter->checkBody($lv->body, $lv, 'lv')[0])->toBe([])
            ->and($linter->checkBody($ru->body, $ru, 'ru')[0])->toBe([]);
    }

    // Idempotent: a second run adds nothing.
    $count = DocumentTemplate::query()->count();
    (new DocumentTemplateSeeder)->run();
    expect(DocumentTemplate::query()->count())->toBe($count)->toBe(count(DocumentKind::cases()) * 2);
});

it('lists the house documents with PDF downloads: admin all, officer only the DPIA-lite, elder none', function (): void {
    Filament\Facades\Filament::setTenant($this->b);
    $this->b->forceFill(['settings' => $this->b->settings->with(['dpa_scope' => ['core', 'heating', 'outages', 'parking']])])->save();
    [$dpa, $dpia] = inBuilding($this->b, fn () => [
        app(DocumentService::class)->prepare($this->admin, DocumentKind::Dpa, ['requested_scope' => ['core', 'heating', 'outages']]),
        app(DocumentService::class)->prepare($this->admin, DocumentKind::DpiaLite),
    ]);

    inBuilding($this->b, fn () => Livewire\Livewire::actingAs($this->admin)->test(DocumentsPage::class)
        ->assertSee(DocumentKind::Dpa->label())->assertSee(DocumentKind::DpiaLite->label())
        ->call('download', $dpa->id, 'ru')
        ->assertFileDownloaded());

    $officer = parkingOfficer($this->b, 'render-officer@example.test');
    $this->actingAs($officer);
    app(StepUpService::class)->markFresh($officer, 'totp');
    inBuilding($this->b, fn () => Livewire\Livewire::actingAs($officer)->test(DocumentsPage::class)
        ->assertSee(DocumentKind::DpiaLite->label())->assertDontSee(DocumentKind::Dpa->label())
        ->call('download', $dpa->id, 'lv')
        ->assertForbidden());

    $elder = makeUser('render-elder@example.test');
    grantRole($elder, $this->b, BuildingRoleType::SectionElder, 1);
    steppedUp($elder);
    $this->actingAs($elder)->get('/admin/b/render-house/documents')->assertForbidden();
});
