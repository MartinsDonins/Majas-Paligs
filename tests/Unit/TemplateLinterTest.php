<?php

declare(strict_types=1);

use App\Enums\DocumentKind;
use App\Enums\Locale;
use App\Models\DocumentTemplate;
use App\Services\Documents\TemplateLinter;
use App\Services\Documents\TemplateMarkup;

/*
 * Template markers and the linter (ROUND2-SPEC §3.4, AC-R2-09) — in memory, per body and locale.
 */

function lintMaster(): DocumentTemplate
{
    return new DocumentTemplate([
        'kind' => DocumentKind::Dpa,
        'version' => 1,
        'locale' => Locale::Lv,
        'title' => 'Paraugs',
        'body' => "# Paraugs\n\n<!-- req:parties -->\nPārzinis: {controller.name}.\n<!-- /req -->\n\n<!-- edit:notes -->\nNav.\n<!-- /edit -->\n",
        'required_markers' => ['parties'],
    ]);
}

/**
 * @return list<string> "block:code"
 */
function lintCodes(string $body): array
{
    [$errors] = app(TemplateLinter::class)->checkBody($body, lintMaster(), 'lv');

    return array_map(static fn (array $e): string => $e['block'].':'.$e['code'], $errors);
}

it('parses and reassembles the markers losslessly, and writes only editable blocks', function (): void {
    $body = lintMaster()->body;

    expect(TemplateMarkup::assemble(TemplateMarkup::parse($body)))->toBe($body)
        ->and(TemplateMarkup::editable($body))->toBe(['notes' => 'Nav.'])
        ->and(TemplateMarkup::placeholders($body))->toBe(['controller.name']);

    $edited = TemplateMarkup::withEditable($body, ['notes' => 'Jauns teksts', 'parties' => 'UZLAUZTS']);
    expect($edited)->toContain('Jauns teksts')->not->toContain('UZLAUZTS')
        ->and(TemplateMarkup::skeleton($edited))->toBe(TemplateMarkup::skeleton($body))
        ->and(TemplateMarkup::strip($edited))->not->toContain('<!--');
});

it('rejects broken, nested or duplicated markers', function (string $body): void {
    expect(fn () => TemplateMarkup::parse($body))->toThrow(InvalidArgumentException::class);
})->with([
    'unclosed' => ["<!-- edit:a -->\ntext\n"],
    'nested' => ['<!-- edit:a --> <!-- req:b -->x<!-- /req --> <!-- /edit -->'],
    'duplicate' => ['<!-- edit:a -->x<!-- /edit --><!-- edit:a -->y<!-- /edit -->'],
    'stray close' => ['text <!-- /req -->'],
]);

it('passes the untouched master', function (): void {
    expect(lintCodes(lintMaster()->body))->toBe([]);
});

it('finds every rule violation per block', function (string $body, string $expected): void {
    expect(lintCodes($body))->toContain($expected);
})->with(function (): array {
    $master = lintMaster()->body;
    $phone = '2'.str_repeat('1', 3).' '.str_repeat('4', 4);

    return [
        'locked block edited' => [str_replace('Pārzinis: {controller.name}.', 'Pārzinis: kāds cits.', $master), 'parties:locked_changed'],
        'required marker removed' => [str_replace(['<!-- req:parties -->', '<!-- /req -->'], '', $master), 'parties:marker_missing'],
        'text outside markers edited' => [str_replace('# Paraugs', '# Cits virsraksts', $master), 'text:locked_changed'],
        'raw HTML' => [str_replace("Nav.\n", "<b onclick=\"x()\">Nav</b>\n", $master), 'notes:raw_html'],
        'script tag' => [str_replace("Nav.\n", "<script>alert(1)</script>\n", $master), 'notes:raw_html'],
        'non-https link' => [str_replace("Nav.\n", "[saite](http://example.test)\n", $master), 'notes:unsafe_link'],
        'javascript link' => [str_replace("Nav.\n", "[saite](javascript:alert(1))\n", $master), 'notes:unsafe_link'],
        'phone-shaped' => [str_replace("Nav.\n", "Zvaniet {$phone}\n", $master), 'notes:phone_shaped'],
        'plate-shaped' => [str_replace("Nav.\n", "Auto AB-1234 stāv pie durvīm\n", $master), 'notes:plate_shaped'],
        'unknown placeholder' => [str_replace("Nav.\n", "Sveiki {evil.thing}\n", $master), 'notes:unknown_placeholder'],
        'unknown editable block' => [$master.'<!-- edit:extra -->x<!-- /edit -->', 'extra:marker_unknown'],
        'broken markers' => [$master.'<!-- edit:open -->', '-:markers_broken'],
    ];
});

it('allows https links, dates and the known placeholders in editable blocks', function (): void {
    $body = str_replace("Nav.\n", "Skatīt [noteikumus](https://example.test/n) līdz 2026-12-31, rakstiet {controller.contact}.\n", lintMaster()->body);

    expect(lintCodes($body))->toBe([]);
});
