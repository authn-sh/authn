<?php

use App\Jobs\Maintenance\ExpireSessions;
use App\Jobs\Maintenance\PruneVerificationCodes;
use App\Jobs\Maintenance\ReapAbandonedAttempts;
use App\Jobs\Maintenance\RotateSigningKey;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
|--------------------------------------------------------------------------
| Background maintenance schedule (AU-19)
|--------------------------------------------------------------------------
|
| Each cron uses withoutOverlapping() so two queue workers never duplicate
| work. Cap-per-tick + LIMIT … FOR UPDATE SKIP LOCKED inside each job
| keeps tail latency bounded.
*/

Schedule::job(ReapAbandonedAttempts::class)
    ->everyMinute()
    ->withoutOverlapping();

Schedule::job(ExpireSessions::class)
    ->everyMinute()
    ->withoutOverlapping();

Schedule::job(PruneVerificationCodes::class)
    ->everyFiveMinutes()
    ->withoutOverlapping();

Schedule::job(RotateSigningKey::class)
    ->everyFiveMinutes()
    ->withoutOverlapping();
