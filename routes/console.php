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
Schedule::command('prune:heating-reports')->dailyAt('04:10')->withoutOverlapping();
Schedule::command('heating:recompute')->hourly()->withoutOverlapping();

// Package C — parking retention (majas_retention) and maintenance (majas_app).
Schedule::command('prune:sightings')->dailyAt('04:20')->withoutOverlapping();
Schedule::command('prune:plate-lookups')->dailyAt('04:30')->withoutOverlapping();
Schedule::command('prune:vehicles')->dailyAt('04:40')->withoutOverlapping();
Schedule::command('prune:uploads')->hourly()->withoutOverlapping();
Schedule::command('parking:maintain')->hourly()->withoutOverlapping();

// Round 2 — CSV import staging (24 h) and the resident roster (moved out + 30 d), majas_retention.
Schedule::command('prune:imports')->hourly()->withoutOverlapping();
Schedule::command('prune:roster')->dailyAt('04:50')->withoutOverlapping();
