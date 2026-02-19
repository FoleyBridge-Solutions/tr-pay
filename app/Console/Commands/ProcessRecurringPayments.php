<?php

namespace App\Console\Commands;

use App\Models\CustomerPaymentMethod;
use App\Models\Payment;
use App\Models\RecurringPayment;
use App\Notifications\PaymentFailed;
use App\Notifications\PracticeCsWriteFailed;
use App\Notifications\RecurringPaymentFailed;
use App\Repositories\PaymentRepository;
use App\Services\AdminAlertService;
use App\Services\PaymentService;
use App\Services\PracticeCsPaymentWriter;
use App\Support\AdminNotifiable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Process all recurring payments that are due.
 *
 * This command should be run daily via the scheduler.
 * It finds all active recurring payments with due dates and processes them.
 *
 * Payment method resolution:
 * 1. FK lookup via customer_payment_method_id (preferred)
 * 2. Fallback to mpc_token string match on CustomerPaymentMethod (legacy rows)
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
            ->whereDate('next_payment_date', '<=', now()->toDateString());

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
                    $payment = $recurringPayment->recordPayment(
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

                    // Write to PracticeCS as unapplied payment
                    if (config('practicecs.payment_integration.enabled')) {
                        $this->writeToPracticeCs($recurringPayment, $payment);
                    }

                    // Send receipt email for card payments (ACH receipts are sent on settlement)
                    $isAch = $recurringPayment->payment_method_type === 'ach';
                    if (! $isAch) {
                        $payment->sendReceipt();
                    }
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
     * Resolves the CustomerPaymentMethod via FK (customer_payment_method_id)
     * or falls back to mpc_token string match for legacy rows.
     *
     * @return array Result with 'success', 'transaction_id', etc.
     */
    protected function processPaymentByTokenFormat(
        PaymentService $paymentService,
        RecurringPayment $recurringPayment
    ): array {
        // Find the saved payment method via FK or token match
        $savedMethod = $this->findSavedMethod($recurringPayment);

        if ($savedMethod) {
            return $this->processWithSavedMethod($paymentService, $recurringPayment, $savedMethod);
        }

        // No saved method found — cannot process
        throw new \RuntimeException(
            "No CustomerPaymentMethod found for recurring payment #{$recurringPayment->id} "
            ."(customer_payment_method_id={$recurringPayment->customer_payment_method_id}, "
            ."token={$recurringPayment->payment_method_token}). "
            .'Run the backfill migration or manually assign a payment method.'
        );
    }

    /**
     * Find the CustomerPaymentMethod for this recurring payment.
     *
     * Prefers the FK relationship (customer_payment_method_id), then falls
     * back to matching by mpc_token for legacy rows not yet backfilled.
     */
    protected function findSavedMethod(RecurringPayment $recurringPayment): ?CustomerPaymentMethod
    {
        // Prefer FK relationship
        if ($recurringPayment->customer_payment_method_id) {
            return $recurringPayment->paymentMethod;
        }

        // Fallback: match by token string for legacy rows
        if (! $recurringPayment->customer_id || empty($recurringPayment->payment_method_token)) {
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
     * Build the PracticeCS payload for a recurring payment.
     *
     * Recurring payments are written as unapplied payments (no invoice applications)
     * since they have no invoice selection — the accounting team applies them
     * manually in PracticeCS.
     *
     * Uses the correct ledger type for the payment method:
     * - Credit Card: type 9 / subtype 10
     * - ACH: type 11 / subtype 12
     *
     * Used for both immediate writes (cards) and deferred writes (ACH).
     *
     * @return array Structured payload with 'payment' key for writeDeferredPayment()
     */
    protected function buildPracticeCsPayload(RecurringPayment $recurringPayment, Payment $payment): array
    {
        $methodType = $recurringPayment->payment_method_type === 'ach' ? 'ach' : 'credit_card';

        // Resolve client_KEY from client_id for PracticeCS posting
        $paymentRepo = app(PaymentRepository::class);
        $clientKey = $paymentRepo->resolveClientKey($recurringPayment->client_id);

        if (! $clientKey) {
            Log::error('Failed to resolve client_KEY for PracticeCS payload', [
                'payment_id' => $payment->id,
                'recurring_payment_id' => $recurringPayment->id,
                'client_id' => $recurringPayment->client_id,
            ]);

            try {
                AdminAlertService::notifyAll(new PracticeCsWriteFailed(
                    $payment->transaction_id,
                    $recurringPayment->client_id,
                    (float) $payment->amount,
                    'Cannot resolve client_KEY for recurring payment',
                    'recurring'
                ));
            } catch (\Exception $notifyEx) {
                Log::warning('Failed to send admin notification', ['error' => $notifyEx->getMessage()]);
            }

            return ['payment' => null];
        }

        return [
            'payment' => [
                'client_KEY' => $clientKey,
                'amount' => $payment->amount,
                'reference' => $payment->transaction_id,
                'comments' => "Recurring payment - {$methodType}",
                'internal_comments' => json_encode([
                    'source' => 'tr-pay-recurring',
                    'transaction_id' => $payment->transaction_id,
                    'payment_method' => $methodType,
                    'recurring_payment_id' => $recurringPayment->id,
                    'processed_at' => now()->toIso8601String(),
                ]),
                'staff_KEY' => config('practicecs.payment_integration.staff_key'),
                'bank_account_KEY' => config('practicecs.payment_integration.bank_account_key'),
                'ledger_type_KEY' => config("practicecs.payment_integration.ledger_types.{$methodType}"),
                'subtype_KEY' => config("practicecs.payment_integration.payment_subtypes.{$methodType}"),
                'invoices' => [],
            ],
        ];
    }

    /**
     * Write a recurring payment to PracticeCS as an unapplied payment.
     *
     * For cards: writes immediately.
     * For ACH: stores the payload in metadata for deferred write on settlement.
     */
    protected function writeToPracticeCs(RecurringPayment $recurringPayment, Payment $payment): void
    {
        $payload = $this->buildPracticeCsPayload($recurringPayment, $payment);

        if (! $payload['payment']) {
            return;
        }

        $isAch = $recurringPayment->payment_method_type === 'ach';

        if ($isAch) {
            // ACH: Defer PracticeCS write until settlement is confirmed
            $metadata = $payment->metadata ?? [];
            $metadata['practicecs_data'] = $payload;
            $payment->update(['metadata' => $metadata]);

            Log::info('ACH recurring payment: PracticeCS write deferred until settlement', [
                'payment_id' => $payment->id,
                'recurring_payment_id' => $recurringPayment->id,
            ]);

            $this->line('  [PracticeCS] Deferred until ACH settlement');
        } else {
            // Card: Write to PracticeCS immediately
            $writer = app(PracticeCsPaymentWriter::class);
            $result = $writer->writeDeferredPayment($payload);

            if ($result['success']) {
                // Record that PracticeCS write succeeded
                $metadata = $payment->metadata ?? [];
                $metadata['practicecs_written_at'] = now()->toIso8601String();
                $metadata['practicecs_ledger_entry_KEY'] = $result['ledger_entry_KEY'] ?? null;
                $payment->update(['metadata' => $metadata]);

                Log::info('Recurring payment written to PracticeCS', [
                    'payment_id' => $payment->id,
                    'recurring_payment_id' => $recurringPayment->id,
                    'ledger_entry_KEY' => $result['ledger_entry_KEY'],
                ]);

                $this->line("  [PracticeCS] Payment written (ledger_entry_KEY: {$result['ledger_entry_KEY']})");
            } else {
                Log::error('Failed to write recurring payment to PracticeCS', [
                    'payment_id' => $payment->id,
                    'recurring_payment_id' => $recurringPayment->id,
                    'error' => $result['error'],
                ]);

                try {
                    AdminAlertService::notifyAll(new PracticeCsWriteFailed(
                        $payment->transaction_id,
                        $recurringPayment->client_id,
                        (float) $payment->amount,
                        $result['error'],
                        'recurring'
                    ));
                } catch (\Exception $notifyEx) {
                    Log::warning('Failed to send admin notification', ['error' => $notifyEx->getMessage()]);
                }

                $this->warn("  [PracticeCS] Write failed: {$result['error']}");
            }
        }
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

        try {
            AdminAlertService::notifyAll(new PaymentFailed(
                $recurringPayment->client_name,
                $recurringPayment->client_id,
                (float) $recurringPayment->amount,
                $errorMessage,
                'recurring'
            ));
        } catch (\Exception $e) {
            Log::warning('Failed to send admin notification', ['error' => $e->getMessage()]);
        }
    }
}
