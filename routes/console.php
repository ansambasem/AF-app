<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// The database and stored files are backed up every night, and sent off the server when a backup disk is set.
Schedule::command('backup:run')->dailyAt('02:30')->withoutOverlapping()->onFailure(fn () => report(new RuntimeException('The nightly backup failed; see the log.')));
Schedule::command('closings:process')->everyMinute()->withoutOverlapping();
Schedule::command('readings:prepare-distributions')->hourly()->withoutOverlapping();
