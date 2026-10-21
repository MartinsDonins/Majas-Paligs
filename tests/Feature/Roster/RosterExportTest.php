<?php

declare(strict_types=1);

use App\Auth\StepUpService;
use App\Filament\Manage\Resources\ResidentContactResource\Pages\ListResidentContacts;
use App\Models\AuditEntry;
use App\Models\ResidentContact;
use App\Services\Roster\RosterExporter;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Livewire\Livewire;

/*
 * Feature/Roster/RosterExportTest (ROUND2-SPEC §5.3): "Eksportēt CSV" — building_admin + STRONG step-up (passkey / TOTP
 * of this session, a trusted device does not count), audited `roster.export` {rows}, 5 per building per day, signed
 * 10-minute link, generated on demand and never stored, formula-safe; elders cannot export. Plus the per-contact Art. 15
 * extract and the runtime import template (UTF-8 BOM, `;`, no phone-shaped string).
 */
beforeEach(function (): void {
    $this->b = rosterHouse('exp-house');
    $this->admin = rosterAdmin($this->b, 'exp-admin@example.test');
    $this->actingAs($this->admin);
    rosterContact($this->b, $this->admin, 1, '=HYPERLINK("http://x")', [syntheticPhone(61)], 'exp@example.test');
    rosterContact($this->b, $this->admin, 14, 'Otrs Kontakts');
});

function exportUrl(): string
{
    return URL::temporarySignedRoute('admin.roster.export', now()->addMinutes(10), ['building' => 'exp-house']);
}

it('exports the roster as a formula-safe ;-CSV with a strong step-up, audited, never stored', function (): void {
    Storage::fake('local');
    steppedUp($this->admin, 'totp');

    $response = $this->actingAs($this->admin)->get(exportUrl());
    $response->assertOk()->assertHeader('Content-Type', 'text/csv; charset=UTF-8')->assertHeader('Cache-Control', 'no-store, private');
    $csv = $response->getContent();

    expect($csv)->toStartWith("\xEF\xBB\xBF".'Dzīvoklis;Vārds;Telefons;E-pasts;Statuss;BIS;WhatsApp;Komentārs')
        ->toContain("'=HYPERLINK")
        ->toContain(syntheticPhone(61))
        ->toContain('exp@example.test')
        ->toContain('Otrs Kontakts');

    $entry = inBuilding($this->b, fn () => AuditEntry::query()->where('action', 'roster.export')->sole());
    expect($entry->data)->toBe(['rows' => 2])->and($entry->actor_role)->toBe('building_admin');
    expect(Storage::disk('local')->allFiles())->toBe([]);
});

it('refuses the export without a strong step-up (a trusted device or an e-mail code is not enough), and for elders', function (): void {
    steppedUp($this->admin, 'trusted');
    $this->actingAs($this->admin)->get(exportUrl())->assertForbidden();

    $elder = rosterElder($this->b, 1);
    $this->flushSession();
    steppedUp($elder, 'passkey');
    $this->actingAs($elder)->get(exportUrl())->assertForbidden();

    // An unsigned / tampered link never works.
    $this->flushSession();
    steppedUp($this->admin, 'totp');
    $this->actingAs($this->admin)->get('/admin/b/exp-house/files/roster-export')->assertForbidden();

    expect(inBuilding($this->b, fn () => AuditEntry::query()->where('action', 'roster.export')->count()))->toBe(0);
});

it('allows 5 exports per building per day', function (): void {
    config(['majas.roster.export_per_day' => 1]);
    steppedUp($this->admin, 'passkey');

    $this->actingAs($this->admin)->get(exportUrl())->assertOk();
    $this->actingAs($this->admin)->get(exportUrl())->assertStatus(429);
    expect(inBuilding($this->b, fn () => app(RosterExporter::class)->exportsToday()))->toBe(1);
});

it('asks for the strong step-up from the list header action, then hands out a signed link', function (): void {
    Filament::setCurrentPanel(Filament::getPanel('manage'));
    Filament::setTenant($this->b);

    app(StepUpService::class)->markFresh($this->admin, 'trusted');
    inBuilding($this->b, fn () => Livewire::actingAs($this->admin)->test(ListResidentContacts::class)
        ->callAction('export')
        ->assertRedirectContains('/step-up'));

    app(StepUpService::class)->markFresh($this->admin, 'totp');
    inBuilding($this->b, fn () => Livewire::actingAs($this->admin)->test(ListResidentContacts::class)
        ->callAction('export')
        ->assertRedirectContains('/files/roster-export?expires='));
});

it('gives a per-contact Art. 15 extract (JSON + CSV, audited) to the admin and the own-stairwell elder only', function (): void {
    $contact = inBuilding($this->b, fn () => ResidentContact::query()->where('apartment_id', apartment($this->b, 1)->id)->firstOrFail());
    $url = URL::temporarySignedRoute('admin.roster.extract', now()->addMinutes(10), ['building' => 'exp-house', 'contact' => $contact->id]);

    steppedUp($this->admin, 'totp');
    $zipBytes = $this->actingAs($this->admin)->get($url)->assertOk()->assertHeader('Content-Type', 'application/zip')->getContent();

    $tmp = tempnam(sys_get_temp_dir(), 'mpz');
    file_put_contents($tmp, $zipBytes);
    $zip = new ZipArchive;
    $zip->open($tmp);
    $json = json_decode((string) $zip->getFromName('contact.json'), true);
    $csv = (string) $zip->getFromName('contact.csv');
    $zip->close();
    @unlink($tmp);

    expect($json['phones'])->toBe([syntheticPhone(61)])
        ->and($json['email'])->toBe('exp@example.test')
        ->and(collect($json['audit'])->pluck('action')->all())->toContain('roster.create')
        ->and($csv)->toContain("'=HYPERLINK");
    expect(inBuilding($this->b, fn () => AuditEntry::query()->where('action', 'roster.subject_export')->count()))->toBe(1);

    $elder2 = rosterElder($this->b, 2);
    $this->flushSession();
    steppedUp($elder2, 'passkey');
    $this->actingAs($elder2)->get($url)->assertNotFound();
});

it('generates the import template at runtime: BOM, semicolon, LV / RU headers, no phone-shaped example', function (): void {
    steppedUp($this->admin);
    $lv = $this->actingAs($this->admin)->get('/admin/b/exp-house/files/import-template/roster.csv')->assertOk()->getContent();

    expect($lv)->toStartWith("\xEF\xBB\xBF".'Dzīvoklis;Vārds;Telefons;E-pasts;Statuss;BIS;WhatsApp;Komentārs')
        ->and(preg_match('/(\+|00)?371[ .-]?\d{2}[ .-]?\d{3}[ .-]?\d{3}|(^|\D)[26]\d{7}(\D|$)/', $lv))->toBe(0);

    $this->admin->forceFill(['locale' => 'ru'])->save();
    $ru = $this->actingAs($this->admin->refresh())->get('/admin/b/exp-house/files/import-template/roster.csv')->assertOk()->getContent();
    expect($ru)->toContain('Квартира;Имя;Телефон');

    // An elder may download the roster template, works below the gate too (no personal data).
    $elder = rosterElder($this->b, 1);
    $this->flushSession();
    $this->actingAs($elder)->get('/admin/b/exp-house/files/import-template/roster.csv')->assertOk();
    $this->actingAs($elder)->get('/admin/b/exp-house/files/import-template/unknown.csv')->assertNotFound();
});
