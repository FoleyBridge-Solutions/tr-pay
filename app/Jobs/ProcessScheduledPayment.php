<?php

namespace App\Jobs;

use App\Models\Payment;
use App\Models\PaymentPlan;
use App\Services\PaymentService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;

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
        if (!$this->paymentPlan->isActive() && $this->paymentPlan->status !== PaymentPlan::STATUS_PAST_DUE) {
            Log::info('Payment plan is not active, skipping', [
                'plan_id' => $this->paymentPlan->plan_id,
                'status' => $this->paymentPlan->status,
            ]);
            return;
        }

        // Get the customer
        $customer = $this->paymentPlan->customer;
        if (!$customer) {
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
                // Record the successful payment
                $payment = $this->paymentPlan->recordPayment(
                    (float) $this->paymentPlan->monthly_payment,
                    $result['transaction_id']
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

                // TODO: Send payment confirmation email/notification

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

        // TODO: Send payment failure notification
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
            $this->paymentPlan->recordFailedPayment('Job failed: ' . $exception->getMessage());
        }

        // TODO: Send critical failure notification to admin
    }
}
