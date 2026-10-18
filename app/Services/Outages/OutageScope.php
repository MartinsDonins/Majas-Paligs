<?php

declare(strict_types=1);

namespace App\Services\Outages;

use InvalidArgumentException;

/**
 * OutageScopeResolver result. With exactly one riser of the kind (or none) there is nothing to ask; with several
 * the resident answers ONE question — "Virtuvē / Vannas istabā / Visur" — and choose() maps the answer to targets.
 */
final readonly class OutageScope
{
    public const ALL = 'all';

    /**
     * @param  list<ScopeTarget>  $targets  targets when no question is needed
     * @param  list<array{key: string, label: string, target: ScopeTarget}>  $options  answers when a question is needed
     */
    public function __construct(
        public array $targets,
        public array $options = [],
    ) {}

    public function needsChoice(): bool
    {
        return $this->options !== [];
    }

    /**
     * @return list<ScopeTarget>
     */
    public function choose(?string $answer): array
    {
        if (! $this->needsChoice()) {
            return $this->targets;
        }

        if ($answer === self::ALL) {
            return array_values(array_map(static fn (array $o): ScopeTarget => $o['target'], $this->options));
        }

        foreach ($this->options as $option) {
            if ($option['key'] === $answer) {
                return [$option['target']];
            }
        }

        throw new InvalidArgumentException('Unknown outage scope answer.');
    }
}
