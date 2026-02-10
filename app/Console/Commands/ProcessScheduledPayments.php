<?php

namespace App\Console\Commands;

use App\Jobs\ProcessScheduledPayment;
use App\Jobs\ProcessScheduledSinglePayment;
use App\Models\Payment;
use App\Models\PaymentPlan;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Process all scheduled payments that are due.
 *
 * This command should be run daily via the scheduler.
 * It finds all active payment plans with due payments and
 * single scheduled payments, then dispatches jobs to process each one.
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
                            {--payment= : Process a specific single payment by ID}
                            {--type=all : Type of payments to process (all, plans, single)}
                            {--force : Process even if already processed today}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Process all scheduled payments (payment plans and single scheduled payments) that are due';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $dryRun = $this->option('dry-run');
        $specificPlan = $this->option('plan');
        $specificPayment = $this->option('payment');
        $type = $this->option('type');
        $force = $this->option('force');

        $this->info('Processing scheduled payments...');
        $this->newLine();

        $totalProcessed = 0;
        $totalFailed = 0;

        // Process payment plans
        if ($type === 'all' || $type === 'plans') {
            $result = $this->processPaymentPlans($dryRun, $specificPlan, $force);
            $totalProcessed += $result['processed'];
            $totalFailed += $result['failed'];
        }

        // Process single scheduled payments
        if ($type === 'all' || $type === 'single') {
            $result = $this->processSinglePayments($dryRun, $specificPayment, $force);
            $totalProcessed += $result['processed'];
            $totalFailed += $result['failed'];
        }

        $this->newLine();
        $this->info('=== Summary ===');
        $this->info("Total Processed: {$totalProcessed}");
        if ($totalFailed > 0) {
            $this->error("Total Failed: {$totalFailed}");
        }

        return $totalFailed > 0 ? Command::FAILURE : Command::SUCCESS;
    }

    /**
     * Process payment plan scheduled payments.
     */
    protected function processPaymentPlans(bool $dryRun, ?string $specificPlan, bool $force): array
    {
        $this->info('--- Payment Plans ---');

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

            return ['processed' => 0, 'failed' => 0];
        }

        $this->info("Found {$plans->count()} payment plan(s) due for processing:");
        $this->newLine();

        // Create a table for display
        $tableData = [];
        foreach ($plans as $plan) {
            // Determine if this is a retry or fresh payment
            $isRetry = $plan->next_retry_date && $plan->next_retry_date->lte(now());
            $dueDate = $isRetry
                ? $plan->next_retry_date->format('Y-m-d').' (Retry #'.$plan->payments_failed.')'
                : $plan->next_payment_date?->format('Y-m-d') ?? 'N/A';

            $tableData[] = [
                $plan->plan_id,
                $plan->customer->name ?? 'Unknown',
                '$'.number_format($plan->monthly_payment, 2),
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
            $this->warn('DRY RUN - No payment plans will be processed.');

            return ['processed' => 0, 'failed' => 0];
        }

        // Confirm if not forced
        if (! $force && ! $this->confirm('Do you want to process these payment plans?')) {
            $this->info('Cancelled.');

            return ['processed' => 0, 'failed' => 0];
        }

        $processed = 0;
        $failed = 0;

        foreach ($plans as $plan) {
            $this->line("Processing plan {$plan->plan_id}...");

            try {
                // Dispatch the job to process the payment
                ProcessScheduledPayment::dispatch($plan);

                $this->info('  -> Queued for processing');
                $processed++;

                Log::info('Scheduled payment plan job dispatched', [
                    'plan_id' => $plan->plan_id,
                    'amount' => $plan->monthly_payment,
                    'payment_number' => $plan->payments_completed + 1,
                ]);

            } catch (\Exception $e) {
                $this->error("  -> Failed: {$e->getMessage()}");
                $failed++;

                Log::error('Failed to dispatch scheduled payment plan job', [
                    'plan_id' => $plan->plan_id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $this->newLine();
        $this->info("Payment Plans - Processed: {$processed}, Failed: {$failed}");

        return ['processed' => $processed, 'failed' => $failed];
    }

    /**
     * Process single scheduled payments.
     */
    protected function processSinglePayments(bool $dryRun, ?string $specificPayment, bool $force): array
    {
        $this->newLine();
        $this->info('--- Single Scheduled Payments ---');

        // Build the query for due single payments
        $query = Payment::query()
            ->where('status', Payment::STATUS_PENDING)
            ->whereNotNull('scheduled_date')
            ->whereNull('payment_plan_id')  // Not a payment plan payment
            ->whereNull('recurring_payment_id');  // Not a recurring payment

        if ($specificPayment) {
            $query->where('id', $specificPayment);
        } else {
            // Get payments due today or earlier
            $today = now()->toDateString();
            $query->where('scheduled_date', '<=', $today);
        }

        $payments = $query->get();

        if ($payments->isEmpty()) {
            $this->info('No single scheduled payments are due for processing.');

            return ['processed' => 0, 'failed' => 0];
        }

        $this->info("Found {$payments->count()} single scheduled payment(s) due for processing:");
        $this->newLine();

        // Create a table for display
        $tableData = [];
        foreach ($payments as $payment) {
            $metadata = $payment->metadata ?? [];
            $clientName = $metadata['client_name'] ?? 'Unknown';

            $tableData[] = [
                $payment->id,
                $payment->transaction_id,
                $clientName,
                '$'.number_format($payment->amount, 2),
                '$'.number_format($payment->total_amount, 2),
                $payment->scheduled_date?->format('Y-m-d') ?? 'N/A',
                $payment->attempt_count,
            ];
        }

        $this->table(
            ['ID', 'Transaction ID', 'Client', 'Amount', 'Total', 'Scheduled Date', 'Attempts'],
            $tableData
        );

        $this->newLine();

        if ($dryRun) {
            $this->warn('DRY RUN - No single payments will be processed.');

            return ['processed' => 0, 'failed' => 0];
        }

        // Confirm if not forced
        if (! $force && ! $this->confirm('Do you want to process these single payments?')) {
            $this->info('Cancelled.');

            return ['processed' => 0, 'failed' => 0];
        }

        $processed = 0;
        $failed = 0;

        foreach ($payments as $payment) {
            $this->line("Processing payment {$payment->transaction_id}...");

            try {
                // Dispatch the job to process the payment
                ProcessScheduledSinglePayment::dispatch($payment);

                $this->info('  -> Queued for processing');
                $processed++;

                Log::info('Scheduled single payment job dispatched', [
                    'payment_id' => $payment->id,
                    'transaction_id' => $payment->transaction_id,
                    'amount' => $payment->amount,
                    'scheduled_date' => $payment->scheduled_date?->toDateString(),
                ]);

            } catch (\Exception $e) {
                $this->error("  -> Failed: {$e->getMessage()}");
                $failed++;

                Log::error('Failed to dispatch scheduled single payment job', [
                    'payment_id' => $payment->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $this->newLine();
        $this->info("Single Payments - Processed: {$processed}, Failed: {$failed}");

        return ['processed' => $processed, 'failed' => $failed];
    }
}
