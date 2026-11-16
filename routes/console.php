<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
| Events still "received" or "processing" well after they arrived usually mean the
| queue was down or a worker died mid-job. Re-dispatching is safe: handlers are idempotent.
*/
Schedule::command('webhooks:replay --stuck')->everyFiveMinutes()->withoutOverlapping();
