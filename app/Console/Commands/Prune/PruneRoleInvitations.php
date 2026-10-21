<?php

declare(strict_types=1);

namespace App\Console\Commands\Prune;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * prune:role-invitations — team invitations (encrypted e-mail + HMAC) are deleted 30 days after they were accepted,
 * revoked or expired (ROUND2-SPEC §2.1). The audit chain keeps the history without the e-mail. Runs as majas_retention.
 */
#[AsCommand(name: 'prune:role-invitations')]
final class PruneRoleInvitations extends Command
{
    protected $signature = 'prune:role-invitations';

    protected $description = 'Delete team invitations 30 days after they were accepted, revoked or expired.';

    public function handle(): int
    {
        $count = DB::connection('pgsql_retention')->delete(<<<'SQL'
            DELETE FROM role_invitations
            WHERE coalesce(accepted_at, revoked_at, expires_at) < now() - interval '30 days'
            SQL);

        $this->info("Pruned {$count} team invitations.");

        return self::SUCCESS;
    }
}
