<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

/*
|--------------------------------------------------------------------------
| Console Routes
|--------------------------------------------------------------------------
|
| This file is where you may define all of your Closure based console
| commands. Each Closure is bound to a command instance allowing a
| simple approach to interacting with each command's IO methods.
|
*/

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
|--------------------------------------------------------------------------
| Scheduled Tasks
|--------------------------------------------------------------------------
|
| Define scheduled tasks that run automatically via cron.
| Make sure to add the Laravel scheduler to your crontab:
|
| * * * * * cd /var/www/tr-pay && php artisan schedule:run >> /dev/null 2>&1
|
*/

// Process scheduled payment plan payments daily at 6 AM
Schedule::command('payments:process-scheduled --force')
    ->dailyAt('06:00')
    ->withoutOverlapping()
    ->onOneServer()
    ->emailOutputOnFailure(env('ADMIN_EMAIL'))
    ->appendOutputTo(storage_path('logs/scheduled-payments.log'));

// Retry any past-due payments at 2 PM (second attempt for same-day failures)
Schedule::command('payments:process-scheduled --force')
    ->dailyAt('14:00')
    ->withoutOverlapping()
    ->onOneServer()
    ->appendOutputTo(storage_path('logs/scheduled-payments.log'));
