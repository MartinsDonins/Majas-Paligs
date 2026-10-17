<?php

declare(strict_types=1);

namespace App\Console\Commands\Prune;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * prune:users — hard-deletes TOMBSTONES once no audit entry references them any more (actor or subject), and
 * never-confirmed e-mail sign-ups without any membership or role after 7 days. Runs as majas_retention.
 */
#[AsCommand(name: 'prune:users')]
final class PruneUsers extends Command
{
    protected $signature = 'prune:users';

    protected $description = 'Hard-delete unreferenced user tombstones and stale unconfirmed sign-ups.';

    public function handle(): int
    {
        $db = DB::connection('pgsql_retention');

        $tombstones = $db->delete(<<<'SQL'
DELETE FROM users u
 WHERE u.anonymised_at IS NOT NULL
   AND NOT EXISTS (SELECT 1 FROM audit_entries a WHERE a.actor_user_id = u.id OR a.subject_user_ids @> ARRAY[u.id])
   AND NOT EXISTS (SELECT 1 FROM apartment_memberships m WHERE m.user_id = u.id)
   AND NOT EXISTS (SELECT 1 FROM building_roles r WHERE r.user_id = u.id)
SQL);

        $stale = $db->delete(<<<'SQL'
DELETE FROM users u
 WHERE u.anonymised_at IS NULL
   AND u.email IS NOT NULL AND u.email_verified_at IS NULL AND u.login_handle IS NULL AND u.platform_role IS NULL
   AND u.created_at < now() - interval '7 days'
   AND NOT EXISTS (SELECT 1 FROM apartment_memberships m WHERE m.user_id = u.id)
   AND NOT EXISTS (SELECT 1 FROM building_roles r WHERE r.user_id = u.id)
   AND NOT EXISTS (SELECT 1 FROM audit_entries a WHERE a.actor_user_id = u.id OR a.subject_user_ids @> ARRAY[u.id])
SQL);

        $this->info("Pruned {$tombstones} tombstones and {$stale} stale sign-ups.");

        return self::SUCCESS;
    }
}
