<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Building;
use App\Services\Roster\ContactRekeyService;
use App\Tenancy\BuildingIterator;
use Illuminate\Console\Command;
use Symfony\Component\Console\Attribute\AsCommand;
use Throwable;

/**
 * contacts:rekey {building?} — move one building (slug) or every building to the next roster key version, one
 * transaction per building (SEC-011). For a master-key rotation set CONTACT_ENC_KEY to the new master and
 * CONTACT_ENC_KEY_PREVIOUS to the old one, run this for all buildings, then remove CONTACT_ENC_KEY_PREVIOUS.
 */
#[AsCommand(name: 'contacts:rekey')]
final class RekeyContacts extends Command
{
    protected $signature = 'contacts:rekey {building? : Building slug (default: all buildings)}';

    protected $description = 'Re-seal roster contacts and recompute their blind indexes with the next key version (per building, atomic).';

    public function handle(ContactRekeyService $rekey): int
    {
        $slug = $this->argument('building');
        $failed = 0;
        $done = 0;

        BuildingIterator::each(function (Building $building) use ($slug, $rekey, &$failed, &$done): void {
            if (is_string($slug) && $slug !== '' && $building->slug !== $slug) {
                return;
            }

            try {
                $r = $rekey->rekey();
                $done++;
                $this->info("{$building->slug}: v{$r['from']} → v{$r['to']} ({$r['contacts']} contacts)");
            } catch (Throwable $e) {
                $failed++;
                $this->error("{$building->slug}: re-key aborted — ".$e::class);
            }
        });

        if ($done === 0 && $failed === 0) {
            $this->warn('No building matched.');
        }

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
