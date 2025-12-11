<?php

namespace App\Console\Commands;

use App\Jobs\ProcessScheduledPayment;
use App\Models\PaymentPlan;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Process all scheduled payments that are due.
 * 
 * This command should be run daily via the scheduler.
 * It finds all active payment plans with due payments and
 * dispatches jobs to process each one.
 */
class ProcessScheduledPayments extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'payments:process-scheduled 
                            {--dry-run : Show what would be processed without actually processing}
                            {--plan= : Process a specific plan by plan_id}
                            {--force : Process even if already processed today}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Process all scheduled payment plan payments that are due';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $dryRun = $this->option('dry-run');
        $specificPlan = $this->option('plan');
        $force = $this->option('force');

        $this->info('Processing scheduled payments...');
        $this->newLine();

        // Build the query for due payments (both fresh and retries)
        $query = PaymentPlan::query();

        if ($specificPlan) {
            $query->where('plan_id', $specificPlan);
        } else {
            // Get plans where:
            // 1. Regular payment is due (next_payment_date <= today)
            // 2. OR retry is scheduled (next_retry_date <= today)
            // AND status is active or past_due
            $today = now()->toDateString();
            $query->where(function ($q) use ($today) {
                $q->where('next_payment_date', '<=', $today)
                  ->orWhere('next_retry_date', '<=', $today);
            })
            ->whereIn('status', [PaymentPlan::STATUS_ACTIVE, PaymentPlan::STATUS_PAST_DUE]);
        }

        $plans = $query->get();

        if ($plans->isEmpty()) {
            $this->info('No payment plans are due for processing.');
            return Command::SUCCESS;
        }

        $this->info("Found {$plans->count()} payment plan(s) due for processing:");
        $this->newLine();

        // Create a table for display
        $tableData = [];
        foreach ($plans as $plan) {
            // Determine if this is a retry or fresh payment
            $isRetry = $plan->next_retry_date && $plan->next_retry_date->lte(now());
            $dueDate = $isRetry 
                ? $plan->next_retry_date->format('Y-m-d') . ' (Retry #' . $plan->payments_failed . ')'
                : $plan->next_payment_date?->format('Y-m-d') ?? 'N/A';
            
            $tableData[] = [
                $plan->plan_id,
                $plan->customer->name ?? 'Unknown',
                '$' . number_format($plan->monthly_payment, 2),
                $dueDate,
                "{$plan->payments_completed}/{$plan->duration_months}",
                $plan->status,
            ];
        }

        $this->table(
            ['Plan ID', 'Customer', 'Amount', 'Due Date', 'Progress', 'Status'],
            $tableData
        );

        $this->newLine();

        if ($dryRun) {
            $this->warn('DRY RUN - No payments will be processed.');
            return Command::SUCCESS;
        }

        // Confirm if not forced
        if (!$force && !$this->confirm('Do you want to process these payments?')) {
            $this->info('Cancelled.');
            return Command::SUCCESS;
        }

        $processed = 0;
        $failed = 0;

        foreach ($plans as $plan) {
            $this->line("Processing plan {$plan->plan_id}...");

            try {
                // Dispatch the job to process the payment
                ProcessScheduledPayment::dispatch($plan);
                
                $this->info("  -> Queued for processing");
                $processed++;

                Log::info('Scheduled payment job dispatched', [
                    'plan_id' => $plan->plan_id,
                    'amount' => $plan->monthly_payment,
                    'payment_number' => $plan->payments_completed + 1,
                ]);

            } catch (\Exception $e) {
                $this->error("  -> Failed: {$e->getMessage()}");
                $failed++;

                Log::error('Failed to dispatch scheduled payment job', [
                    'plan_id' => $plan->plan_id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $this->newLine();
        $this->info("Processed: {$processed}");
        if ($failed > 0) {
            $this->error("Failed: {$failed}");
        }

        return $failed > 0 ? Command::FAILURE : Command::SUCCESS;
    }
}
