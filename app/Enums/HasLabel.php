<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Backed enums whose labels live in lang/{lv,ru}.json under "enum.<prefix>.<value>" (MVP-SPEC §0).
 */
trait HasLabel
{
    abstract protected static function labelPrefix(): string;

    public function label(?string $locale = null): string
    {
        return __('enum.'.static::labelPrefix().'.'.$this->value, [], $locale);
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $c): string => $c->value, self::cases());
    }

    /**
     * @return array<string, string> value => label (for selects)
     */
    public static function options(): array
    {
        $out = [];
        foreach (self::cases() as $case) {
            $out[$case->value] = $case->label();
        }

        return $out;
    }
}
