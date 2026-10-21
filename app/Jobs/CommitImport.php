<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\Import\ImportRunService;
use App\Tenancy\Jobs\RestoreBuildingContext;
use App\Tenancy\Jobs\TenantAware;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Commit one import run (DB-003, ROUND2-SPEC §4.6) inside its building context (TenantAware + RestoreBuildingContext).
 * Idempotent: ImportRunService::runCommit() exits for a run that is not `committing` and resumes from the recorded
 * progress, so a second dispatch never writes a row twice. Carries ids only — never a cell value.
 */
final class CommitImport implements ShouldQueue, TenantAware
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 1;

    public int $timeout = 600;

    public function __construct(
        public readonly int $buildingId,
        public readonly string $runId,
    ) {}

    public function tenantBuildingId(): int
    {
        return $this->buildingId;
    }

    /**
     * @return list<object>
     */
    public function middleware(): array
    {
        return [new RestoreBuildingContext];
    }

    public function handle(ImportRunService $imports): void
    {
        $imports->runCommit($this->runId);
    }
}
