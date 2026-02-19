<?php

namespace App\Console\Commands;

use App\Models\Customer;
use App\Repositories\PaymentRepository;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Sync client email addresses from PracticeCS into local Customer records.
 *
 * Queries PracticeCS Contact_Email table to find primary email addresses
 * for each client, then updates the corresponding Customer.email field
 * in the local SQLite database.
 *
 * This command is idempotent — it can be run multiple times safely.
 * Existing email values will be overwritten with the latest from PracticeCS.
 */
class SyncClientEmails extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'sync:client-emails
                            {--dry-run : Preview changes without applying them}
                            {--client-id= : Sync a single client by client_id}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Sync client email addresses from PracticeCS into local Customer records';

    /**
     * Execute the console command.
     */
    public function handle(PaymentRepository $repo): int
    {
        $dryRun = $this->option('dry-run');
        $singleClientId = $this->option('client-id');

        if ($dryRun) {
            $this->info('DRY RUN — no changes will be made.');
        }

        // Load customers to sync
        $query = Customer::whereNotNull('client_id')->where('client_id', '!=', '');

        if ($singleClientId) {
            $query->where('client_id', $singleClientId);
        }

        $customers = $query->get();

        if ($customers->isEmpty()) {
            $this->warn('No customers found to sync.');

            return self::SUCCESS;
        }

        $this->info("Found {$customers->count()} customer(s) to sync.");

        // Batch fetch emails from PracticeCS for efficiency
        $clientIds = $customers->pluck('client_id')->unique()->values()->all();

        $this->info('Fetching emails from PracticeCS...');

        // Process in chunks of 500 to avoid SQL parameter limits
        $emailMap = [];
        foreach (array_chunk($clientIds, 500) as $chunk) {
            $emailMap = array_merge($emailMap, $repo->getClientEmailsBatch($chunk));
        }

        $this->info('Found emails for '.count($emailMap).' of '.count($clientIds).' clients.');

        // Apply updates
        $stats = ['updated' => 0, 'unchanged' => 0, 'no_email' => 0, 'errors' => 0];

        $bar = $this->output->createProgressBar($customers->count());
        $bar->start();

        foreach ($customers as $customer) {
            $email = $emailMap[$customer->client_id] ?? null;

            if (! $email) {
                $stats['no_email']++;
                $bar->advance();

                continue;
            }

            // Normalize email for comparison
            $email = strtolower(trim($email));

            if ($customer->email === $email) {
                $stats['unchanged']++;
                $bar->advance();

                continue;
            }

            if ($dryRun) {
                $oldEmail = $customer->email ?? '(none)';
                $this->newLine();
                $this->line("  Customer #{$customer->id} ({$customer->client_id}): {$oldEmail} => {$email}");
            } else {
                try {
                    $customer->email = $email;
                    $customer->save();
                } catch (\Exception $e) {
                    $stats['errors']++;
                    $this->newLine();
                    $this->error("  Failed to update Customer #{$customer->id}: {$e->getMessage()}");
                    $bar->advance();

                    continue;
                }
            }

            $stats['updated']++;
            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);

        // Summary
        $action = $dryRun ? 'Would update' : 'Updated';
        $this->info('=== Summary ===');
        $this->table(
            ['Metric', 'Count'],
            [
                [$action, $stats['updated']],
                ['Already up to date', $stats['unchanged']],
                ['No email in PracticeCS', $stats['no_email']],
                ['Errors', $stats['errors']],
                ['Total processed', $customers->count()],
            ]
        );

        if ($dryRun) {
            $this->warn('DRY RUN complete — no changes were made. Run without --dry-run to apply.');
        } else {
            Log::info('SyncClientEmails completed', $stats);
            $this->info("Done! {$action} {$stats['updated']} customer email(s).");
        }

        return self::SUCCESS;
    }
}
