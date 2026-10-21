<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\ImportKind;
use App\Models\ImportRun;
use App\Models\User;
use App\Services\Import\ImportFailed;
use App\Services\Import\ImportRunService;

/**
 * ImportRunPolicy (DB-003): every ability follows the kind's handler (permits: building_admin / parking_officer at
 * parking L1; roster: building_admin at L-R) — the gate is part of the answer (D6), so a run cannot even be looked at
 * while its gate is closed. Runs are never deleted by the app (prune:imports).
 */
final class ImportRunPolicy
{
    public function create(User $user, ImportKind $kind): bool
    {
        return $this->allowed($user, $kind);
    }

    public function view(User $user, ImportRun $run): bool
    {
        return $this->allowed($user, $run->kind);
    }

    public function update(User $user, ImportRun $run): bool
    {
        return $this->allowed($user, $run->kind);
    }

    public function commit(User $user, ImportRun $run): bool
    {
        return $this->allowed($user, $run->kind);
    }

    public function delete(User $user, ImportRun $run): bool
    {
        return false;
    }

    private function allowed(User $user, ImportKind $kind): bool
    {
        if ($user->isTombstone()) {
            return false;
        }

        try {
            $handler = app(ImportRunService::class)->handler($kind);
        } catch (ImportFailed) {
            return false;
        }

        return $handler->gateOpen() && $handler->canImport($user);
    }
}
