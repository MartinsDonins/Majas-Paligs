<?php

declare(strict_types=1);

namespace App\Services\Import;

/**
 * Validation result of one CSV record (ROUND2-SPEC §4.5): status ok | warning (imported) | error (skipped), the
 * action the commit would take (create | update | skip), the codes (never values) and the normalised values for the
 * commit. `values` must never reach logs, audit data, Livewire public properties or exception messages — the preview
 * renders masked cells through ImportHandler::mask().
 */
final class RowResult
{
    public const OK = 'ok';

    public const WARNING = 'warning';

    public const ERROR = 'error';

    /** @var list<string> */
    public array $codes = [];

    /** @var list<string> */
    private array $errorCodes = [];

    /**
     * @param  array<string, mixed>  $values  normalised values keyed by target
     */
    public function __construct(
        public readonly int $line,
        public array $values = [],
        public string $action = 'create',
    ) {}

    public function warn(string $code): self
    {
        $this->codes[] = $code;

        return $this;
    }

    public function fail(string $code): self
    {
        $this->codes[] = $code;
        $this->errorCodes[] = $code;

        return $this;
    }

    public function status(): string
    {
        if ($this->errorCodes !== []) {
            return self::ERROR;
        }

        return $this->codes === [] ? self::OK : self::WARNING;
    }

    public function failed(): bool
    {
        return $this->errorCodes !== [];
    }

    /**
     * What the commit does with this record: errors and `exists_skip` rows are skipped.
     */
    public function effectiveAction(): string
    {
        return $this->failed() ? 'skip' : $this->action;
    }

    /**
     * @return list<string>
     */
    public function uniqueCodes(): array
    {
        return array_values(array_unique($this->codes));
    }
}
