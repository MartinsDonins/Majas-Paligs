<?php

declare(strict_types=1);

namespace App\Services\Import;

/**
 * A target field an ImportHandler accepts (ROUND2-SPEC §4.4): required fields block "Tālāk" until mapped; a `multi`
 * field may take several columns (e.g. "Telefons 1", "Telefons 2") and several values per cell; every other field is
 * mapped at most once. Synonyms are matched after ColumnMapper::normalize (lower-case, unaccented, no punctuation).
 */
final readonly class ImportField
{
    /**
     * @param  list<string>  $synonyms  LV / RU / EN header forms (any spelling; normalised when compared)
     */
    public function __construct(
        public string $key,
        public bool $required = false,
        public bool $multi = false,
        public int $maxLength = 200,
        public array $synonyms = [],
    ) {}

    public function label(): string
    {
        return __('import.field.'.$this->key);
    }
}
