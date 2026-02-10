<?php

namespace App\Console\Commands;

use App\Models\CustomerPaymentMethod;
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
 *
 * Supports three payment_method_token formats:
 * 1. MPC gateway token (cards with a CustomerPaymentMethod record)
 * 2. ACH pseudo-token ("ach_local_..." with encrypted bank details on CustomerPaymentMethod)
 * 3. Legacy encrypted JSON (raw card/ACH data from old imports, pre-saved-method era)
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
                // Determine token format and process accordingly
                $result = $this->processPaymentByTokenFormat($paymentService, $recurringPayment);

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
     * Determine the token format and route to the appropriate processing method.
     *
     * Supports three formats:
     * 1. Saved method token — references a CustomerPaymentMethod record
     * 2. Legacy encrypted JSON — raw card/ACH data from old imports
     *
     * @return array Result with 'success', 'transaction_id', etc.
     */
    protected function processPaymentByTokenFormat(
        PaymentService $paymentService,
        RecurringPayment $recurringPayment
    ): array {
        $token = $recurringPayment->payment_method_token;

        // First, check if this token matches a saved CustomerPaymentMethod
        $savedMethod = $this->findSavedMethod($recurringPayment);

        if ($savedMethod) {
            return $this->processWithSavedMethod($paymentService, $recurringPayment, $savedMethod);
        }

        // Fall back to legacy encrypted data format
        return $this->processWithEncryptedData($paymentService, $recurringPayment);
    }

    /**
     * Find the CustomerPaymentMethod record that matches this recurring payment's token.
     */
    protected function findSavedMethod(RecurringPayment $recurringPayment): ?CustomerPaymentMethod
    {
        if (! $recurringPayment->customer_id) {
            return null;
        }

        return CustomerPaymentMethod::where('customer_id', $recurringPayment->customer_id)
            ->where('mpc_token', $recurringPayment->payment_method_token)
            ->first();
    }

    /**
     * Process payment using a saved CustomerPaymentMethod.
     *
     * For cards: charges via the MPC reusable token.
     * For ACH: decrypts bank details from the saved method and charges via Kotapay.
     *
     * @return array Result with 'success', 'transaction_id', etc.
     */
    protected function processWithSavedMethod(
        PaymentService $paymentService,
        RecurringPayment $recurringPayment,
        CustomerPaymentMethod $savedMethod
    ): array {
        $customer = $recurringPayment->customer;
        $description = $recurringPayment->description ?? "Recurring payment - {$recurringPayment->client_name}";

        if ($savedMethod->isCard()) {
            // Card: charge via saved method token (MPC reusable token)
            return $paymentService->chargeWithSavedMethod(
                $customer,
                $savedMethod,
                (float) $recurringPayment->amount,
                ['description' => $description]
            );
        }

        // ACH: get decrypted bank details and charge via Kotapay
        $bankDetails = $savedMethod->getBankDetails();

        if (! $bankDetails) {
            throw new \Exception('ACH saved method has no stored bank details');
        }

        $paymentData = [
            'type' => 'ach',
            'routing' => $bankDetails['routing_number'],
            'account' => $bankDetails['account_number'],
            'account_type' => $savedMethod->account_type ?? 'checking',
            'name' => $customer->name,
            'is_business' => $savedMethod->is_business ?? false,
        ];

        return $paymentService->processRecurringCharge(
            $paymentData,
            (float) $recurringPayment->amount,
            $description,
            $customer
        );
    }

    /**
     * Process payment using legacy encrypted payment data.
     *
     * This handles the old format where raw card/ACH data was encrypted
     * and stored directly in payment_method_token.
     *
     * @return array Result with 'success', 'transaction_id', etc.
     */
    protected function processWithEncryptedData(
        PaymentService $paymentService,
        RecurringPayment $recurringPayment
    ): array {
        // Decrypt the payment method token
        $paymentData = json_decode(decrypt($recurringPayment->payment_method_token), true);

        if (! $paymentData) {
            throw new \Exception('Invalid payment method token - could not decrypt');
        }

        // Get or create customer if not exists
        $customer = $recurringPayment->customer;
        if (! $customer) {
            $customer = $paymentService->getOrCreateCustomer([
                'client_id' => $recurringPayment->client_id,
                'client_name' => $recurringPayment->client_name,
            ]);

            $recurringPayment->customer_id = $customer->id;
            $recurringPayment->save();
        }

        $description = $recurringPayment->description ?? "Recurring payment - {$recurringPayment->client_name}";

        return $paymentService->processRecurringCharge(
            $paymentData,
            (float) $recurringPayment->amount,
            $description,
            $customer
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
