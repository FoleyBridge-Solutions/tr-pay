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

// Process recurring payments daily at 7 AM
Schedule::command('payments:process-recurring')
    ->dailyAt('07:00')
    ->withoutOverlapping()
    ->onOneServer()
    ->emailOutputOnFailure(env('ADMIN_EMAIL'))
    ->appendOutputTo(storage_path('logs/recurring-payments.log'));

// Retry failed recurring payments at 3 PM (second attempt)
Schedule::command('payments:process-recurring')
    ->dailyAt('15:00')
    ->withoutOverlapping()
    ->onOneServer()
    ->appendOutputTo(storage_path('logs/recurring-payments.log'));

// Check ACH payment settlement status daily at 10 PM
// ACH payments take 2-3 business days to settle; this polls Kotapay for updates
Schedule::command('payments:check-ach-status')
    ->dailyAt('22:00')
    ->withoutOverlapping()
    ->onOneServer()
    ->emailOutputOnFailure(env('ADMIN_EMAIL'))
    ->appendOutputTo(storage_path('logs/ach-status-checks.log'));

/*
|--------------------------------------------------------------------------
| Payment Method Management
|--------------------------------------------------------------------------
*/

// Cleanup expired payment methods daily at 2 AM
Schedule::command('payment-methods:cleanup-expired')
    ->dailyAt('02:00')
    ->withoutOverlapping()
    ->onOneServer()
    ->appendOutputTo(storage_path('logs/payment-methods.log'));

// Send expiration notifications daily at 9 AM (30 days before expiration)
Schedule::command('payment-methods:notify-expiring --days=30')
    ->dailyAt('09:00')
    ->withoutOverlapping()
    ->onOneServer()
    ->appendOutputTo(storage_path('logs/payment-methods.log'));

/*
|--------------------------------------------------------------------------
| Database Backups
|--------------------------------------------------------------------------
*/

// Backup SQLite database every hour, keep last 24 backups
Schedule::call(function () {
    $source = database_path('database.sqlite');
    $backupDir = database_path('backups');

    if (! file_exists($source)) {
        return;
    }

    // Create timestamped backup
    $timestamp = now()->format('Y-m-d_H-i-s');
    $backup = "{$backupDir}/database_{$timestamp}.sqlite";
    copy($source, $backup);

    // Keep only last 24 backups
    $backups = glob("{$backupDir}/database_*.sqlite");
    rsort($backups);
    foreach (array_slice($backups, 24) as $old) {
        unlink($old);
    }

    \Illuminate\Support\Facades\Log::info("Database backup created: {$backup}");
})->hourly()->name('database-backup');
