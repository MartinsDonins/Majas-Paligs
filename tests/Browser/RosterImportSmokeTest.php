<?php

declare(strict_types=1);

use App\Models\ImportRun;
use App\Models\ResidentContact;

/*
 * ROUND2-SPEC §12 "Browser/RosterImportSmokeTest" — the roster import and list in real Chromium (Vite build, Livewire
 * upload, Alpine): upload a synthetic CSV → mapping → masked preview → dry-run → commit with the Art. 14 confirmation;
 * the list shows masked phones, "Rādīt kontaktus" unmasks them with tel: links; no phone ever sits in a
 * `wire:snapshot`; both pages reflow at 320 px without sideways scroll. Synthetic data only (runtime phones).
 */
function snapshotsHave(object $page, string $needle): bool
{
    return (bool) $page->script('[...document.querySelectorAll("[wire\\\\:snapshot]")].some(e => e.getAttribute("wire:snapshot").replace(/[\\s-]/g, "").includes("'.$needle.'"))');
}

it('imports a synthetic sheet end to end and reveals a contact, never leaking phones into snapshots', function (): void {
    $b = rosterHouse('browser-roster');
    $admin = rosterAdmin($b, browserEmail('roster-admin'));
    $phone = syntheticPhone(77);
    $digits = substr($phone, 4);

    $csv = storage_path('framework/testing-roster.csv');
    file_put_contents($csv, rosterCsv([
        ['1', 'Paraugs Pārlūkā', syntheticPhoneTyped(77), 'parlukam@example.test', 'īpašnieks', 'jā', 'nē', ''],
        ['999', 'Nav Dzīvokļa', '', '', '', '', '', ''],
    ], ';', bom: true));

    $this->actingAs($admin);
    steppedUp($admin, 'totp');

    $page = phone('/admin/b/browser-roster/roster/import');
    $page->waitForText(__('import.upload.title'));
    assertNoHorizontalScroll($page, 'import upload 320');

    $page->attach('#import-file', $csv);
    // The upload submits itself when Livewire finishes sending the file (livewire-upload-finish).
    $page->waitForText(__('import.mapping.title'));
    expect(snapshotsHave($page, $digits))->toBeFalse();
    assertNoHorizontalScroll($page, 'import mapping 320');

    $page->click(__('import.mapping.next'))->waitForText(__('import.preview.title'));
    $page->assertSee('Paraugs Pārlūkā')->assertSee(__('import.code.apartment_not_found'))->assertDontSee($digits);
    expect(snapshotsHave($page, $digits))->toBeFalse();

    $page->click(__('import.dry_run.submit'))->waitForText(__('import.dry_run.counts', ['create' => 1, 'update' => 0, 'skip' => 1]));
    $page->check('input[wire\\:model="confirmed.art14"]');
    $page->click(__('import.commit.submit'))->waitForText(__('import.commit.done', ['created' => 1, 'updated' => 0, 'skipped' => 1]));
    $page->assertNoJavaScriptErrors();

    expect(inBuilding($b, fn () => ResidentContact::query()->count()))->toBe(1)
        ->and(inBuilding($b, fn () => ImportRun::query()->sole()->payload_ciphertext))->toBeNull();

    // The list: masked, then "Rādīt kontaktus" → tel: link, still nothing in the snapshots.
    $list = phone('/admin/b/browser-roster/roster');
    $list->waitForText('Paraugs Pārlūkā');
    $list->assertSee('+371 2•••••'.substr($phone, -2))->assertDontSee($digits);
    assertNoHorizontalScroll($list, 'roster list 320');

    $list->click(__('roster.reveal.action'));
    $list->waitForText($phone);
    $list->assertSourceHas('href="tel:'.$phone.'"');
    expect(snapshotsHave($list, $digits))->toBeFalse();
    $list->assertNoJavaScriptErrors();

    @unlink($csv);
});
