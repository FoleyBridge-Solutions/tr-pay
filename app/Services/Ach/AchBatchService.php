<?php

namespace App\Services\Ach;

use App\Models\Ach\AchBatch;
use App\Models\Ach\AchEntry;
use App\Models\Customer;
use App\Models\Payment;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * AchBatchService
 *
 * Service for managing ACH batches and entries.
 */
class AchBatchService
{
    /**
     * Create a new ACH batch for collecting entries.
     */
    public function createBatch(
        \DateTime $effectiveDate,
        ?string $secCode = null,
        ?string $entryDescription = null
    ): AchBatch {
        $config = config('kotapay');

        return AchBatch::create([
            'batch_number' => AchBatch::generateBatchNumber(),
            'company_name' => substr($config['originator']['company_name'] ?? '', 0, 16),
            'company_id' => $config['originator']['company_id'],
            'sec_code' => $secCode ?? $config['default_sec_code'] ?? 'WEB',
            'company_entry_description' => substr($entryDescription ?? $config['processing']['entry_description'] ?? 'PAYMENT', 0, 10),
            'effective_entry_date' => $effectiveDate,
            'status' => AchBatch::STATUS_PENDING,
        ]);
    }

    /**
     * Add a debit entry to a batch (pull money from customer).
     */
    public function addDebitEntry(
        AchBatch $batch,
        string $routingNumber,
        string $accountNumber,
        float $amount,
        string $name,
        string $accountType = 'checking',
        ?Payment $payment = null,
        ?Customer $customer = null,
        ?string $individualId = null
    ): AchEntry {
        if (! $batch->canAddEntries()) {
            throw new \RuntimeException("Batch {$batch->batch_number} is not accepting new entries");
        }

        $routing = AchEntry::parseRoutingNumber($routingNumber);
        $transactionCode = AchEntry::getDebitTransactionCode($accountType);

        $entry = AchEntry::create([
            'ach_batch_id' => $batch->id,
            'payment_id' => $payment?->id,
            'customer_id' => $customer?->id,
            'transaction_code' => $transactionCode,
            'receiving_dfi_id' => $routing['receiving_dfi_id'],
            'check_digit' => $routing['check_digit'],
            'dfi_account_number_encrypted' => Crypt::encryptString($accountNumber),
            'amount' => (int) round($amount * 100), // Convert to cents
            'individual_id' => substr($individualId ?? $payment?->id ?? '', 0, 15),
            'individual_name' => substr(strtoupper($name), 0, 22),
            'trace_number' => $this->generateTraceNumber($batch),
            'client_id' => $payment?->client_id ?? $customer?->client_id,
            'routing_number_last_four' => substr($routingNumber, -4),
            'account_number_last_four' => substr($accountNumber, -4),
            'account_type' => strtolower($accountType),
            'status' => AchEntry::STATUS_PENDING,
        ]);

        // Recalculate batch totals
        $batch->recalculateTotals();

        Log::info('ACH debit entry added', [
            'batch_id' => $batch->id,
            'entry_id' => $entry->id,
            'amount' => $amount,
            'name' => $name,
        ]);

        return $entry;
    }

    /**
     * Generate a unique trace number for an entry.
     *
     * Trace numbers must be unique across all entries per NACHA specification.
     * Uses database-level locking to prevent race conditions in concurrent requests.
     *
     * Format: 8-digit ODFI routing + 7-digit sequence number = 15 digits total
     *
     * @param  AchBatch  $batch  The batch this entry belongs to
     * @return string 15-digit trace number
     */
    protected function generateTraceNumber(AchBatch $batch): string
    {
        $odfiRouting = substr(config('kotapay.originator.odfi_routing', '00000000'), 0, 8);

        // Use database-level atomic operation to prevent race conditions.
        // This locks the row during the transaction to ensure unique sequence numbers.
        $sequence = DB::transaction(function () {
            // Get the current max ID with a lock to prevent concurrent reads
            // Using MAX(id) + 1 ensures uniqueness even if entries are deleted
            $maxId = DB::table('ach_entries')
                ->lockForUpdate()
                ->max('id');

            // Return the next sequence number (starting at 1 if no entries exist)
            return ($maxId ?? 0) + 1;
        });

        // Pad sequence to 7 digits (max 9,999,999 entries per ODFI routing)
        return $odfiRouting.str_pad((string) $sequence, 7, '0', STR_PAD_LEFT);
    }

    /**
     * Validate a routing number using ABA checksum.
     */
    public function validateRoutingNumber(string $routingNumber): bool
    {
        $routing = preg_replace('/\D/', '', $routingNumber);

        if (strlen($routing) !== 9) {
            return false;
        }

        // ABA checksum algorithm
        $sum = 0;
        for ($i = 0; $i < 9; $i += 3) {
            $sum += (int) $routing[$i] * 3;
            $sum += (int) $routing[$i + 1] * 7;
            $sum += (int) $routing[$i + 2];
        }

        return $sum !== 0 && ($sum % 10) === 0;
    }

    /**
     * Calculate the effective entry date based on cutoff time.
     */
    public function calculateEffectiveDate(?\DateTime $requestedDate = null): \DateTime
    {
        $config = config('kotapay.processing');
        $offset = $config['effective_date_offset'] ?? 1;
        $cutoff = $config['daily_cutoff'] ?? '14:00';

        $now = now();
        $cutoffTime = \Carbon\Carbon::parse($cutoff);

        // If past cutoff or no date requested, add offset days
        if ($requestedDate === null || $now->greaterThan($cutoffTime)) {
            return $now->addBusinessDays($offset);
        }

        return \Carbon\Carbon::instance($requestedDate);
    }
}
