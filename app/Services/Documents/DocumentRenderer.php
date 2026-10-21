<?php

declare(strict_types=1);

namespace App\Services\Documents;

use App\Enums\DocumentKind;
use App\Gdpr\RetentionTable;
use App\Models\Building;
use App\Models\BuildingDocument;
use App\Models\DocumentTemplate;
use Barryvdh\DomPDF\Facade\Pdf;
use League\CommonMark\CommonMarkConverter;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Renders house documents (ROUND2-SPEC §3.4): placeholders filled from the building settings / platform config
 * (values Markdown-escaped, so a controller name can never inject a link or markup), markers removed, Markdown →
 * sanitised HTML (league/commonmark, `html_input: strip`, `allow_unsafe_links: false`), and PDF via dompdf with
 * DejaVu Sans (A4; LV and RU as separate documents).
 *
 * A finalised document renders from its own snapshot (meta.snapshot) — what was signed does not change when the
 * settings change later.
 */
final class DocumentRenderer
{
    /**
     * Placeholder values for a building (strings; Markdown-escaped except the generated lists).
     *
     * @param  list<string>|null  $modules  modules for {dpa.scope_list} / {retention.table} (null = recorded DPA scope)
     * @return array<string, string>
     */
    public function values(Building $building, string $locale, ?array $modules = null): array
    {
        $s = $building->settings;
        $modules ??= $s->dpaScope;
        $pending = __('document.value_pending', locale: $locale);
        $subprocessors = array_values(array_filter(array_map('trim', explode(';', (string) config('majas.processor.subprocessors')))));

        return [
            'controller.name' => $this->escape($s->controller['name'] ?? $pending),
            'controller.contact' => $this->escape($s->controller['contact'] ?? $pending),
            'controller.basis_ref' => $this->escape($s->controller['basis_ref'] ?? $pending),
            'building.name' => $this->escape((string) $building->name),
            'building.address' => $this->escape(trim(implode(', ', array_filter([(string) $building->address_line, (string) $building->city])))),
            'dpa.scope_list' => $modules === [] ? $pending : implode("\n", array_map(
                static fn (string $m): string => '- '.__('enum.module.'.$m, locale: $locale).' — '.__('document.scope_purpose.'.$m, locale: $locale),
                $modules,
            )),
            'dpa.ref' => $this->escape($s->dpaRef ?? $pending),
            'processor.name' => $this->escape((string) config('majas.processor.name', 'CoreDigify')),
            'processor.contact' => $this->escape((string) config('majas.processor.contact', '')),
            'subprocessors.list' => $subprocessors === [] ? __('document.subprocessors_pending', locale: $locale)
                : implode("\n", array_map(fn (string $x): string => '- '.$this->escape($x), $subprocessors)),
            'retention.table' => RetentionTable::markdown($locale, $modules === [] ? null : [...$modules, 'core']),
        ];
    }

    /**
     * Snapshot of the placeholder values stored at finalise (both languages).
     *
     * @param  list<string>|null  $modules
     * @return array{lv: array<string, string>, ru: array<string, string>}
     */
    public function snapshot(Building $building, ?array $modules = null): array
    {
        return ['lv' => $this->values($building, 'lv', $modules), 'ru' => $this->values($building, 'ru', $modules)];
    }

    /**
     * Filled Markdown (markers removed) of a document body.
     *
     * @param  array<string, string>  $values
     */
    public function markdown(string $body, array $values): string
    {
        $text = TemplateMarkup::strip($body);

        return (string) preg_replace_callback(TemplateMarkup::PLACEHOLDER, static fn (array $m): string => $values[$m[1]] ?? '', $text);
    }

    /**
     * Notice Markdown: like markdown(), but a locked block `module-<m>` (the parking / roster sections) is included
     * only when the notice covers module <m> (ROUND2-SPEC §3.5.2).
     *
     * @param  array<string, string>  $values
     * @param  list<string>  $covers
     */
    public function noticeMarkdown(string $body, array $values, array $covers): string
    {
        $segments = array_values(array_filter(
            TemplateMarkup::parse($body),
            static fn (array $s): bool => ! str_starts_with($s['key'], 'module-') || in_array(substr($s['key'], 7), $covers, true),
        ));

        return $this->markdown(TemplateMarkup::assemble($segments), $values);
    }

    /**
     * Sanitised HTML for any Markdown text (also used for building notice overrides).
     */
    public function html(string $markdown): string
    {
        $converter = new CommonMarkConverter([
            'html_input' => 'strip',
            'allow_unsafe_links' => false,
            'max_nesting_level' => 20,
        ]);

        return (string) $converter->convert($markdown);
    }

