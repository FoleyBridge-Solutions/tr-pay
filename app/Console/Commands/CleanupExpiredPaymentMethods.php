<?php

// app/Console/Commands/CleanupExpiredPaymentMethods.php

namespace App\Console\Commands;

use App\Services\CustomerPaymentMethodService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * CleanupExpiredPaymentMethods Command
 *
 * Automatically removes expired credit cards from the system.
 * Expired cards cannot be charged, so they should be cleaned up.
 *
 * This command:
 * 1. Finds all cards past their expiration date
 * 2. Deletes tokens from MiPaymentChoice gateway
 * 3. Removes records from local database
 * 4. Sends deletion notification emails
 *
 * Schedule: Run daily at 2:00 AM
 */
class CleanupExpiredPaymentMethods extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'payment-methods:cleanup-expired
                            {--dry-run : Show what would be deleted without actually deleting}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Remove expired credit cards from the system';

    /**
     * Execute the console command.
     */
    public function handle(CustomerPaymentMethodService $service): int
    {
        $this->info('Checking for expired payment methods...');

        $expiredMethods = $service->getExpiredMethods();

        if ($expiredMethods->isEmpty()) {
            $this->info('No expired payment methods found.');

            return self::SUCCESS;
        }

        $this->info("Found {$expiredMethods->count()} expired payment method(s).");

        if ($this->option('dry-run')) {
            $this->warn('DRY RUN - No changes will be made.');

            $this->table(
                ['ID', 'Customer', 'Type', 'Last Four', 'Expired'],
                $expiredMethods->map(fn ($m) => [
                    $m->id,
                    $m->customer->name ?? 'N/A',
                    $m->type,
                    $m->last_four,
                    $m->expiration_display,
                ])->toArray()
            );

            return self::SUCCESS;
        }

        $bar = $this->output->createProgressBar($expiredMethods->count());
        $bar->start();

        $deletedCount = 0;
        $skippedCount = 0;
        $errorCount = 0;

        foreach ($expiredMethods as $method) {
            try {
                // Skip expired cards that are linked to active recurring payments or payment plans
                if ($method->isLinkedToActivePlans()) {
                    $linkedInfo = $method->getLinkedPlansInfo();
                    $this->newLine();
                    $this->warn("Skipping method {$method->id} ({$method->display_name}) — linked to {$linkedInfo['total_count']} active plan(s)/recurring payment(s). Card needs to be updated.");
                    Log::warning('Skipping expired payment method deletion — linked to active plans', [
                        'payment_method_id' => $method->id,
                        'customer_id' => $method->customer_id,
                        'display_name' => $method->display_name,
                        'linked_payment_plans' => $linkedInfo['payment_plans']->count(),
                        'linked_recurring_payments' => $linkedInfo['recurring_payments']->count(),
                    ]);
                    $skippedCount++;
                    $bar->advance();

                    continue;
                }

                $service->delete($method, true); // Force delete (safe — no active links)
                $deletedCount++;
            } catch (\Exception $e) {
                $this->newLine();
                $this->error("Failed to delete method {$method->id}: {$e->getMessage()}");
                $errorCount++;
            }

            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);

        $this->info("Deleted {$deletedCount} expired payment method(s).");

        if ($skippedCount > 0) {
            $this->warn("Skipped {$skippedCount} expired method(s) linked to active plans — these need updated payment methods.");
        }

        if ($errorCount > 0) {
            $this->warn("Failed to delete {$errorCount} payment method(s). Check logs for details.");

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
