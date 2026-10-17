<?php

declare(strict_types=1);

namespace App\Console\Commands\Prune;

use App\Enums\ExportStatus;
use App\Models\DataExport;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * prune:exports — delete expired export files (user GDPR exports and building exports) and mark them expired.
 * Files only (rows are kept as a small history); uses the app connection for the status update of the user's
 * own rows — no personal data is deleted from tables here.
 */
#[AsCommand(name: 'prune:exports')]
final class PruneExports extends Command
{
    protected $signature = 'prune:exports';

    protected $description = 'Delete expired export ZIP files.';

    public function handle(): int
    {
        $disk = Storage::disk((string) config('majas.exports.disk', 'local'));
        $count = 0;

        foreach (DataExport::query()->where('status', ExportStatus::Ready->value)->where('expires_at', '<', now())->get() as $export) {
            if ($export->path !== null) {
                $disk->delete($export->path);
            }
            $export->forceFill(['status' => ExportStatus::Expired, 'path' => null])->save();
            $count++;
        }

        // Building exports live under exports/buildings/; files older than the keep window are removed.
        foreach ($disk->files('exports/buildings') as $file) {
            if ($disk->lastModified($file) < now()->subHours((int) config('majas.exports.keep_hours', 24))->getTimestamp()) {
                $disk->delete($file);
                $count++;
            }
        }

        $this->info("Expired {$count} export files.");

        return self::SUCCESS;
    }
}
