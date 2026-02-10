<?php

// app/Console/Commands/NotifyExpiringPaymentMethods.php

namespace App\Console\Commands;

use App\Services\CustomerPaymentMethodService;
use Illuminate\Console\Command;

/**
 * NotifyExpiringPaymentMethods Command
 *
 * Sends email notifications to customers whose credit cards
 * are expiring soon (default: within 30 days).
 *
 * This helps customers update their payment methods before
 * scheduled payments fail.
 *
 * Schedule: Run daily at 9:00 AM
 */
class NotifyExpiringPaymentMethods extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'payment-methods:notify-expiring
                            {--days=30 : Number of days before expiration to notify}
                            {--dry-run : Show what would be notified without sending emails}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Send expiration notifications for cards expiring soon';

    /**
     * Execute the console command.
     */
    public function handle(CustomerPaymentMethodService $service): int
    {
        $days = (int) $this->option('days');

        $this->info("Checking for payment methods expiring within {$days} days...");

        $expiringMethods = $service->getMethodsExpiringSoon($days);

        if ($expiringMethods->isEmpty()) {
            $this->info('No payment methods expiring soon that need notification.');

            return self::SUCCESS;
        }

        $this->info("Found {$expiringMethods->count()} payment method(s) expiring soon.");

        if ($this->option('dry-run')) {
            $this->warn('DRY RUN - No emails will be sent.');

            $this->table(
                ['ID', 'Customer', 'Email', 'Card', 'Expires'],
                $expiringMethods->map(fn ($m) => [
                    $m->id,
                    $m->customer->name ?? 'N/A',
                    $m->customer->email ?? 'N/A',
                    "{$m->brand} •••• {$m->last_four}",
                    $m->expiration_display,
                ])->toArray()
            );

            return self::SUCCESS;
        }

        $bar = $this->output->createProgressBar($expiringMethods->count());
        $bar->start();

        $notifiedCount = $service->sendExpirationNotifications($days);

        $bar->finish();
        $this->newLine(2);

        $this->info("Sent {$notifiedCount} expiration notification(s).");

        return self::SUCCESS;
    }
}
