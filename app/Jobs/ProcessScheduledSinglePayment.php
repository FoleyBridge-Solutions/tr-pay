<?php

namespace App\Jobs;

use App\Mail\PaymentReceipt;
use App\Models\Customer;
use App\Models\CustomerPaymentMethod;
use App\Models\Payment;
use App\Services\PaymentService;
use App\Services\PracticeCsPaymentWriter;
use App\Support\AdminNotifiable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Process a scheduled single payment.
 *
 * This job charges the customer's saved payment method and updates
 * the payment record accordingly. It also writes to PracticeCS.
 */
class ProcessScheduledSinglePayment implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * The number of times the job may be attempted.
     */
    public int $tries = 3;

    /**
     * The number of seconds to wait before retrying the job.
     */
    public array $backoff = [60, 300, 900]; // 1 min, 5 min, 15 min

    /**
     * Create a new job instance.
     */
    public function __construct(
        public Payment $payment
    ) {}

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        Log::info('Processing scheduled single payment', [
            'payment_id' => $this->payment->id,
            'transaction_id' => $this->payment->transaction_id,
            'amount' => $this->payment->amount,
        ]);

        // Verify the payment is still pending
        if ($this->payment->status !== Payment::STATUS_PENDING) {
            Log::info('Payment is not pending, skipping', [
                'payment_id' => $this->payment->id,
                'status' => $this->payment->status,
            ]);

            return;
        }

        $metadata = $this->payment->metadata ?? [];
        $paymentMethodToken = $metadata['payment_method_token'] ?? null;
        $clientId = $this->payment->client_key; // Column stores client_id values

        if (! $paymentMethodToken) {
            $this->handleFailure('No payment method token found');

            return;
        }

        // Get the customer
        $customer = Customer::where('client_id', $clientId)->first();
        if (! $customer) {
            $this->handleFailure('Customer not found');

            return;
        }

        try {
            $chargeAmount = (float) $this->payment->total_amount;
            $isAch = $this->payment->payment_method === 'ach';

            if ($isAch) {
                // ACH: Look up saved payment method, decrypt bank details, charge via Kotapay
                $paymentMethodId = $metadata['payment_method_id'] ?? null;
                if (! $paymentMethodId) {
                    $this->handleFailure('No saved payment method ID found for ACH payment');

                    return;
                }

                $savedMethod = CustomerPaymentMethod::find($paymentMethodId);
                if (! $savedMethod || ! $savedMethod->hasBankDetails()) {
                    $this->handleFailure('Saved ACH payment method not found or missing bank details');

                    return;
                }

                $bankDetails = $savedMethod->getBankDetails();
                if (! $bankDetails) {
                    $this->handleFailure('Failed to decrypt bank account details');

                    return;
                }

                $paymentService = app(PaymentService::class);
                $achResult = $paymentService->chargeAchWithKotapay($customer, [
                    'routing_number' => $bankDetails['routing_number'],
                    'account_number' => $bankDetails['account_number'],
                    'account_type' => $savedMethod->account_type ?? 'checking',
                    'account_name' => $customer->name,
                    'is_business' => $savedMethod->is_business ?? false,
                ], $chargeAmount, [
                    'description' => $this->payment->description ?? 'Scheduled payment',
                ]);

                if (! $achResult['success']) {
                    $this->handleFailure($achResult['error'] ?? 'ACH payment failed');

                    return;
                }

                // ACH: mark as processing (not completed) — settlement takes 2-3 days
                $this->payment->update([
                    'status' => Payment::STATUS_PROCESSING,
                    'payment_vendor' => 'kotapay',
                    'vendor_transaction_id' => $achResult['transaction_id'] ?? null,
                ]);

                Log::info('Scheduled ACH payment submitted for processing', [
                    'payment_id' => $this->payment->id,
                    'transaction_id' => $this->payment->transaction_id,
                    'vendor_transaction_id' => $achResult['transaction_id'] ?? null,
                    'amount' => $this->payment->amount,
                ]);
            } else {
                // Card: charge via MiPaymentChoice
                $chargeResponse = $customer->charge($chargeAmount, $paymentMethodToken, [
                    'description' => $this->payment->description ?? 'Scheduled payment',
                ]);

                if (! $chargeResponse || empty($chargeResponse['TransactionKey'])) {
                    $errorMessage = $chargeResponse['ResponseStatus']['Message'] ?? 'Payment failed';
                    $this->handleFailure($errorMessage);

                    return;
                }

                // Card: mark as completed (cards settle immediately)
                $this->payment->update([
                    'status' => Payment::STATUS_COMPLETED,
                    'processed_at' => now(),
                    'vendor_transaction_id' => $chargeResponse['TransactionKey'] ?? null,
                ]);

                Log::info('Scheduled card payment processed successfully', [
                    'payment_id' => $this->payment->id,
                    'transaction_id' => $this->payment->transaction_id,
                    'gateway_transaction_id' => $chargeResponse['TransactionKey'],
                    'amount' => $this->payment->amount,
                ]);
            }

            // Write to PracticeCS if enabled
            if (config('practicecs.payment_integration.enabled')) {
                $this->writeToPracticeCs($metadata);
            }

            // Send success email
            $this->sendSuccessEmail($metadata);

        } catch (\Exception $e) {
            Log::error('Exception processing scheduled payment', [
                'payment_id' => $this->payment->id,
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
    protected function handleFailure(string $reason): void
    {
        Log::warning('Scheduled payment failed', [
            'payment_id' => $this->payment->id,
            'reason' => $reason,
            'attempt' => $this->attempts(),
        ]);

        $this->payment->markAsFailed($reason);

        // Send failure notification
        $this->sendFailureNotification($reason);
    }

    /**
     * Write payment to PracticeCS.
     */
    protected function writeToPracticeCs(array $metadata): void
    {
        $writer = app(PracticeCsPaymentWriter::class);

        $methodType = $this->payment->payment_method === 'credit_card' ? 'credit_card' : 'ach';
        $invoices = $metadata['invoices'] ?? [];

        // Resolve client_KEY from client_id for PracticeCS posting
        $clientId = $this->payment->client_key; // Column stores client_id values
        $paymentRepo = app(\App\Repositories\PaymentRepository::class);
        $clientKey = $paymentRepo->resolveClientKey($clientId);

        if (! $clientKey) {
            Log::error('Failed to resolve client_KEY for PracticeCS write', [
                'payment_id' => $this->payment->id,
                'client_id' => $clientId,
            ]);

            return;
        }

        $practiceCsData = [
            'client_KEY' => $clientKey,
            'amount' => $this->payment->amount,
            'reference' => $this->payment->transaction_id,
            'comments' => "Scheduled payment - {$methodType}",
            'internal_comments' => json_encode([
                'source' => 'tr-pay-scheduled',
                'transaction_id' => $this->payment->transaction_id,
                'payment_method' => $methodType,
                'fee' => $this->payment->fee,
                'processed_at' => now()->toIso8601String(),
            ]),
            'staff_KEY' => config('practicecs.payment_integration.staff_key'),
            'bank_account_KEY' => config('practicecs.payment_integration.bank_account_key'),
            'ledger_type_KEY' => config("practicecs.payment_integration.ledger_types.{$methodType}"),
            'subtype_KEY' => config("practicecs.payment_integration.payment_subtypes.{$methodType}"),
            'invoices' => $invoices,
        ];

        $result = $writer->writePayment($practiceCsData);

        if (! $result['success']) {
            Log::error('Failed to write scheduled payment to PracticeCS', [
                'payment_id' => $this->payment->id,
                'error' => $result['error'],
            ]);
        } else {
            Log::info('Scheduled payment written to PracticeCS', [
                'payment_id' => $this->payment->id,
                'ledger_entry_KEY' => $result['ledger_entry_KEY'],
            ]);
        }
    }

    /**
     * Send success email notification.
     */
    protected function sendSuccessEmail(array $metadata): void
    {
        $clientEmail = $metadata['client_email'] ?? null;

        if (! $clientEmail) {
            return;
        }

        try {
            $paymentData = [
                'amount' => $this->payment->amount,
                'fee' => $this->payment->fee,
                'total' => $this->payment->total_amount,
                'payment_method' => $this->payment->payment_method,
                'last_four' => $this->payment->payment_method_last_four,
            ];

            $clientInfo = [
                'client_name' => $metadata['client_name'] ?? 'Client',
                'client_id' => $metadata['client_id'] ?? '',
            ];

            Mail::to($clientEmail)->send(new PaymentReceipt(
                $paymentData,
                $clientInfo,
                $this->payment->transaction_id
            ));

            Log::info('Payment receipt email sent for scheduled payment', [
                'payment_id' => $this->payment->id,
                'email' => $clientEmail,
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to send payment receipt email', [
                'payment_id' => $this->payment->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Send failure notification to admin.
     */
    protected function sendFailureNotification(string $errorMessage): void
    {
        $admin = new AdminNotifiable;

        if (! $admin->isConfigured()) {
            return;
        }

        try {
            $metadata = $this->payment->metadata ?? [];
            $clientName = $metadata['client_name'] ?? 'Unknown Client';
            $appName = config('app.name');

            $admin->notify(new class($this->payment, $errorMessage, $clientName) extends Notification
            {
                public function __construct(
                    public Payment $payment,
                    public string $errorMessage,
                    public string $clientName
                ) {}

                public function via($notifiable): array
                {
                    return ['mail'];
                }

                public function toMail($notifiable): MailMessage
                {
                    $appName = config('app.name');

                    return (new MailMessage)
                        ->subject("[{$appName}] Scheduled Payment Failed - {$this->clientName}")
                        ->error()
                        ->greeting('Scheduled Payment Failed')
                        ->line("A scheduled payment failed to process for **{$this->clientName}**.")
                        ->line('**Payment Details:**')
                        ->line("- Transaction ID: {$this->payment->transaction_id}")
                        ->line('- Amount: $'.number_format($this->payment->amount, 2))
                        ->line('- Scheduled Date: '.($this->payment->scheduled_date?->format('F j, Y') ?? 'N/A'))
                        ->line('')
                        ->line('**Error:**')
                        ->line($this->errorMessage)
                        ->action('View Payments', route('admin.payments'))
                        ->line('')
                        ->line('Please review this payment and take appropriate action.')
                        ->salutation("- {$appName} System");
                }
            });
        } catch (\Exception $e) {
            Log::error('Failed to send admin notification for scheduled payment failure', [
                'payment_id' => $this->payment->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Handle a job failure after all retries.
     */
    public function failed(\Throwable $exception): void
    {
        Log::error('Scheduled payment job failed permanently', [
            'payment_id' => $this->payment->id,
            'error' => $exception->getMessage(),
        ]);

        // Mark as failed if not already
        if ($this->payment->status === Payment::STATUS_PENDING) {
            $this->payment->markAsFailed('Job failed: '.$exception->getMessage());
        }

        // Send critical failure notification
        $this->sendFailureNotification('Job failed after all retries: '.$exception->getMessage());
    }
}
