<?php

declare(strict_types=1);

namespace App\Services\Documents;

use App\Enums\Locale;
use App\Models\BuildingDocument;
use App\Models\DocumentTemplate;
use InvalidArgumentException;

/**
 * TemplateLinter::check(document) (ROUND2-SPEC §3.4, AC-R2-09): before a house document can be finalised or published
 * as a notice, for LV and RU:
 * - every required marker of the master is present and its locked text unchanged; text outside markers unchanged;
 * - no unknown placeholders;
 * - every editable block non-empty in both languages (and the same blocks in both);
 * - no raw HTML (Markdown subset only), links to https only;
 * - no phone-shaped or plate-shaped strings outside placeholders (bodies hold no personal data).
 * Returns a per-block error list; empty = clean. Codes, never the offending text.
 */
final class TemplateLinter
{
    public const PLACEHOLDERS = [
        'controller.name', 'controller.contact', 'controller.basis_ref', 'building.name', 'building.address',
        'dpa.scope_list', 'dpa.ref', 'processor.name', 'processor.contact', 'subprocessors.list', 'retention.table',
    ];

    /** 8+ digits, optionally grouped by spaces, optional leading + (a phone number; ISO dates with "-" are not). */
    private const PHONE = '/(?<![\w.,\/-])\+?\d(?: ?\d){7,}(?![\w-])/u';

    /** Latvian-style registration plates: "AB-1234" or "AB1234". */
    private const PLATE = '/\b[A-ZĀČĒĢĪĶĻŅŠŪŽ]{1,3}-\d{1,4}\b|\b[A-Z]{2}\d{4}\b/u';

    /** Any HTML tag or comment that is not one of our markers. */
    private const HTML = '/<\s*\/?\s*[a-zA-Z][^>]*>|<!--(?!\s*\/?(?:req|edit)\b)/';

    /**
     * @return list<array{locale: string, block: string, code: string}>
     */
    public function check(BuildingDocument $document): array
    {
        $errors = [];
        $editable = [];

        foreach (Locale::cases() as $locale) {
            $master = $this->master($document, $locale);
            $body = $document->body($locale->value);

            if ($master === null) {
                $errors[] = ['locale' => $locale->value, 'block' => '-', 'code' => 'template_missing'];

                continue;
            }

            [$localeErrors, $blocks] = $this->checkBody($body, $master, $locale->value);
            $errors = [...$errors, ...$localeErrors];
            $editable[$locale->value] = $blocks;
        }

        if (isset($editable['lv'], $editable['ru'])) {
            foreach (array_unique([...array_keys($editable['lv']), ...array_keys($editable['ru'])]) as $key) {
                foreach (['lv', 'ru'] as $loc) {
                    if (trim((string) ($editable[$loc][$key] ?? '')) === '') {
                        $errors[] = ['locale' => $loc, 'block' => $key, 'code' => 'editable_empty'];
                    }
                }
            }
        }

        return $errors;
    }

    /**
     * @return array{0: list<array{locale: string, block: string, code: string}>, 1: array<string, string>}
     */
    public function checkBody(string $body, DocumentTemplate $master, string $locale): array
    {
        $errors = [];
        $err = static function (string $block, string $code) use (&$errors, $locale): void {
            $errors[] = ['locale' => $locale, 'block' => $block, 'code' => $code];
        };

        try {
            $segments = TemplateMarkup::parse($body);
            $masterSegments = TemplateMarkup::parse($master->body);
        } catch (InvalidArgumentException) {
            $err('-', 'markers_broken');

            return [$errors, []];
        }

        $req = $this->blocks($segments, 'req');
        $masterReq = $this->blocks($masterSegments, 'req');

        foreach ($master->required_markers !== [] ? $master->required_markers : array_keys($masterReq) as $key) {
            if (! array_key_exists($key, $req)) {
                $err($key, 'marker_missing');
            } elseif ($this->norm($req[$key]) !== $this->norm($masterReq[$key] ?? '')) {
                $err($key, 'locked_changed');
            }
        }

        // Text outside the markers is locked too (compared position by position).
        $text = array_values(array_map(fn (array $s): string => $this->norm($s['text']), array_filter($segments, static fn (array $s): bool => $s['type'] === 'text')));
        $masterText = array_values(array_map(fn (array $s): string => $this->norm($s['text']), array_filter($masterSegments, static fn (array $s): bool => $s['type'] === 'text')));
        if ($text !== $masterText) {
            $err('text', 'locked_changed');
        }

        $editable = $this->blocks($segments, 'edit');
        $masterEditable = $this->blocks($masterSegments, 'edit');
        foreach (array_diff(array_keys($masterEditable), array_keys($editable)) as $key) {
            $err($key, 'marker_missing');
        }
        foreach (array_diff(array_keys($editable), array_keys($masterEditable)) as $key) {
            $err($key, 'marker_unknown');
        }

        foreach ($segments as $s) {
            $block = $s['type'] === 'text' ? 'text' : $s['key'];

            foreach (TemplateMarkup::placeholders($s['text']) as $placeholder) {
                if (! in_array($placeholder, self::PLACEHOLDERS, true)) {
                    $err($block, 'unknown_placeholder');
                }
            }

            if ($s['type'] !== 'edit') {
                continue;
            }

            $content = $s['text'];
            if (mb_strlen($content) > DocumentService::MAX_BLOCK_CHARS) {
                $err($block, 'too_long');
            }
            if (preg_match(self::HTML, $content) === 1) {
                $err($block, 'raw_html');
            }
            if ($this->hasUnsafeLink($content)) {
                $err($block, 'unsafe_link');
            }
            $plain = (string) preg_replace(TemplateMarkup::PLACEHOLDER, ' ', $content);
            if (preg_match(self::PHONE, $plain) === 1) {
                $err($block, 'phone_shaped');
            }
            if (preg_match(self::PLATE, $plain) === 1) {
                $err($block, 'plate_shaped');
            }
        }

        return [$errors, array_map(static fn (string $t): string => trim($t), $editable)];
    }

    private function master(BuildingDocument $document, Locale $locale): ?DocumentTemplate
    {
        return DocumentTemplate::query()->where('kind', $document->kind->value)->where('version', $document->template_version)
            ->where('locale', $locale->value)->first();
    }

    /**
     * @param  list<array{type: string, key: string, text: string}>  $segments
     * @return array<string, string>
     */
    private function blocks(array $segments, string $type): array
    {
        $out = [];
        foreach ($segments as $s) {
            if ($s['type'] === $type) {
                $out[$s['key']] = $s['text'];
            }
        }

        return $out;
    }

    private function norm(string $text): string
    {
        return trim((string) preg_replace('/[ \t]+\n/', "\n", str_replace("\r\n", "\n", $text)));
    }

    /**
     * Markdown links / autolinks / bare schemes that are not https.
     */
    private function hasUnsafeLink(string $text): bool
    {
        if (preg_match_all('/\]\(\s*<?([^)\s>]*)/', $text, $m) > 0) {
            foreach ($m[1] as $url) {
                if (! str_starts_with(strtolower($url), 'https://')) {
                    return true;
                }
            }
        }

        if (preg_match('/^\s*\[[^\]]+\]:\s*(?!https:\/\/)\S+/mi', $text) === 1) {
            return true;
        }

        return preg_match('/\b(?:javascript|data|vbscript|file|http):/i', $text) === 1;
    }
}
