<?php

declare(strict_types=1);

namespace App\Services\Import;

/**
 * Validation result of one row: status (ok / warning / error), codes, the planned action and — in memory only — the
 * normalised values for the commit and the MASKED preview cells. Reports and summaries use number + codes only.
 */
final class RowResult
{
    public const OK = 'ok';

    public const WARNING = 'warning';

    public const ERROR = 'error';

    /** @var list<string> */
    public array $codes = [];

    public string $action = 'create';

    public ?int $apartmentId = null;

    public ?int $apartmentNumber = null;

    public ?int $sectionId = null;

    /** Existing record the row updates / matches (create_or_update, exists_*). */
    public ?string $targetId = null;

    /** @var array<string, mixed> normalised values for ImportHandler::commit() */
    public array $values = [];

    /** @var array<string, string> field key => display text (masked where sensitive) */
    public array $preview = [];

    public function __construct(public readonly int $number) {}

    public function add(string $code): void
    {
        if (! in_array($code, $this->codes, true)) {
            $this->codes[] = $code;
        }
    }

    public function has(string $code): bool
    {
        return in_array($code, $this->codes, true);
    }

    /**
     * @param  array<string, 'error'|'warning'|'skip'>  $severity
     */
    public function finalize(array $severity): void
    {
        foreach ($this->codes as $code) {
            if (($severity[$code] ?? 'error') === 'error') {
                $this->action = 'skip';

                return;
            }
            if (($severity[$code] ?? null) === 'skip') {
                $this->action = 'skip';
            }
        }
    }

    /**
     * @param  array<string, 'error'|'warning'|'skip'>  $severity
     */
    public function status(array $severity): string
    {
        $levels = array_map(static fn (string $c): string => $severity[$c] ?? 'error', $this->codes);

        return match (true) {
            in_array('error', $levels, true) => self::ERROR,
            $levels !== [] => self::WARNING,
            default => self::OK,
        };
    }
}
