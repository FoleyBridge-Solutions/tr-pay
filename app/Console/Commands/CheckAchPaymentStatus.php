<?php

namespace App\Console\Commands;

use App\Models\Customer;
use App\Models\Payment;
use App\Support\AdminNotifiable;
use FoleyBridgeSolutions\KotapayCashier\Exceptions\PaymentFailedException;
use Illuminate\Console\Command;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Log;

/**
 * Check the status of ACH payments that are still processing.
 *
 * This command polls the Kotapay API for ACH payments with status 'processing'
 * and updates them to 'completed' (settled) or 'failed' (returned/rejected).
 *
 * Should be run daily via the scheduler after business hours.
 */
class CheckAchPaymentStatus extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'payments:check-ach-status
                            {--dry-run : Show what would be checked without updating}
                            {--id= : Check a specific payment by ID}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Check the settlement status of ACH payments via Kotapay';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $dryRun = $this->option('dry-run');
        $specificId = $this->option('id');

        $this->info('Checking ACH payment statuses...');
        $this->newLine();

        // Query processing ACH payments
        $query = Payment::query()
            ->where('status', Payment::STATUS_PROCESSING)
            ->where('payment_vendor', 'kotapay');

        if ($specificId) {
            $query->where('id', $specificId);
        }

        $payments = $query->get();

        if ($payments->isEmpty()) {
            $this->info('No ACH payments currently processing.');

            return self::SUCCESS;
        }

        $this->info("Found {$payments->count()} processing ACH payment(s).");
        $this->newLine();

        $settled = 0;
        $returned = 0;
        $stillProcessing = 0;
        $skipped = 0;
        $errors = 0;
        $failedPayments = [];

        foreach ($payments as $payment) {
            $this->line("Checking payment #{$payment->id} ({$payment->transaction_id}) - \${$payment->total_amount}");

            // Skip payments with fake/fallback transaction IDs
            if ($this->isFallbackTransactionId($payment->vendor_transaction_id)) {
                $this->warn("  [SKIPPED] Fallback transaction ID: {$payment->vendor_transaction_id}");
                $this->warn('  Cannot poll Kotapay without a real transaction ID.');

                Log::warning('Skipping ACH status check - fallback transaction ID', [
                    'payment_id' => $payment->id,
                    'vendor_transaction_id' => $payment->vendor_transaction_id,
                ]);

                $skipped++;

                continue;
            }

            if (empty($payment->vendor_transaction_id)) {
                $this->warn('  [SKIPPED] No vendor transaction ID');
                $skipped++;

                continue;
            }

            if ($dryRun) {
                $this->info("  [DRY RUN] Would check Kotapay for transaction: {$payment->vendor_transaction_id}");
                $stillProcessing++;

                continue;
            }

            try {
                // Look up a customer to use the AchBillable trait
                $customer = $payment->customer;
                if (! $customer) {
                    $customer = Customer::where('client_id', $payment->client_id)->first();
                }

                if (! $customer) {
                    $this->error('  [ERROR] Customer not found for payment');
                    $errors++;

                    continue;
                }

                // Poll Kotapay for the payment status
                $response = $customer->getAchPaymentStatus($payment->vendor_transaction_id);

                Log::info('Kotapay ACH status check response', [
                    'payment_id' => $payment->id,
                    'vendor_transaction_id' => $payment->vendor_transaction_id,
                    'response' => $response,
                ]);

                // Parse the Kotapay response status
                $status = $this->parseKotapayStatus($response);

                switch ($status) {
                    case 'settled':
                        $payment->update([
                            'status' => Payment::STATUS_COMPLETED,
                            'processed_at' => now(),
                        ]);

                        $this->info('  [SETTLED] Payment marked as completed');
                        $settled++;

                        Log::info('ACH payment settled', [
                            'payment_id' => $payment->id,
                            'vendor_transaction_id' => $payment->vendor_transaction_id,
                        ]);
                        break;

                    case 'returned':
                    case 'rejected':
                    case 'failed':
                        $returnReason = $this->parseReturnReason($response);

                        $payment->update([
                            'status' => Payment::STATUS_FAILED,
                            'failure_reason' => "ACH {$status}: {$returnReason}",
                            'failed_at' => now(),
                        ]);

                        $this->error("  [RETURNED] {$returnReason}");
                        $returned++;

                        $failedPayments[] = [
                            'payment' => $payment,
                            'reason' => $returnReason,
                            'status' => $status,
                        ];

                        Log::warning('ACH payment returned/failed', [
                            'payment_id' => $payment->id,
                            'vendor_transaction_id' => $payment->vendor_transaction_id,
                            'return_reason' => $returnReason,
                            'status' => $status,
                        ]);
                        break;

                    default:
                        $this->line('  [PROCESSING] Still pending settlement');
                        $stillProcessing++;

                        Log::info('ACH payment still processing', [
                            'payment_id' => $payment->id,
                            'vendor_transaction_id' => $payment->vendor_transaction_id,
                            'kotapay_status' => $status,
                        ]);
                        break;
                }
            } catch (PaymentFailedException $e) {
                $this->error("  [ERROR] Kotapay API error: {$e->getMessage()}");
                $errors++;

                Log::error('Failed to check ACH payment status', [
                    'payment_id' => $payment->id,
                    'vendor_transaction_id' => $payment->vendor_transaction_id,
                    'error' => $e->getMessage(),
                ]);
            } catch (\Exception $e) {
                $this->error("  [ERROR] {$e->getMessage()}");
                $errors++;

                Log::error('Unexpected error checking ACH payment status', [
                    'payment_id' => $payment->id,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]);
            }
        }

        // Summary
        $this->newLine();
        $this->info('Summary:');
        $this->table(
            ['Status', 'Count'],
            [
                ['Settled (completed)', $settled],
                ['Returned (failed)', $returned],
                ['Still Processing', $stillProcessing],
                ['Skipped (no valid ID)', $skipped],
                ['Errors', $errors],
            ]
        );

        // Send admin notification if any payments were returned
        if (! empty($failedPayments) && ! $dryRun) {
            $this->sendFailureNotification($failedPayments);
        }

        return $errors > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Check if a transaction ID is a fallback/fake ID.
     *
     * Fallback IDs are generated locally when Kotapay doesn't return
     * a real transaction ID. They cannot be used to poll for status.
     */
    protected function isFallbackTransactionId(?string $transactionId): bool
    {
        if (empty($transactionId)) {
            return true;
        }

        // Local fallback IDs start with 'ach_'
        return str_starts_with($transactionId, 'ach_');
    }

    /**
     * Parse the Kotapay API response to determine payment status.
     *
     * The Kotapay API response structure may vary. This method attempts
     * to extract the status from known response patterns and falls back
     * to 'processing' if the status cannot be determined.
     *
     * @param  array  $response  Raw Kotapay API response
     * @return string One of: 'settled', 'returned', 'rejected', 'failed', 'processing', 'pending'
     */
    protected function parseKotapayStatus(array $response): string
    {
        // Try common response paths for status
        $status = $response['data']['status']
            ?? $response['data']['paymentStatus']
            ?? $response['status']
            ?? $response['paymentStatus']
            ?? null;

        if ($status === null) {
            Log::warning('Could not determine Kotapay payment status from response', [
                'response_keys' => array_keys($response),
                'data_keys' => isset($response['data']) ? array_keys($response['data']) : [],
            ]);

            return 'processing';
        }

        // Normalize the status string
        $normalizedStatus = strtolower(trim((string) $status));

        // Map Kotapay statuses to our internal statuses
        return match (true) {
            in_array($normalizedStatus, ['settled', 'completed', 'cleared', 'posted']) => 'settled',
            in_array($normalizedStatus, ['returned', 'return', 'nsf', 'bounced']) => 'returned',
            in_array($normalizedStatus, ['rejected', 'declined', 'error']) => 'rejected',
            in_array($normalizedStatus, ['failed']) => 'failed',
            in_array($normalizedStatus, ['voided', 'void', 'cancelled', 'canceled']) => 'returned',
            in_array($normalizedStatus, ['pending', 'processing', 'submitted', 'in_progress', 'originated']) => 'processing',
            default => 'processing',
        };
    }

    /**
     * Parse the return reason from a Kotapay API response.
     *
     * @param  array  $response  Raw Kotapay API response
     * @return string Human-readable return reason
     */
    protected function parseReturnReason(array $response): string
    {
        // Try common response paths for return/error reasons
        $reason = $response['data']['returnReason']
            ?? $response['data']['returnReasonCode']
            ?? $response['data']['errorMessage']
            ?? $response['data']['message']
            ?? $response['returnReason']
            ?? $response['message']
            ?? null;

        if ($reason) {
            return (string) $reason;
        }

        return 'No reason provided by payment processor';
    }

    /**
     * Send admin notification about failed/returned ACH payments.
     *
     * @param  array  $failedPayments  Array of ['payment' => Payment, 'reason' => string, 'status' => string]
     */
    protected function sendFailureNotification(array $failedPayments): void
    {
        $admin = new AdminNotifiable;

        if (! $admin->isConfigured()) {
            return;
        }

        try {
            $admin->notify(new class($failedPayments) extends Notification
            {
                public function __construct(
                    public array $failedPayments
                ) {}

                public function via($notifiable): array
                {
                    return ['mail'];
                }

                public function toMail($notifiable): MailMessage
                {
                    $appName = config('app.name');
                    $count = count($this->failedPayments);

                    $mail = (new MailMessage)
                        ->subject("[{$appName}] {$count} ACH Payment(s) Returned/Failed")
                        ->error()
                        ->greeting('ACH Payment Returns Detected')
                        ->line("{$count} ACH payment(s) were returned or rejected by the bank.");

                    foreach ($this->failedPayments as $item) {
                        $payment = $item['payment'];
                        $metadata = $payment->metadata ?? [];
                        $clientName = $metadata['client_name'] ?? ($payment->customer?->name ?? 'Unknown');

                        $mail->line('')
                            ->line("**{$clientName}** - \${$payment->total_amount}")
                            ->line("- Transaction: {$payment->transaction_id}")
                            ->line("- Reason: {$item['reason']}");
                    }

                    return $mail
                        ->line('')
                        ->action('View Payments', route('admin.payments'))
                        ->line('Please review these payments and contact the affected clients.')
                        ->salutation("- {$appName} System");
                }
            });

            $this->info('Admin notification sent for returned ACH payments.');
        } catch (\Exception $e) {
            Log::error('Failed to send ACH return notification', [
                'error' => $e->getMessage(),
            ]);
        }
    }
}
