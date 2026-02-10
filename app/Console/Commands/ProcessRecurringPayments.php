<?php

namespace App\Console\Commands;

use App\Models\RecurringPayment;
use App\Notifications\RecurringPaymentFailed;
use App\Services\PaymentService;
use App\Support\AdminNotifiable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Process all recurring payments that are due.
 *
 * This command should be run daily via the scheduler.
 * It finds all active recurring payments with due dates and processes them.
 */
class ProcessRecurringPayments extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'payments:process-recurring
                            {--dry-run : Show what would be processed without actually processing}
                            {--id= : Process a specific recurring payment by ID}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Process all recurring payments that are due';

    /**
     * Execute the console command.
     */
    public function handle(PaymentService $paymentService): int
    {
        $dryRun = $this->option('dry-run');
        $specificId = $this->option('id');

        $this->info('Processing recurring payments...');
        $this->newLine();

        // Build the query for due recurring payments
        $query = RecurringPayment::query()
            ->where('status', RecurringPayment::STATUS_ACTIVE)
            ->whereNotNull('next_payment_date')
            ->where('next_payment_date', '<=', now()->toDateString());

        if ($specificId) {
            $query->where('id', $specificId);
        }

        $duePayments = $query->get();

        if ($duePayments->isEmpty()) {
            $this->info('No recurring payments are due for processing.');

            return Command::SUCCESS;
        }

        $this->info("Found {$duePayments->count()} recurring payment(s) due for processing.");
        $this->newLine();

        $processed = 0;
        $failed = 0;

        foreach ($duePayments as $recurringPayment) {
            $this->line("Processing: {$recurringPayment->client_name} - \${$recurringPayment->amount} ({$recurringPayment->frequency_label})");

            if ($dryRun) {
                $this->info('  [DRY RUN] Would process payment');

                continue;
            }

            // Defensive check: skip payments without payment method configured
            // This can happen if a 'pending' record was manually changed to 'active'
            // without adding payment details
            if (empty($recurringPayment->payment_method_token) || empty($recurringPayment->payment_method_type)) {
                $this->warn('  [SKIP] No payment method configured - record should be "pending" status');

                Log::warning('Recurring payment skipped: missing payment method', [
                    'recurring_payment_id' => $recurringPayment->id,
                    'client_name' => $recurringPayment->client_name,
                    'status' => $recurringPayment->status,
                    'payment_method_type' => $recurringPayment->payment_method_type,
                ]);

                // Update status back to pending since it shouldn't be active
                $recurringPayment->status = RecurringPayment::STATUS_PENDING;
                $recurringPayment->save();

                $failed++;

                continue;
            }

            try {
                // Decrypt the payment method token
                $paymentData = json_decode(decrypt($recurringPayment->payment_method_token), true);

                if (! $paymentData) {
                    throw new \Exception('Invalid payment method token');
                }

                // Process the payment
                // Note: In production, this would use the actual payment gateway
                $result = $this->processPayment($paymentService, $recurringPayment, $paymentData);

                if ($result['success']) {
                    $recurringPayment->recordPayment(
                        $recurringPayment->amount,
                        $result['transaction_id'],
                        $result['transaction_id'] ?? null
                    );

                    $this->info("  [SUCCESS] Transaction: {$result['transaction_id']}");
                    $processed++;

                    Log::info('Recurring payment processed successfully', [
                        'recurring_payment_id' => $recurringPayment->id,
                        'client_name' => $recurringPayment->client_name,
                        'amount' => $recurringPayment->amount,
                        'transaction_id' => $result['transaction_id'],
                    ]);
                } else {
                    $errorMessage = $result['error'] ?? 'Unknown error';
                    $recurringPayment->recordFailedPayment($errorMessage);

                    $this->error("  [FAILED] {$errorMessage}");
                    $failed++;

                    Log::warning('Recurring payment failed', [
                        'recurring_payment_id' => $recurringPayment->id,
                        'client_name' => $recurringPayment->client_name,
                        'amount' => $recurringPayment->amount,
                        'error' => $errorMessage,
                    ]);

                    // Send notification to admin
                    $this->notifyAdmin($recurringPayment, $errorMessage);
                }
            } catch (\Exception $e) {
                $recurringPayment->recordFailedPayment($e->getMessage());

                $this->error("  [ERROR] {$e->getMessage()}");
                $failed++;

                Log::error('Recurring payment processing error', [
                    'recurring_payment_id' => $recurringPayment->id,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]);

                // Send notification to admin
                $this->notifyAdmin($recurringPayment, $e->getMessage());
            }
        }

        $this->newLine();
        $this->info("Processing complete: {$processed} succeeded, {$failed} failed");

        return $failed > 0 ? Command::FAILURE : Command::SUCCESS;
    }

    /**
     * Process a payment through the payment gateway.
     *
     * @param  array  $paymentData  Decrypted payment method data (card/ACH details)
     */
    protected function processPayment(
        PaymentService $paymentService,
        RecurringPayment $recurringPayment,
        array $paymentData
    ): array {
        // Get or create customer if not exists
        $customer = $recurringPayment->customer;
        if (! $customer) {
            $customer = $paymentService->getOrCreateCustomer([
                'client_KEY' => $recurringPayment->client_id,
                'client_name' => $recurringPayment->client_name,
            ]);

            $recurringPayment->customer_id = $customer->id;
            $recurringPayment->save();
        }

        // Process the charge
        // - Cards go through MiPaymentChoice
        // - ACH goes through Kotapay (requires customer object)
        // The $paymentData array contains:
        // - For cards: type, number, expiry, cvv, name
        // - For ACH: type, routing, account, account_type, name
        return $paymentService->processRecurringCharge(
            $paymentData,
            (float) $recurringPayment->amount,
            $recurringPayment->description ?? "Recurring payment - {$recurringPayment->client_name}",
            $customer // Pass customer for ACH payments (Kotapay)
        );
    }

    /**
     * Send failure notification to admin.
     */
    protected function notifyAdmin(RecurringPayment $recurringPayment, string $errorMessage): void
    {
        $admin = new AdminNotifiable;

        if ($admin->isConfigured()) {
            try {
                $admin->notify(new RecurringPaymentFailed($recurringPayment, $errorMessage));
                $this->line('  [EMAIL] Admin notification sent');
            } catch (\Exception $e) {
                Log::error('Failed to send admin notification', [
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }
}
