<?php

namespace App\Console\Commands;

use App\Notifications\PracticeCsWriteFailed;
use App\Services\AdminAlertService;
use Illuminate\Console\Command;

/**
 * Send a test notification to verify the admin notification system.
 *
 * Usage: php artisan notify:test
 */
class SendTestNotification extends Command
{
    protected $signature = 'notify:test';

    protected $description = 'Send a test notification to all active admin users';

    public function handle(): int
    {
        $this->info('Sending test notification to all active admin users...');

        AdminAlertService::notifyAll(new PracticeCsWriteFailed(
            'TEST-'.now()->format('His'),
            'TEST-CLIENT',
            99.99,
            'This is a test notification — the system is working correctly.',
            'test'
        ));

        $this->info('Test notification sent. Check the dashboard or notification bell.');

        return self::SUCCESS;
    }
}
