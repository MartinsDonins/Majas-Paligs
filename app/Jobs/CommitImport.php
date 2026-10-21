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
 * Commit of a validated import run (DB-003, ROUND2-SPEC §4.6) inside the run's building context. Holds only ids —
 * the CSV stays sealed in import_runs until the job opens it. Idempotent: ImportRunService::runCommit exits for a run
 * that is not `committing` (a second dispatch, a retry after success).
 */
final class CommitImport implements ShouldQueue, TenantAware
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 1;

    public int $timeout = 600;

    public function __construct(
        public readonly string $runId,
        public readonly int $buildingId,
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
