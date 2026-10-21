<?php

declare(strict_types=1);

namespace App\Services\Import;

use RuntimeException;

/**
 * A refused import step with a translatable reason key (`admin.import.error.<key>`). The message carries the key
 * only — never a cell value, a file name or a plate (ROUND2-SPEC §4.6, AC-R2-19).
 */
final class ImportFailed extends RuntimeException
{
    public function __construct(public readonly string $key)
    {
        parent::__construct('Import refused: '.$key);
    }

    public function label(): string
    {
        return __('admin.import.error.'.$this->key);
    }
}
