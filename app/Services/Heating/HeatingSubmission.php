<?php

declare(strict_types=1);

namespace App\Services\Heating;

/**
 * Result of a heating report submission shown on the thank-you screen (counts only).
 */
final readonly class HeatingSubmission
{
    public function __construct(
        public string $id,
        public ?int $roundId,
        public int $reports,
        public int $apartmentsOnMyRiser,
    ) {}
}
