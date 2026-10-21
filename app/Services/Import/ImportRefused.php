<?php

declare(strict_types=1);

namespace App\Services\Import;

use RuntimeException;

/**
 * A pipeline step refused for a reason the person can act on (`import.error.<key>`): file_type, file_too_large,
 * too_many_rows, too_many_columns, cell_too_long, file_empty, gate_closed, forbidden, daily_cap, mapping_required,
 * mapping_duplicate, wrong_status, dry_run_stale, confirm_missing, commit_running, run_expired, unknown_kind.
 * The message is the key only — never a cell value or a file name.
 */
final class ImportRefused extends RuntimeException
{
    /**
     * @param  list<string>  $missing  gate condition labels (gate_closed only)
     */
    public function __construct(public readonly string $key, public readonly array $missing = [])
    {
        parent::__construct('import refused: '.$key);
    }

    public function label(): string
    {
        return __('import.error.'.$this->key);
    }
}
