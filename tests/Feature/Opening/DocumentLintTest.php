<?php

declare(strict_types=1);

use App\Auth\StepUpService;
use App\Enums\BuildingRoleType;
use App\Enums\DocumentKind;
use App\Enums\DocumentStatus;
use App\Filament\Manage\Pages\HouseOpeningWizardPage;
use App\Livewire\Admin\DocumentEditor;
use App\Models\BuildingDocument;
use App\Services\Documents\DocumentRefused;
use App\Services\Documents\DocumentService;
use Database\Seeders\DocumentTemplateSeeder;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

/*
 * AC-R2-09 — editing a locked block, removing a required marker, adding raw HTML or a phone-shaped string blocks
 * finalising with a per-block error, LV and RU; a forged Livewire call can never write a locked block; a finalised
 * document is frozen in the database.
 */

beforeEach(function (): void {
    Notification::fake();
    (new DocumentTemplateSeeder)->run();
    $this->b = makeBuilding('lint-house', 1, 1);
    $this->admin = makeUser('lint-admin@example.test');
    grantRole($this->admin, $this->b, BuildingRoleType::BuildingAdmin);
    $this->actingAs($this->admin);
    app(StepUpService::class)->markFresh($this->admin, 'totp');
    Filament::setTenant($this->b);
    $this->doc = inBuilding($this->b, fn () => app(DocumentService::class)->prepare($this->admin, DocumentKind::Dpa, ['requested_scope' => ['core', 'heating', 'outages']]));
});

/**
 * @return list<array{locale: string, block: string, code: string}>
 */
function finalizeErrors(BuildingDocument $doc): array
{
    try {
        inBuilding(test()->b, fn () => app(DocumentService::class)->finalize(test()->admin, $doc->refresh()));
    } catch (DocumentRefused $e) {
        expect($e->reason)->toBe('lint_failed');

        return $e->errors;
    }

    return [];
}

function forceBody(BuildingDocument $doc, string $locale, callable $change): void
{
    DB::table('building_documents')->where('id', $doc->id)->update(['body_'.$locale => $change($doc->refresh()->body($locale))]);
}

it('refuses a locked block changed in the stored body (LV) and a removed required marker (RU)', function (): void {
    forceBody($this->doc, 'lv', fn (string $b) => str_replace('Puses noslēdz šo līgumu', 'Puses vienojas citādi', $b));
    forceBody($this->doc, 'ru', fn (string $b) => preg_replace('/<!-- req:breach -->.*?<!-- \/req -->/s', '', $b));

    expect(finalizeErrors($this->doc))->toContain(
        ['locale' => 'lv', 'block' => 'parties', 'code' => 'locked_changed'],
        ['locale' => 'ru', 'block' => 'breach', 'code' => 'marker_missing'],
    );
    expect($this->doc->refresh()->status)->toBe(DocumentStatus::Draft);
});

it('refuses raw HTML, phone-shaped strings and an empty block in the other language', function (): void {
    $phone = '+371 2'.str_repeat('0', 3).' '.str_repeat('5', 4);

    inBuilding($this->b, fn () => app(DocumentService::class)->saveBlocks($this->admin, $this->doc, [
        'instructions' => '<img src=x onerror=alert(1)>',
        'contacts' => '',
    ], [
        'notices' => "Zvaniet {$phone}",
    ]));

    expect(finalizeErrors($this->doc))->toContain(
        ['locale' => 'lv', 'block' => 'instructions', 'code' => 'raw_html'],
        ['locale' => 'ru', 'block' => 'notices', 'code' => 'phone_shaped'],
        ['locale' => 'lv', 'block' => 'contacts', 'code' => 'editable_empty'],
    );
});

it('shows the per-block errors in the wizard, LV and RU', function (): void {
    inBuilding($this->b, fn () => app(DocumentService::class)->saveBlocks($this->admin, $this->doc, [], ['instructions' => '<b>x</b>']));

    inBuilding($this->b, fn () => Livewire::actingAs($this->admin)->test(HouseOpeningWizardPage::class)
        ->call('edit', $this->doc->id)
        ->call('finalize', $this->doc->id)
        ->assertNotified(__('document.error.lint_failed'))
        ->assertSee('RU · '.__('document.block.named', ['key' => 'instructions']).': '.__('document.lint.raw_html')));
});

it('never writes a locked block, even from a forged Livewire call', function (): void {
    $before = $this->doc->body_lv;

    expect(fn () => inBuilding($this->b, fn () => app(DocumentService::class)->saveBlocks($this->admin, $this->doc, ['parties' => 'UZLAUZTS'], [])))
        ->toThrow(DocumentRefused::class);

    inBuilding($this->b, fn () => Livewire::actingAs($this->admin)->test(DocumentEditor::class, ['documentId' => $this->doc->id])
        ->set('lv.parties', 'UZLAUZTS')
        ->call('save')
        ->assertNotified(__('document.error.block_locked')));

    expect($this->doc->refresh()->body_lv)->toBe($before)->not->toContain('UZLAUZTS');
});

it('freezes a finalised document in the database and keeps published masters immutable', function (): void {
    inBuilding($this->b, fn () => app(DocumentService::class)->finalize($this->admin, $this->doc));

    // Each refused statement runs in its own savepoint (the test transaction must stay usable).
    $refused = fn (callable $statement) => fn () => DB::transaction($statement);
    expect($refused(fn () => DB::table('building_documents')->where('id', $this->doc->id)->update(['body_lv' => 'x'])))
        ->toThrow(QueryException::class);
    expect($refused(fn () => DB::table('building_documents')->where('id', $this->doc->id)->update(['status' => 'draft'])))
        ->toThrow(QueryException::class);
    expect($refused(fn () => DB::table('document_templates')->where('kind', 'dpa')->update(['body' => 'x'])))
        ->toThrow(QueryException::class);
    expect($refused(fn () => DB::table('document_templates')->where('kind', 'dpa')->delete()))
        ->toThrow(QueryException::class);

    expect(fn () => inBuilding($this->b, fn () => app(DocumentService::class)->saveBlocks($this->admin, $this->doc->refresh(), ['notes' => 'x'], [])))
        ->toThrow(DocumentRefused::class);
});

it('requires building_admin and a fresh second factor for every document write', function (): void {
    $elder = makeUser('lint-elder@example.test');
    grantRole($elder, $this->b, BuildingRoleType::SectionElder, 1);
    $this->actingAs($elder);
    app(StepUpService::class)->markFresh($elder, 'email');

    expect(fn () => inBuilding($this->b, fn () => app(DocumentService::class)->finalize($elder, $this->doc)))
        ->toThrow(AuthorizationException::class);

    $this->actingAs($this->admin);
    app(StepUpService::class)->forget();
    expect(fn () => inBuilding($this->b, fn () => app(DocumentService::class)->finalize($this->admin, $this->doc)))
        ->toThrow(AuthorizationException::class);
});
