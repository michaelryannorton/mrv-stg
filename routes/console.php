<?php

use App\Http\Controllers\StatusHealthController;
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
//
// The two onSuccess/onFailure lines send the Healthchecks.io heartbeat (URL in STATUS_HEALTH_HEARTBEAT_INGEST_ICS; nothing is
// sent while it is empty). The controller's wrapper is used, not pingOnSuccess(): the framework's own ping waits up to 30 seconds
// and catches only some exceptions. A success ping means the command ran; the command exits 0 even when every feed failed, so
// ingest_ics_age and sources_failing in the health report are what show the feeds working.
Schedule::command('ingest:ics')->everyThirtyMinutes()->withoutOverlapping()
    ->onSuccess(StatusHealthController::heartbeat('ingest_ics'))
    ->onFailure(StatusHealthController::heartbeat('ingest_ics', failed: true));

// The status dashboard's scheduler marker: the health endpoint reports how long ago this last ran.
Schedule::call(fn () => StatusHealthController::recordSchedulerRun())
    ->everyMinute()
    ->name('status-health-scheduler');
