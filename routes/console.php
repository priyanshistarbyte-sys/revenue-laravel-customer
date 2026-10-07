<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
| GAM report sync (daily Site × Date + hourly Site × Hour reports) on a cron.
| --force: the cron expression sets the cadence, so skip GamSync's own throttle
| (otherwise a run a few seconds short of GAM_MIN_INTERVAL_HOURS gets skipped).
| Needs the server's crontab to call the scheduler every minute — see README.
*/
if (config('adledger.gam.schedule')) {
    Schedule::command('gam:sync --force')
        ->cron(config('adledger.gam.schedule'))
        ->withoutOverlapping(120)
        ->appendOutputTo(storage_path('logs/gam-sync.log'));
}
