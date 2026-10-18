<?php

declare(strict_types=1);

namespace App\Console\Commands\Prune;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * prune:sightings — sighting photos are deleted on day 30 (file removed, photo_path cleared, photo_deleted_at set);
 * sighting records after 12 months (PLANNING §5, MVP-SPEC AC 7). Runs as majas_retention.
 */
#[AsCommand(name: 'prune:sightings')]
final class PruneSightings extends Command
{
    protected $signature = 'prune:sightings';

    protected $description = 'Delete sighting photos after 30 days and sighting records after 12 months.';

    public function handle(): int
    {
        $db = DB::connection('pgsql_retention');
        $disk = Storage::disk((string) config('majas.parking.photo_disk', 'local'));

        $photos = $db->table('sightings')
            ->whereNotNull('photo_path')
            ->whereNull('photo_deleted_at')
            ->whereRaw("created_at < now() - interval '30 days'")
            ->get(['id', 'photo_path']);

        foreach ($photos as $row) {
            $disk->delete((string) $row->photo_path);
            $db->table('sightings')->where('id', $row->id)->update(['photo_path' => null, 'photo_deleted_at' => now()]);
        }

        $old = $db->table('sightings')->whereRaw("created_at < now() - interval '12 months'")->whereNotNull('photo_path')->pluck('photo_path');
        foreach ($old as $path) {
            $disk->delete((string) $path);
        }
        $records = $db->delete("DELETE FROM sightings WHERE created_at < now() - interval '12 months'");

        $this->info("Deleted {$photos->count()} sighting photos and {$records} sighting records.");

        return self::SUCCESS;
    }
}
