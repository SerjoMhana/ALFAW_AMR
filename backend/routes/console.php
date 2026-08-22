<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
 * The nightly backup.
 *
 * It runs outside school hours, never twice at once, and says nothing on a
 * good night — the output is only worth reading when something failed.
 */
Schedule::command('backup:run --quiet-success')
    ->dailyAt(config('backup.time', '01:30'))
    ->withoutOverlapping()
    ->onFailure(fn () => logger()->error('The nightly backup did not complete.'));
