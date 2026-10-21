<?php

declare(strict_types=1);

namespace App\Services\Documents;

use RuntimeException;

/**
 * A document / opening action was refused for a known reason (template_missing, not_draft, not_final, not_sent,
 * lint_failed, controller_first, scope_outside_dpa, scope_core_required, dpia_incomplete, not_agreement, …).
 * `errors` carries the per-block linter list for lint_failed. The message is the translated, person-facing text.
 */
final class DocumentRefused extends RuntimeException
{
    /**
     * @param  list<array{locale: string, block: string, code: string}>  $errors
     */
    public function __construct(public readonly string $reason, public readonly array $errors = [])
    {
        parent::__construct(__('document.error.'.$reason));
    }

    /**
     * Person-facing lines, one per linter error ("LV · Kontaktpersonas: bloks ir tukšs").
     *
     * @return list<string>
     */
    public function lines(): array
    {
        return array_map(static fn (array $e): string => mb_strtoupper($e['locale']).' · '
            .($e['block'] === '-' ? __('document.block.document') : ($e['block'] === 'text' ? __('document.block.text') : __('document.block.named', ['key' => $e['block']])))
            .': '.__('document.lint.'.$e['code']), $this->errors);
    }
}
