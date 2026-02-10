<?php

namespace App\Services\Ach;

use App\Models\Ach\AchBatch;
use App\Models\Ach\AchEntry;
use App\Models\Ach\AchFile;
use App\Models\Customer;
use App\Models\Payment;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

require_once app_path('Libraries/NachaEngine.php');

/**
 * AchFileService
 *
 * Service for generating NACHA files and managing ACH batches.
 * Wraps the NachaEngine library with Laravel integration.
 */
class AchFileService
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
     * Mark a batch as ready and generate the NACHA file.
     *
     * @param  AchBatch  $batch  The batch to generate a file for
     * @return AchFile The generated ACH file
     *
     * @throws \RuntimeException If batch has no entries
     */
    public function generateFile(AchBatch $batch): AchFile
    {
        if ($batch->entries()->count() === 0) {
            throw new \RuntimeException("Batch {$batch->batch_number} has no entries");
        }

        $batch->markAsReady();

        // Eager load entries to prevent N+1 queries in buildNachaContent
        $batch->load('entries');

        $config = config('kotapay');

        // Create the AchFile record
        $achFile = AchFile::create([
            'filename' => AchFile::generateFilename($config['originator']['company_id']),
            'file_id_modifier' => AchFile::getNextModifier(),
            'immediate_destination' => $config['originator']['immediate_destination'],
            'immediate_origin' => $config['originator']['immediate_origin'],
            'immediate_destination_name' => substr($config['originator']['immediate_destination_name'] ?? 'KOTAPAY', 0, 23),
            'immediate_origin_name' => substr($config['originator']['immediate_origin_name'] ?? '', 0, 23),
            'file_creation_date' => now(),
            'file_creation_time' => now()->format('Hi'),
            'status' => AchFile::STATUS_PENDING,
        ]);

        // Link batch to file
        $batch->update(['ach_file_id' => $achFile->id]);

        // Generate the actual NACHA content
        $nachaContent = $this->buildNachaContent($achFile, [$batch]);

        // Update file with content and totals
        $achFile->update([
            'file_contents' => $nachaContent,
            'file_hash' => hash('sha256', $nachaContent),
            'batch_count' => 1,
            'entry_addenda_count' => $batch->entry_count,
            'total_debit_amount' => $batch->total_debit_amount,
            'total_credit_amount' => $batch->total_credit_amount,
            'status' => AchFile::STATUS_GENERATED,
            'generated_at' => now(),
        ]);

        // Update batch status
        $batch->update([
            'status' => AchBatch::STATUS_GENERATED,
            'generated_at' => now(),
        ]);

        // Store the file
        $this->storeFile($achFile);

        Log::info('NACHA file generated', [
            'file_id' => $achFile->id,
            'filename' => $achFile->filename,
            'batch_count' => 1,
            'entry_count' => $batch->entry_count,
            'total_debit' => $batch->total_debit_dollars,
        ]);

        return $achFile;
    }

    /**
     * Generate NACHA file for multiple batches.
     *
     * @param  array<AchBatch>  $batches  Array of AchBatch models
     * @return AchFile The generated ACH file
     *
     * @throws \RuntimeException If no batches provided or generation fails
     */
    public function generateFileForBatches(array $batches): AchFile
    {
        if (empty($batches)) {
            throw new \RuntimeException('No batches provided');
        }

        $config = config('kotapay');

        // Ensure batches have entries eager-loaded to prevent N+1 queries
        // If batches were passed without entries loaded, load them now
        $batchIds = array_map(fn ($b) => $b->id, $batches);
        $batches = AchBatch::with('entries')->whereIn('id', $batchIds)->get()->all();

        // Create the AchFile record
        $achFile = AchFile::create([
            'filename' => AchFile::generateFilename($config['originator']['company_id']),
            'file_id_modifier' => AchFile::getNextModifier(),
            'immediate_destination' => $config['originator']['immediate_destination'],
            'immediate_origin' => $config['originator']['immediate_origin'],
            'immediate_destination_name' => substr($config['originator']['immediate_destination_name'] ?? 'KOTAPAY', 0, 23),
            'immediate_origin_name' => substr($config['originator']['immediate_origin_name'] ?? '', 0, 23),
            'file_creation_date' => now(),
            'file_creation_time' => now()->format('Hi'),
            'status' => AchFile::STATUS_PENDING,
        ]);

        $totalDebit = 0;
        $totalCredit = 0;
        $totalEntries = 0;

        foreach ($batches as $batch) {
            $batch->markAsReady();
            $batch->update(['ach_file_id' => $achFile->id]);
            $totalDebit += $batch->total_debit_amount;
            $totalCredit += $batch->total_credit_amount;
            $totalEntries += $batch->entry_count;
        }

        // Generate the actual NACHA content (batches already have entries loaded)
        $nachaContent = $this->buildNachaContent($achFile, $batches);

        // Update file with content and totals
        $achFile->update([
            'file_contents' => $nachaContent,
            'file_hash' => hash('sha256', $nachaContent),
            'batch_count' => count($batches),
            'entry_addenda_count' => $totalEntries,
            'total_debit_amount' => $totalDebit,
            'total_credit_amount' => $totalCredit,
            'status' => AchFile::STATUS_GENERATED,
            'generated_at' => now(),
        ]);

        // Update batch statuses
        foreach ($batches as $batch) {
            $batch->update([
                'status' => AchBatch::STATUS_GENERATED,
                'generated_at' => now(),
            ]);
        }

        // Store the file
        $this->storeFile($achFile);

        return $achFile;
    }

    /**
     * Build the actual NACHA file content using the NachaEngine.
     *
     * Per Kotapay requirements, each batch must contain its own offset entry
     * rather than having a separate consolidated offset batch at the end.
     *
     * @param  AchFile  $achFile  The ACH file record
     * @param  array<AchBatch>  $batches  Array of batches (should be eager-loaded with entries)
     * @return string The NACHA file content
     *
     * @throws \RuntimeException If NACHA generation fails
     */
    protected function buildNachaContent(AchFile $achFile, array $batches): string
    {
        $config = config('kotapay');

        // Initialize the NACHA file generator
        // balanced_file = false: We will manually add offset entries within each batch
        $nacha = new \nacha_file(
            $config['originator']['immediate_origin'],           // origin_id
            $config['originator']['company_id'],                 // company_id
            $config['originator']['company_name'],               // company_name
            $config['originator']['settlement_routing'],         // settlement_routing_number
            $config['originator']['settlement_account'],         // settlement_account_number
            $config['originator']['immediate_destination_name'], // originating_bank_name
            false,                                               // balanced_file = false (we add offset per batch)
            false,                                               // settlement_is_savings
            $achFile->file_id_modifier                           // file_modifier
        );

        if (! empty($nacha->errors)) {
            throw new \RuntimeException('NACHA initialization failed: '.implode(', ', $nacha->errors));
        }

        // Collect all entries from all batches and group by personal vs business
        // Note: Entries should be eager-loaded to avoid N+1 queries
        $personalEntries = [];
        $businessEntries = [];

        foreach ($batches as $batch) {
            // Access entries via the already-loaded relationship (eager-loaded)
            // If not eager-loaded, this will trigger a query per batch (N+1)
            // Callers should use: AchBatch::with('entries')->whereIn(...)->get()
            foreach ($batch->entries as $entry) {
                if ($entry->is_business) {
                    $businessEntries[] = $entry;
                } else {
                    $personalEntries[] = $entry;
                }
            }
        }

        // Process personal entries (PPD batch) - PPDBILLING with in-batch offset
        if (! empty($personalEntries)) {
            $ppdTotal = 0;

            foreach ($personalEntries as $index => $entry) {
                $accountNumber = $entry->account_number; // Decrypted via accessor
                $isSavings = $entry->account_type === 'savings';
                $isFirstEntry = ($index === 0);

                if ($entry->isDebit()) {
                    $nacha->create_debit_entry(
                        $entry->amount / 100,                    // amount in dollars
                        $entry->individual_name,                 // name
                        $entry->full_routing_number,             // routing
                        $accountNumber,                          // account
                        'PPDBILLING',                            // memo
                        $entry->individual_id ?? $entry->id,     // internal_id
                        $isFirstEntry,                           // create_new_batch
                        $isSavings,                              // savings_account
                        true                                     // personal_payment = true for PPD
                    );
                    $ppdTotal += $entry->amount / 100;
                } else {
                    $nacha->create_credit_entry(
                        $entry->amount / 100,
                        $entry->individual_name,
                        $entry->full_routing_number,
                        $accountNumber,
                        'PPDBILLING',
                        $entry->individual_id ?? $entry->id,
                        $isFirstEntry,
                        $isSavings,
                        true                                     // personal_payment = true for PPD
                    );
                    $ppdTotal -= $entry->amount / 100;
                }
            }

            // Add offset entry within the PPD batch (credit to settlement account)
            if ($ppdTotal > 0) {
                $nacha->create_credit_entry(
                    $ppdTotal,                                   // amount to offset
                    $config['originator']['company_name'],       // name
                    $config['originator']['settlement_routing'], // routing
                    $config['originator']['settlement_account'], // account
                    'PPDBILLING',                                // memo (same as batch)
                    'OFFSET',                                    // internal_id
                    false,                                       // create_new_batch = false (same batch)
                    false,                                       // savings_account = false (checking)
                    true                                         // personal_payment = true for PPD
                );
            } elseif ($ppdTotal < 0) {
                $nacha->create_debit_entry(
                    abs($ppdTotal),
                    $config['originator']['company_name'],
                    $config['originator']['settlement_routing'],
                    $config['originator']['settlement_account'],
                    'PPDBILLING',
                    'OFFSET',
                    false,
                    false,
                    true
                );
            }
        }

        // Process business entries (CCD batch) - CCDBILLING with in-batch offset
        if (! empty($businessEntries)) {
            $ccdTotal = 0;

            foreach ($businessEntries as $index => $entry) {
                $accountNumber = $entry->account_number; // Decrypted via accessor
                $isSavings = $entry->account_type === 'savings';
                $isFirstEntry = ($index === 0);

                if ($entry->isDebit()) {
                    $nacha->create_debit_entry(
                        $entry->amount / 100,                    // amount in dollars
                        $entry->individual_name,                 // name
                        $entry->full_routing_number,             // routing
                        $accountNumber,                          // account
                        'CCDBILLING',                            // memo
                        $entry->individual_id ?? $entry->id,     // internal_id
                        $isFirstEntry,                           // create_new_batch
                        $isSavings,                              // savings_account
                        false                                    // personal_payment = false for CCD
                    );
                    $ccdTotal += $entry->amount / 100;
                } else {
                    $nacha->create_credit_entry(
                        $entry->amount / 100,
                        $entry->individual_name,
                        $entry->full_routing_number,
                        $accountNumber,
                        'CCDBILLING',
                        $entry->individual_id ?? $entry->id,
                        $isFirstEntry,
                        $isSavings,
                        false                                    // personal_payment = false for CCD
                    );
                    $ccdTotal -= $entry->amount / 100;
                }
            }

            // Add offset entry within the CCD batch (credit to settlement account)
            if ($ccdTotal > 0) {
                $nacha->create_credit_entry(
                    $ccdTotal,                                   // amount to offset
                    $config['originator']['company_name'],       // name
                    $config['originator']['settlement_routing'], // routing
                    $config['originator']['settlement_account'], // account
                    'CCDBILLING',                                // memo (same as batch)
                    'OFFSET',                                    // internal_id
                    false,                                       // create_new_batch = false (same batch)
                    false,                                       // savings_account = false (checking)
                    false                                        // personal_payment = false for CCD
                );
            } elseif ($ccdTotal < 0) {
                $nacha->create_debit_entry(
                    abs($ccdTotal),
                    $config['originator']['company_name'],
                    $config['originator']['settlement_routing'],
                    $config['originator']['settlement_account'],
                    'CCDBILLING',
                    'OFFSET',
                    false,
                    false,
                    false
                );
            }
        }

        if (! empty($nacha->errors)) {
            throw new \RuntimeException('NACHA generation failed: '.implode(', ', $nacha->errors));
        }

        $content = $nacha->get_file_string();

        if ($content === false) {
            throw new \RuntimeException('Failed to generate NACHA file content');
        }

        return $content;
    }

    /**
     * Store the generated file to disk.
     */
    protected function storeFile(AchFile $achFile): void
    {
        $disk = config('kotapay.storage.disk', 'local');
        $path = config('kotapay.storage.path', 'ach-files');

        Storage::disk($disk)->put(
            "{$path}/{$achFile->filename}",
            $achFile->file_contents
        );

        Log::info('ACH file stored', [
            'file_id' => $achFile->id,
            'path' => "{$path}/{$achFile->filename}",
        ]);
    }

    /**
     * Get the file contents for download.
     */
    public function getFileContents(AchFile $achFile): string
    {
        if ($achFile->file_contents) {
            return $achFile->file_contents;
        }

        $disk = config('kotapay.storage.disk', 'local');
        $path = config('kotapay.storage.path', 'ach-files');

        return Storage::disk($disk)->get("{$path}/{$achFile->filename}");
    }

    /**
     * Get the full path to a stored file.
     */
    public function getFilePath(AchFile $achFile): string
    {
        $disk = config('kotapay.storage.disk', 'local');
        $path = config('kotapay.storage.path', 'ach-files');

        return Storage::disk($disk)->path("{$path}/{$achFile->filename}");
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
