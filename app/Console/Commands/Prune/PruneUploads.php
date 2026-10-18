<?php

declare(strict_types=1);

namespace App\Console\Commands\Prune;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * prune:uploads — Livewire temporary uploads (sighting photos before processing) live on the private disk and are
 * purged hourly (MVP-SPEC C.5): anything older than one hour goes. No database access.
 */
#[AsCommand(name: 'prune:uploads')]
final class PruneUploads extends Command
{
    protected $signature = 'prune:uploads';

    protected $description = 'Delete Livewire temporary uploads older than one hour.';

    public function handle(): int
    {
        $disk = Storage::disk((string) (config('livewire.temporary_file_upload.disk') ?? 'local'));
        $dir = (string) (config('livewire.temporary_file_upload.directory') ?? 'livewire-tmp');
        $cutoff = now()->subHour()->getTimestamp();
        $count = 0;

        foreach ($disk->files($dir) as $file) {
            if ($disk->lastModified($file) < $cutoff) {
                $disk->delete($file);
                $count++;
            }
        }

        $this->info("Deleted {$count} temporary uploads.");

        return self::SUCCESS;
    }
}
