<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\ImportRun;
use App\Models\User;
use App\Services\Import\ImportRefused;
use App\Services\Import\ImportRunService;

/**
 * ImportRunPolicy (DB-003): every stage of a kind's import needs the kind's role (ImportHandler::mayImport — roster:
 * building_admin; permits: parking staff) AND the kind's gate (decision D6: roster L-R, permits parking L1).
 */
final class ImportRunPolicy
{
    public function upload(User $user, string $kind): bool
    {
        try {
            app(ImportRunService::class)->assertAllowed($user, $kind);
        } catch (ImportRefused) {
            return false;
        }

        return true;
    }

    public function view(User $user, ImportRun $run): bool
    {
        return $this->upload($user, $run->kind);
    }

    public function commit(User $user, ImportRun $run): bool
    {
        return $this->upload($user, $run->kind);
    }

    public function discard(User $user, ImportRun $run): bool
    {
        return $this->upload($user, $run->kind);
    }
}
