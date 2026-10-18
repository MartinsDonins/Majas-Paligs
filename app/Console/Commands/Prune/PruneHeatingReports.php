<?php

declare(strict_types=1);

namespace App\Console\Commands\Prune;

use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * prune:heating-reports — heating reports are kept until the end of the heating season + 1 month
 * (MVP-SPEC B.7 AC 10). Season end = config majas.heating.season_end (month-day, default 05-31). Aggregated
 * projections of past survey rounds stay (counts only). Runs as majas_retention.
 */
#[AsCommand(name: 'prune:heating-reports')]
final class PruneHeatingReports extends Command
{
    protected $signature = 'prune:heating-reports';

    protected $description = 'Delete heating reports from heating seasons that ended more than a month ago.';

    public function handle(): int
    {
        $cutoff = self::cutoff(CarbonImmutable::now('Europe/Riga'));

        $count = DB::connection('pgsql_retention')->delete('DELETE FROM heating_reports WHERE reported_at < ?', [$cutoff->utc()]);
        $this->info("Pruned {$count} heating reports reported before {$cutoff->toDateString()}.");

        return self::SUCCESS;
    }

    /**
     * The most recent season end whose +1 month grace period is over.
     */
    public static function cutoff(CarbonImmutable $now): CarbonImmutable
    {
        [$month, $day] = array_map('intval', explode('-', (string) config('majas.heating.season_end', '05-31')));

        $end = $now->setDate($now->year, $month, $day)->startOfDay();
        if ($end->addMonth()->greaterThan($now)) {
            $end = $end->subYear();
        }

        return $end;
    }
}
