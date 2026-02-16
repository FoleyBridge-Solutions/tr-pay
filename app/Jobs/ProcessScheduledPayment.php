<?php

namespace App\Jobs;

use App\Models\Payment;
use App\Models\PaymentPlan;
use App\Notifications\PaymentFailed;
use App\Notifications\PaymentPlanPaymentFailed;
use App\Notifications\PracticeCsWriteFailed;
use App\Repositories\PaymentRepository;
use App\Services\AdminAlertService;
use App\Services\PaymentService;
use App\Services\PracticeCsPaymentWriter;
use App\Support\AdminNotifiable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Process a scheduled payment for a payment plan.
 *
 * This job charges the customer's saved payment method and updates
 * the payment plan records accordingly.
 */
class ProcessScheduledPayment implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * The number of times the job may be attempted.
     *
     * @var int
     */
    public $tries = 3;

    /**
     * The number of seconds to wait before retrying the job.
     *
     * @var array
     */
    public $backoff = [60, 300, 900]; // 1 min, 5 min, 15 min

    /**
     * Create a new job instance.
     */
    public function __construct(
        public PaymentPlan $paymentPlan,
        public ?Payment $scheduledPayment = null
    ) {}

    /**
     * Execute the job.
     */
    public function handle(PaymentService $paymentService): void
    {
        Log::info('Processing scheduled payment', [
            'plan_id' => $this->paymentPlan->plan_id,
            'payment_number' => $this->paymentPlan->payments_completed + 1,
            'amount' => $this->paymentPlan->monthly_payment,
        ]);

        // Verify the plan is still active
        if (! $this->paymentPlan->isActive() && $this->paymentPlan->status !== PaymentPlan::STATUS_PAST_DUE) {
            Log::info('Payment plan is not active, skipping', [
                'plan_id' => $this->paymentPlan->plan_id,
                'status' => $this->paymentPlan->status,
            ]);

            return;
        }

        // Get the customer
        $customer = $this->paymentPlan->customer;
        if (! $customer) {
            Log::error('Customer not found for payment plan', [
                'plan_id' => $this->paymentPlan->plan_id,
                'customer_id' => $this->paymentPlan->customer_id,
            ]);
            $this->paymentPlan->recordFailedPayment('Customer not found');

            return;
        }

        DB::beginTransaction();

        try {
            // Process the payment using the saved payment method token
            $paymentNumber = $this->paymentPlan->payments_completed + 1;
            $totalPayments = $this->paymentPlan->duration_months;

            $result = $paymentService->processPayment(
                $customer,
                (float) $this->paymentPlan->monthly_payment,
                $this->paymentPlan->payment_method_token,
                "Payment plan installment {$paymentNumber} of {$totalPayments}",
                [
                    'plan_id' => $this->paymentPlan->plan_id,
                    'payment_number' => $paymentNumber,
                    'scheduled_date' => $this->paymentPlan->next_payment_date?->toDateString(),
                ]
            );

            if ($result['success']) {
                // Get the informational NCA fee from the scheduled payment or plan metadata
                $fee = $this->scheduledPayment
                    ? (float) ($this->scheduledPayment->fee ?? 0)
                    : (float) ($this->paymentPlan->metadata['fee_per_payment'] ?? 0);

                // Record the successful payment
                $payment = $this->paymentPlan->recordPayment(
                    (float) $this->paymentPlan->monthly_payment,
                    $result['transaction_id'],
                    $result['transaction_id'] ?? null,
                    $fee
                );

                // If we had a scheduled payment record, update it
                if ($this->scheduledPayment) {
                    $this->scheduledPayment->markAsCompleted($result['transaction_id']);
                }

                Log::info('Scheduled payment processed successfully', [
                    'plan_id' => $this->paymentPlan->plan_id,
                    'payment_id' => $payment->id,
                    'transaction_id' => $result['transaction_id'],
                    'amount' => $this->paymentPlan->monthly_payment,
                    'payments_completed' => $this->paymentPlan->payments_completed,
                    'plan_status' => $this->paymentPlan->status,
                ]);

                DB::commit();

                // Write installment to PracticeCS (or defer for ACH)
                $this->writeToPracticeCs($payment, (float) $this->paymentPlan->monthly_payment, $result['transaction_id']);

            } else {
                // Payment failed
                $this->handlePaymentFailure($result['error'] ?? 'Unknown error');
                DB::commit();
            }

        } catch (\Exception $e) {
            DB::rollBack();

            Log::error('Exception processing scheduled payment', [
                'plan_id' => $this->paymentPlan->plan_id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            // Re-throw to trigger job retry
            throw $e;
        }
    }

    /**
     * Handle a payment failure.
     */
    protected function handlePaymentFailure(string $reason): void
    {
        Log::warning('Scheduled payment failed', [
            'plan_id' => $this->paymentPlan->plan_id,
            'reason' => $reason,
            'attempt' => $this->attempts(),
        ]);

        // Record the failed payment
        $this->paymentPlan->recordFailedPayment($reason);

        // If we had a scheduled payment record, mark it as failed
        if ($this->scheduledPayment) {
            $this->scheduledPayment->markAsFailed($reason);
        }

        // Send payment failure notification to admin
        $this->notifyAdminOfFailure($reason);
    }

    /**
     * Handle a job failure after all retries.
     */
    public function failed(\Throwable $exception): void
    {
        Log::error('Scheduled payment job failed permanently', [
            'plan_id' => $this->paymentPlan->plan_id,
            'error' => $exception->getMessage(),
        ]);

        // Record the failure if not already recorded
        if ($this->paymentPlan->isActive()) {
            $this->paymentPlan->recordFailedPayment('Job failed: '.$exception->getMessage());
        }

        // Send critical failure notification to admin
        $this->notifyAdminOfFailure('Job failed after all retries: '.$exception->getMessage());
    }

    /**
     * Send failure notification to admin.
     */
    protected function notifyAdminOfFailure(string $errorMessage): void
    {
        $admin = new AdminNotifiable;

        if ($admin->isConfigured()) {
            try {
                $paymentNumber = $this->paymentPlan->payments_completed + 1;
                $admin->notify(new PaymentPlanPaymentFailed(
                    $this->paymentPlan,
                    $errorMessage,
                    $paymentNumber
                ));
            } catch (\Exception $e) {
                Log::error('Failed to send admin notification for payment plan failure', [
                    'error' => $e->getMessage(),
                    'plan_id' => $this->paymentPlan->plan_id,
                ]);
            }
        }

        try {
            AdminAlertService::notifyAll(new PaymentFailed(
                $this->paymentPlan->metadata['client_name'] ?? 'Unknown',
                $this->paymentPlan->client_id,
                (float) $this->paymentPlan->monthly_payment,
                $errorMessage,
                'plan_installment'
            ));
        } catch (\Exception $e) {
            Log::warning('Failed to send admin notification', ['error' => $e->getMessage()]);
        }
    }

    /**
     * Write installment payment to PracticeCS (or defer for ACH).
     *
     * Allocates the installment amount sequentially across the plan's invoices,
     * building on previous payments. For card payments, writes immediately.
     * For ACH, stores the payload in payment metadata for deferred writing
     * when the ACH transaction settles.
     *
     * @param  Payment  $payment  The payment record from recordPayment()
     * @param  float  $amount  The installment amount
     * @param  string  $transactionId  Gateway transaction ID
     */
    protected function writeToPracticeCs(Payment $payment, float $amount, string $transactionId): void
    {
        if (! config('practicecs.payment_integration.enabled')) {
            return;
        }

        try {
            $payload = $this->buildPracticeCsPayload($payment, $amount, $transactionId);

            if (! $payload) {
                return;
            }

            $isAch = $this->paymentPlan->payment_method_type === 'ach';
            $increments = $payload['_increments'] ?? [];
            unset($payload['_increments']); // Remove internal tracking from stored payload

            if ($isAch) {
                // ACH: Store payload for deferred write on settlement
                $paymentMetadata = $payment->metadata ?? [];
                $paymentMetadata['practicecs_data'] = $payload;
                $paymentMetadata['practicecs_increments'] = $increments;
                $payment->update(['metadata' => $paymentMetadata]);

                // Track pending amounts in plan metadata
                PracticeCsPaymentWriter::updatePlanTracking($this->paymentPlan, $increments, 'practicecs_pending');

                Log::info('PracticeCS: Plan installment deferred for ACH settlement', [
                    'payment_id' => $payment->id,
                    'plan_id' => $this->paymentPlan->plan_id,
                    'amount' => $amount,
                    'invoices_allocated' => count($payload['payment']['invoices'] ?? []),
                ]);
            } else {
                // Card: Write to PracticeCS immediately
                $writer = app(PracticeCsPaymentWriter::class);
                $result = $writer->writeDeferredPayment($payload);

                if ($result['success']) {
                    // Track applied amounts in plan metadata
                    PracticeCsPaymentWriter::updatePlanTracking($this->paymentPlan, $increments, 'practicecs_applied');

                    // Record PracticeCS write in payment metadata
                    $paymentMetadata = $payment->metadata ?? [];
                    $paymentMetadata['practicecs_written_at'] = now()->toIso8601String();
                    $paymentMetadata['practicecs_ledger_entry_KEY'] = $result['ledger_entry_KEY'] ?? null;
                    $payment->update(['metadata' => $paymentMetadata]);

                    Log::info('PracticeCS: Plan installment written immediately (card)', [
                        'payment_id' => $payment->id,
                        'plan_id' => $this->paymentPlan->plan_id,
                        'ledger_entry_KEY' => $result['ledger_entry_KEY'],
                        'amount' => $amount,
                    ]);
                } else {
                    Log::error('PracticeCS: Plan installment write failed', [
                        'payment_id' => $payment->id,
                        'plan_id' => $this->paymentPlan->plan_id,
                        'error' => $result['error'] ?? 'Unknown error',
                    ]);

                    try {
                        AdminAlertService::notifyAll(new PracticeCsWriteFailed(
                            $payment->transaction_id,
                            $this->paymentPlan->client_id,
                            $amount,
                            $result['error'] ?? 'Unknown error',
                            'plan_installment'
                        ));
                    } catch (\Exception $notifyEx) {
                        Log::warning('Failed to send admin notification', ['error' => $notifyEx->getMessage()]);
                    }
                }
            }
        } catch (\Exception $e) {
            // Log but don't fail — the payment already succeeded
            Log::error('PracticeCS: Exception writing plan installment', [
                'payment_id' => $payment->id,
                'plan_id' => $this->paymentPlan->plan_id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
        }
    }

    /**
     * Build the PracticeCS payload for a plan installment.
     *
     * Reads the plan's stored invoice data and allocates the installment
     * amount sequentially across invoices, accounting for prior payments.
     *
     * @param  Payment  $payment  The payment record
     * @param  float  $amount  Installment amount
     * @param  string  $transactionId  Gateway transaction ID
     * @return array|null Payload for PracticeCsPaymentWriter, or null if unable to build
     */
    protected function buildPracticeCsPayload(Payment $payment, float $amount, string $transactionId): ?array
    {
        $metadata = $this->paymentPlan->metadata ?? [];
        $invoices = $metadata['practicecs_invoices'] ?? [];
        $applied = $metadata['practicecs_applied'] ?? [];
        $pending = $metadata['practicecs_pending'] ?? [];

        // Resolve client_KEY
        $clientKey = $metadata['client_KEY'] ?? null;

        if (! $clientKey) {
            $clientKey = app(PaymentRepository::class)->resolveClientKey($this->paymentPlan->client_id);
        }

        if (! $clientKey) {
            Log::warning('PracticeCS: Cannot resolve client_KEY for plan installment, skipping', [
                'payment_id' => $payment->id,
                'plan_id' => $this->paymentPlan->plan_id,
                'client_id' => $this->paymentPlan->client_id,
            ]);

            return null;
        }

        // Check if we have invoice data with ledger_entry_KEYs
        $hasLedgerKeys = ! empty($invoices) && isset($invoices[0]['ledger_entry_KEY']);

        if (! $hasLedgerKeys) {
            Log::warning('PracticeCS: No ledger_entry_KEY data in plan invoices, skipping', [
                'payment_id' => $payment->id,
                'plan_id' => $this->paymentPlan->plan_id,
            ]);

            return null;
        }

        // Allocate payment sequentially across invoices
        $allocation = PracticeCsPaymentWriter::allocateToInvoices(
            $invoices,
            $amount,
            $applied,
            $pending
        );

        $paymentNumber = $this->paymentPlan->payments_completed;
        $totalPayments = $this->paymentPlan->duration_months;
        $methodType = $this->paymentPlan->payment_method_type;
        $methodConfigKey = $methodType === 'card' ? 'credit_card' : $methodType;

        $payload = [
            'payment' => [
                'client_KEY' => $clientKey,
                'amount' => $amount,
                'reference' => $transactionId,
                'comments' => "Payment plan installment {$paymentNumber} of {$totalPayments} - plan {$this->paymentPlan->plan_id}",
                'internal_comments' => json_encode([
                    'source' => 'tr-pay',
                    'transaction_id' => $transactionId,
                    'payment_method' => $methodType,
                    'plan_id' => $this->paymentPlan->plan_id,
                    'payment_id' => $payment->id,
                    'payment_number' => $paymentNumber,
                    'total_payments' => $totalPayments,
                    'processed_at' => now()->toIso8601String(),
                ]),
                'staff_KEY' => config('practicecs.payment_integration.staff_key'),
                'bank_account_KEY' => config('practicecs.payment_integration.bank_account_key'),
                'ledger_type_KEY' => config("practicecs.payment_integration.ledger_types.{$methodConfigKey}"),
                'subtype_KEY' => config("practicecs.payment_integration.payment_subtypes.{$methodConfigKey}"),
                'invoices' => $allocation['allocations'],
            ],
            '_increments' => $allocation['increments'],
        ];

        return $payload;
    }
}
