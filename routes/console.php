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
Schedule::command('prune:permit-observations')->dailyAt('04:45')->withoutOverlapping();
Schedule::command('prune:uploads')->hourly()->withoutOverlapping();
Schedule::command('parking:maintain')->hourly()->withoutOverlapping();

// Round 2 — team invitations (ROUND2-SPEC §2.1).
Schedule::command('prune:role-invitations')->dailyAt('04:50')->withoutOverlapping();
// Round 2 — CSV import staging (24 h) and the resident roster (moved out + 30 d), majas_retention.
Schedule::command('prune:imports')->hourly()->withoutOverlapping();
Schedule::command('prune:roster')->dailyAt('04:55')->withoutOverlapping();
// Round 3 — operator error log (app_error_events, 30 days after last seen), majas_retention.
Schedule::command('prune:error-events')->dailyAt('05:05')->withoutOverlapping();
