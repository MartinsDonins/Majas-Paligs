<?php

declare(strict_types=1);

namespace App\Services\Parking;

/**
 * Outcome of a sighting report as the REPORTER sees it. A recorded report always gets the same neutral
 * "Paldies, ziņojums nodots stāvvietas atbildīgajam" — never registered / not registered (a sighting must not
 * work as a lookup). `photoDropped` = the report was accepted without the photo (daily photo or storage cap).
 */
final readonly class SightingResult
{
    public const SUBMITTED = 'submitted';

    public const INVALID = 'invalid';

    public const LIMIT = 'limit';

    public const RATE_LIMITED = 'rate_limited';

    public const PHOTO_REJECTED = 'photo_rejected';

    public function __construct(
        public string $status,
        public bool $photoDropped = false,
        public ?int $retryAfter = null,
        public ?string $photoReason = null,
    ) {}
}
