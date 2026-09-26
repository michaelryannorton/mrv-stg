<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// withoutOverlapping() is this app's equivalent of the flock()-based overlap protection every
// cron script in the main mrv.org project requires — a second invocation exits immediately
// rather than piling up. See the "Scheduling" section of the Laravel staging setup plan for how
// this gets triggered without adding a second crontab line.
Schedule::command('ingest:ics')->everyThirtyMinutes()->withoutOverlapping();
