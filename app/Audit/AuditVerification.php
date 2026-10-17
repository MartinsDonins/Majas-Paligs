<?php

declare(strict_types=1);

namespace App\Audit;

final readonly class AuditVerification
{
    private function __construct(
        public bool $valid,
        public int $checked,
        public ?int $failedAtId = null,
        public ?string $reason = null,
    ) {}

    public static function ok(int $checked): self
    {
        return new self(true, $checked);
    }

    public static function fail(?int $id, string $reason, int $checked): self
    {
        return new self(false, $checked, $id, $reason);
    }
}