    /**
     * HTML of a house document in a locale (snapshot values once finalised, live values for a draft).
     */
    public function documentHtml(BuildingDocument $document, Building $building, string $locale): string
    {
        $values = (array) ($document->meta['snapshot'][$locale] ?? $this->values($building, $locale, $this->modulesFor($document)));

        $html = $this->html($document->kind === DocumentKind::PrivacyNotice
            ? $this->noticeMarkdown($document->body($locale), $values, $document->coversModules())
            : $this->markdown($document->body($locale), $values));

        return $document->kind === DocumentKind::DpiaLite ? $html.$this->answersHtml((array) $document->answers, $locale) : $html;
    }

    /**
     * The DPIA-lite answers as an escaped list (typed notes are never interpreted as markup).
     *
     * @param  array<string, mixed>  $answers
     */
    public function answersHtml(array $answers, string $locale): string
    {
        $items = '';
        foreach (DpiaLite::normalise($answers) as $item => $row) {
            $allowed = DpiaLite::ITEMS[$item];
            $answer = $row['answer'] === null ? '—' : (is_array($allowed) ? __('dpia.answer.'.$row['answer'], locale: $locale) : $row['answer']);
            if ($item === 'residual') {
                $answer .= ' · '.__(($row['accepted'] ?? false) ? 'dpia.accepted' : 'dpia.not_accepted', locale: $locale);
            }
            $items .= '<li><strong>'.e(__('dpia.item.'.$item, locale: $locale)).':</strong> '.e($answer)
                .($row['note'] !== '' ? '<br>'.nl2br(e($row['note'])) : '').'</li>';
        }

        return '<h2>'.e(__('dpia.answers', locale: $locale)).'</h2><ol>'.$items.'</ol>';
    }

    /**
     * A4 PDF bytes of one language version (DejaVu Sans covers Latvian diacritics and Cyrillic).
     */
    public function pdfBytes(BuildingDocument $document, Building $building, string $locale): string
    {
        return $this->pdfFromHtml(
            title: __('enum.document_kind.'.$document->kind->value, locale: $locale),
            building: (string) $building->name,
            meta: 'v'.$document->version.' · '.__('enum.document_status.'.$document->status->value, locale: $locale),
            html: $this->documentHtml($document, $building, $locale),
            locale: $locale,
        );
    }

    /**
     * A master rendered for a building without a house copy (the parking decision template — download only).
     */
    public function templatePdfBytes(DocumentTemplate $template, Building $building): string
    {
        $locale = $template->locale->value;

        return $this->pdfFromHtml(
            title: $template->title,
            building: (string) $building->name,
            meta: 'v'.$template->version,
            html: $this->html($this->markdown($template->body, $this->values($building, $locale))),
            locale: $locale,
        );
    }

    /**
     * Download response for a Livewire / Filament action (streamed, so Livewire can hand it to the browser).
     */
    public static function download(string $bytes, string $filename): StreamedResponse
    {
        return response()->streamDownload(static function () use ($bytes): void {
            echo $bytes;
        }, $filename, ['Content-Type' => 'application/pdf']);
    }

    public static function filename(Building $building, string $kind, int $version, string $locale): string
    {
        return sprintf('%s-%s-v%d-%s.pdf', $building->slug, str_replace('_', '-', $kind), $version, $locale);
    }

    private function pdfFromHtml(string $title, string $building, string $meta, string $html, string $locale): string
    {
        return Pdf::loadView('pdf.building-document', [
            'title' => $title,
            'building' => $building,
            'meta' => $meta,
            'html' => $html,
            'disclaimer' => __('document.disclaimer', locale: $locale),
            'locale' => $locale,
        ])->setPaper('a4', 'portrait')->output();
    }

    /**
     * Modules the placeholders talk about: the requested DPA scope, the notice's covered modules, or the recorded scope.
     *
     * @return list<string>|null
     */
    public function modulesFor(BuildingDocument $document): ?array
    {
        $modules = $document->kind->isAgreement() ? $document->requestedScope() : $document->coversModules();

        return $modules === [] ? null : $modules;
    }

    /**
     * Escape Markdown / HTML-significant characters in a typed value.
     */
    private function escape(string $value): string
    {
        $value = str_replace(["\r", "\n"], ' ', $value);

        return (string) preg_replace('/([\\\\`*_{}\[\]()<>#!|~])/', '\\\\$1', $value);
    }
}
