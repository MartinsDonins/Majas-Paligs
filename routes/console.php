<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schedule;

/*
 * Scheduler (runs in the `scheduler` service). Prune commands use the majas_retention connection and run
 * in-process here — never queued to Horizon (MVP-SPEC A.2).
 */
Schedule::command('prune:login-tokens')->hourly()->withoutOverlapping();
Schedule::command('prune:users')->dailyAt('03:20')->withoutOverlapping();
Schedule::command('prune:audit')->weeklyOn(1, '03:40')->withoutOverlapping();
Schedule::command('prune:exports')->hourly()->withoutOverlapping();
