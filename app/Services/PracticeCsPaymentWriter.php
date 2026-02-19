<?php

// app/Services/PracticeCsPaymentWriter.php

namespace App\Services;

use App\Notifications\PracticeCsWriteFailed;
use FoleyBridgeSolutions\PracticeCsPI\Data\LedgerEntry;
use FoleyBridgeSolutions\PracticeCsPI\Exceptions\PracticeCsException;
use FoleyBridgeSolutions\PracticeCsPI\Services\LedgerService;
use Illuminate\Support\Facades\Log;

/**
 * PracticeCsPaymentWriter
 *
 * Writes payment data to PracticeCS via the practicecs-pi API package.
 * Local payment plan tracking methods operate on Eloquent models directly.
 *
 * CRITICAL: This service WRITES to the PracticeCS database via the API.
 * Only use when payment integration is enabled.
 */
class PracticeCsPaymentWriter
{
    /**
     * The PracticeCS ledger service.
     */
    protected LedgerService $ledgerService;

    /**
     * Create a new PracticeCsPaymentWriter instance.
     *
     * @param LedgerService $ledgerService The PracticeCS ledger API service
     */
    public function __construct(LedgerService $ledgerService)
    {
        $this->ledgerService = $ledgerService;
    }

    /**
     * Write a payment to PracticeCS.
     *
     * @return array ['success' => bool, 'ledger_entry_KEY' => int|null, 'error' => string|null]
     */
    public function writePayment(array $paymentData): array
    {
        // Verify payment integration is enabled
        if (! config('practicecs.payment_integration.enabled')) {
            return [
                'success' => false,
                'error' => 'PracticeCS payment integration is disabled',
            ];
        }

        try {
            $entry = $this->ledgerService->writePayment($paymentData);

            if (! $entry->success) {
                throw new \RuntimeException($entry->error ?? 'Payment write returned unsuccessful');
            }

            Log::info('PracticeCS: Payment written successfully', [
                'ledger_entry_KEY' => $entry->ledgerEntryKey,
                'entry_number' => $entry->entryNumber,
                'client_KEY' => $paymentData['client_KEY'],
                'amount' => $paymentData['amount'],
            ]);

            $result = [
                'success' => true,
                'ledger_entry_KEY' => $entry->ledgerEntryKey,
                'entry_number' => $entry->entryNumber,
            ];

            if ($entry->warning) {
                $result['warning'] = $entry->warning;
            }

            return $result;

        } catch (PracticeCsException $e) {
            Log::error('PracticeCS: Payment write failed', [
                'error' => $e->getMessage(),
                'status_code' => $e->getStatusCode(),
                'response_body' => $e->getResponseBody(),
                'payment_data' => $paymentData,
            ]);

            $this->notifyAdmins($paymentData, $e->getMessage(), 'payment');

            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        } catch (\Exception $e) {
            Log::error('PracticeCS: Payment write failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'payment_data' => $paymentData,
            ]);

            $this->notifyAdmins($paymentData, $e->getMessage(), 'payment');

            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Write a memo (debit or credit) to PracticeCS.
     *
     * @param  string  $memoType  'debit' or 'credit'
     * @return array ['success' => bool, 'ledger_entry_KEY' => int|null, 'error' => string|null]
     */
    public function writeMemo(array $memoData, string $memoType): array
    {
        // Verify payment integration is enabled
        if (! config('practicecs.payment_integration.enabled')) {
            return [
                'success' => false,
                'error' => 'PracticeCS payment integration is disabled',
            ];
        }

        try {
            $entry = $this->ledgerService->writeMemo($memoData, $memoType);

            if (! $entry->success) {
                throw new \RuntimeException($entry->error ?? 'Memo write returned unsuccessful');
            }

            Log::info("PracticeCS: {$memoType} memo written successfully", [
                'ledger_entry_KEY' => $entry->ledgerEntryKey,
                'entry_number' => $entry->entryNumber,
                'client_KEY' => $memoData['client_KEY'],
                'amount' => $memoData['amount'],
            ]);

            $result = [
                'success' => true,
                'ledger_entry_KEY' => $entry->ledgerEntryKey,
                'entry_number' => $entry->entryNumber,
            ];

            if ($entry->warning) {
                $result['warning'] = $entry->warning;
            }

            return $result;

        } catch (PracticeCsException $e) {
            Log::error("PracticeCS: {$memoType} memo write failed", [
                'error' => $e->getMessage(),
                'status_code' => $e->getStatusCode(),
                'response_body' => $e->getResponseBody(),
                'memo_data' => $memoData,
            ]);

            $this->notifyAdmins($memoData, $e->getMessage(), "memo_{$memoType}");

            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        } catch (\Exception $e) {
            Log::error("PracticeCS: {$memoType} memo write failed", [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'memo_data' => $memoData,
            ]);

            $this->notifyAdmins($memoData, $e->getMessage(), "memo_{$memoType}");

            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Write a deferred payment to PracticeCS from a stored payload.
     *
     * This method handles both simple payments and payments with client group
     * distribution (credit/debit memos). It is used for:
     * - Immediate card payment writes (called from writeToPracticeCs at payment time)
     * - Deferred ACH payment writes (called from CheckAchPaymentStatus on settlement)
     *
     * The LedgerService API handles group distribution internally when the
     * 'group_distribution' key is present in the deferred data.
     *
     * @param  array  $deferredData  Structured payload with 'payment' key and optional 'group_distribution'
     * @return array ['success' => bool, 'ledger_entry_KEY' => int|null, 'error' => string|null]
     */
    public function writeDeferredPayment(array $deferredData): array
    {
        // Verify payment integration is enabled
        if (! config('practicecs.payment_integration.enabled')) {
            return [
                'success' => false,
                'error' => 'PracticeCS payment integration is disabled',
            ];
        }

        $paymentData = $deferredData['payment'] ?? null;

        if (! $paymentData) {
            return [
                'success' => false,
                'error' => 'No payment data in deferred payload',
            ];
        }

        try {
            $entry = $this->ledgerService->writeDeferredPayment($deferredData);

            if (! $entry->success) {
                throw new \RuntimeException($entry->error ?? 'Deferred payment write returned unsuccessful');
            }

            Log::info('PracticeCS: Deferred payment written successfully', [
                'ledger_entry_KEY' => $entry->ledgerEntryKey,
                'entry_number' => $entry->entryNumber,
                'client_KEY' => $paymentData['client_KEY'],
                'amount' => $paymentData['amount'],
                'has_group_distribution' => ! empty($deferredData['group_distribution']),
            ]);

            $result = [
                'success' => true,
                'ledger_entry_KEY' => $entry->ledgerEntryKey,
                'entry_number' => $entry->entryNumber,
            ];

            if ($entry->warning) {
                $result['warning'] = $entry->warning;
            }

            return $result;

        } catch (PracticeCsException $e) {
            Log::error('PracticeCS: Deferred payment write failed', [
                'error' => $e->getMessage(),
                'status_code' => $e->getStatusCode(),
                'response_body' => $e->getResponseBody(),
                'payment_data' => $paymentData,
            ]);

            $this->notifyAdmins($paymentData, $e->getMessage(), 'deferred_payment');

            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        } catch (\Exception $e) {
            Log::error('PracticeCS: Deferred payment write failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'payment_data' => $paymentData,
            ]);

            $this->notifyAdmins($paymentData, $e->getMessage(), 'deferred_payment');

            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Send admin notification for a failed PracticeCS write.
     *
     * @param  array  $data  Payment or memo data containing reference, client_KEY, amount
     * @param  string  $errorMessage  The error message
     * @param  string  $writeType  The type of write operation (payment, memo_credit, memo_debit, deferred_payment)
     */
    protected function notifyAdmins(array $data, string $errorMessage, string $writeType): void
    {
        try {
            AdminAlertService::notifyAll(new PracticeCsWriteFailed(
                $data['reference'] ?? 'unknown',
                (string) ($data['client_KEY'] ?? 'unknown'),
                (float) ($data['amount'] ?? 0),
                $errorMessage,
                $writeType
            ));
        } catch (\Exception $notifyEx) {
            Log::warning('Failed to send admin notification', ['error' => $notifyEx->getMessage()]);
        }
    }

    /**
     * Allocate a payment amount sequentially across invoices.
     *
     * Fills each invoice in order, accounting for amounts already applied
     * (settled) and pending (ACH awaiting settlement). Returns the invoice
     * allocations and the incremental amounts to add to pending/applied tracking.
     *
     * @param  array  $invoices  Invoice data with ledger_entry_KEY and open_amount
     * @param  float  $amount  Payment amount to allocate
     * @param  array  $applied  Settled amounts by ledger_entry_KEY
     * @param  array  $pending  Pending ACH amounts by ledger_entry_KEY
     * @return array{allocations: array, increments: array} allocations for PracticeCS payload, increments to add to tracking
     */
    public static function allocateToInvoices(array $invoices, float $amount, array $applied, array $pending): array
    {
        $allocations = [];
        $increments = [];
        $remaining = $amount;

        foreach ($invoices as $invoice) {
            if ($remaining <= 0.01) {
                break;
            }

            $key = $invoice['ledger_entry_KEY'] ?? null;
            if (! $key) {
                continue;
            }

            $openAmount = (float) ($invoice['open_amount'] ?? 0);
            $alreadyApplied = (float) ($applied[$key] ?? 0);
            $alreadyPending = (float) ($pending[$key] ?? 0);
            $invoiceRemaining = $openAmount - $alreadyApplied - $alreadyPending;

            if ($invoiceRemaining <= 0.01) {
                continue;
            }

            $applyAmount = round(min($remaining, $invoiceRemaining), 2);
            $allocations[] = [
                'ledger_entry_KEY' => $key,
                'amount' => $applyAmount,
            ];
            $increments[$key] = $applyAmount;
            $remaining = round($remaining - $applyAmount, 2);
        }

        return [
            'allocations' => $allocations,
            'increments' => $increments,
        ];
    }

    /**
     * Update payment plan metadata tracking after a PracticeCS write or deferral.
     *
     * @param  \App\Models\PaymentPlan  $paymentPlan  The payment plan to update
     * @param  array  $increments  Amounts to add, keyed by ledger_entry_KEY
     * @param  string  $trackingKey  'practicecs_applied' or 'practicecs_pending'
     */
    public static function updatePlanTracking(\App\Models\PaymentPlan $paymentPlan, array $increments, string $trackingKey): void
    {
        $metadata = $paymentPlan->metadata ?? [];
        $tracking = $metadata[$trackingKey] ?? [];

        foreach ($increments as $ledgerKey => $amount) {
            $tracking[$ledgerKey] = round((float) ($tracking[$ledgerKey] ?? 0) + $amount, 2);
        }

        $metadata[$trackingKey] = $tracking;
        $paymentPlan->metadata = $metadata;
        $paymentPlan->save();
    }

    /**
     * Move amounts from practicecs_pending to practicecs_applied in plan metadata.
     *
     * Called when an ACH payment settles and is written to PracticeCS.
     *
     * @param  \App\Models\PaymentPlan  $paymentPlan  The payment plan
     * @param  array  $increments  Amounts to move, keyed by ledger_entry_KEY
     */
    public static function settlePlanTracking(\App\Models\PaymentPlan $paymentPlan, array $increments): void
    {
        $metadata = $paymentPlan->metadata ?? [];
        $applied = $metadata['practicecs_applied'] ?? [];
        $pending = $metadata['practicecs_pending'] ?? [];

        foreach ($increments as $ledgerKey => $amount) {
            $applied[$ledgerKey] = round((float) ($applied[$ledgerKey] ?? 0) + $amount, 2);
            $pending[$ledgerKey] = round(max(0, (float) ($pending[$ledgerKey] ?? 0) - $amount), 2);
        }

        $metadata['practicecs_applied'] = $applied;
        $metadata['practicecs_pending'] = $pending;
        $paymentPlan->metadata = $metadata;
        $paymentPlan->save();
    }

    /**
     * Remove amounts from practicecs_pending in plan metadata.
     *
     * Called when an ACH payment is returned/rejected — the allocation
     * is freed so future payments can fill those invoices.
     *
     * @param  \App\Models\PaymentPlan  $paymentPlan  The payment plan
     * @param  array  $increments  Amounts to remove, keyed by ledger_entry_KEY
     */
    public static function revertPlanTracking(\App\Models\PaymentPlan $paymentPlan, array $increments): void
    {
        $metadata = $paymentPlan->metadata ?? [];
        $pending = $metadata['practicecs_pending'] ?? [];

        foreach ($increments as $ledgerKey => $amount) {
            $pending[$ledgerKey] = round(max(0, (float) ($pending[$ledgerKey] ?? 0) - $amount), 2);
        }

        $metadata['practicecs_pending'] = $pending;
        $paymentPlan->metadata = $metadata;
        $paymentPlan->save();
    }
}
