<?php

// app/Services/PracticeCsPaymentWriter.php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * PracticeCsPaymentWriter
 *
 * Writes payment data to PracticeCS SQL Server database
 *
 * CRITICAL: This service WRITES to the PracticeCS database
 * Only use when payment integration is enabled
 */
class PracticeCsPaymentWriter
{
    /**
     * Write a payment to PracticeCS
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

        $connection = config('practicecs.payment_integration.connection', 'sqlsrv');

        try {
            DB::connection($connection)->beginTransaction();

            // Step 1: Generate next primary key (with locking)
            $nextLedgerKey = DB::connection($connection)->selectOne(
                'SELECT ISNULL(MAX(ledger_entry_KEY), 0) + 1 AS next_key FROM Ledger_Entry WITH (TABLOCKX)'
            )->next_key;

            // Step 2: Generate next entry number
            $nextEntryNumber = DB::connection($connection)->selectOne(
                'SELECT ISNULL(MAX(entry_number), 0) + 1 AS next_num FROM Ledger_Entry'
            )->next_num;

            // Step 3: Validate foreign keys exist
            $this->validateForeignKeys($connection, $paymentData);

            // Step 4: Insert Ledger_Entry
            $entryDate = now()->startOfDay(); // Must be midnight per CHECK constraint

            // Truncate reference to 30 chars (nvarchar(30) column limit)
            // Full transaction ID is preserved in internal_comments (nvarchar(MAX))
            $reference = Str::limit($paymentData['reference'], 30, '');

            DB::connection($connection)->insert('
                INSERT INTO Ledger_Entry (
                    ledger_entry_KEY,
                    update__staff_KEY,
                    update_date_utc,
                    bank_account_KEY,
                    control_date,
                    ledger_entry_type_KEY,
                    client_KEY,
                    entry_date,
                    reference,
                    amount,
                    comments,
                    internal_comments,
                    approved_date,
                    approved__staff_KEY,
                    posted_date,
                    posted__staff_KEY,
                    entry_number,
                    create_date_utc,
                    ledger_entry_subtype_KEY
                )
                VALUES (?, ?, GETUTCDATE(), ?, ?, ?, ?, ?, ?, ?, ?, ?, CAST(GETDATE() AS DATE), ?, CAST(GETDATE() AS DATE), ?, ?, GETUTCDATE(), ?)
            ', [
                $nextLedgerKey,
                $paymentData['staff_KEY'],
                $paymentData['bank_account_KEY'],
                $entryDate,
                $paymentData['ledger_type_KEY'],
                $paymentData['client_KEY'],
                $entryDate,
                $reference,
                -abs($paymentData['amount']), // MUST be negative
                $paymentData['comments'],
                $paymentData['internal_comments'],
                $paymentData['staff_KEY'],
                $paymentData['staff_KEY'],
                $nextEntryNumber,
                $paymentData['subtype_KEY'],
            ]);

            Log::info('PracticeCS: Ledger_Entry created', [
                'ledger_entry_KEY' => $nextLedgerKey,
                'entry_number' => $nextEntryNumber,
                'reference' => $paymentData['reference'],
            ]);

            // Step 5: Apply payment to invoices
            if (! empty($paymentData['invoices'])) {
                $this->applyPaymentToInvoices(
                    $connection,
                    $nextLedgerKey,
                    $paymentData['invoices'],
                    $paymentData['staff_KEY']
                );
            }

            // Step 6: (Optional) Record in Online_Payment table
            if (config('practicecs.payment_integration.track_online_payments')) {
                $this->recordOnlinePayment($connection, $nextLedgerKey, $paymentData);
            }

            // Step 7: Commit transaction
            DB::connection($connection)->commit();

            Log::info('PracticeCS: Payment written successfully', [
                'ledger_entry_KEY' => $nextLedgerKey,
                'client_KEY' => $paymentData['client_KEY'],
                'amount' => $paymentData['amount'],
            ]);

            return [
                'success' => true,
                'ledger_entry_KEY' => $nextLedgerKey,
                'entry_number' => $nextEntryNumber,
            ];

        } catch (\Exception $e) {
            if (DB::connection($connection)->transactionLevel() > 0) {
                DB::connection($connection)->rollBack();
            }

            Log::error('PracticeCS: Payment write failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'payment_data' => $paymentData,
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Validate that all foreign keys exist
     */
    protected function validateForeignKeys(string $connection, array $paymentData): void
    {
        // Validate client
        $client = DB::connection($connection)->selectOne(
            'SELECT 1 AS found FROM Client WHERE client_KEY = ?',
            [$paymentData['client_KEY']]
        );
        if (! $client) {
            throw new \Exception("Client not found: {$paymentData['client_KEY']}");
        }

        // Validate staff
        $staff = DB::connection($connection)->selectOne(
            'SELECT 1 AS found FROM Staff WHERE staff_KEY = ?',
            [$paymentData['staff_KEY']]
        );
        if (! $staff) {
            throw new \Exception("Staff not found: {$paymentData['staff_KEY']}");
        }

        // Validate bank account
        $bankAccount = DB::connection($connection)->selectOne(
            'SELECT 1 AS found FROM Bank_Account WHERE bank_account_KEY = ?',
            [$paymentData['bank_account_KEY']]
        );
        if (! $bankAccount) {
            throw new \Exception("Bank account not found: {$paymentData['bank_account_KEY']}");
        }

        // Validate ledger type
        $ledgerType = DB::connection($connection)->selectOne(
            'SELECT 1 AS found FROM Ledger_Entry_Type WHERE ledger_entry_type_KEY = ?',
            [$paymentData['ledger_type_KEY']]
        );
        if (! $ledgerType) {
            throw new \Exception("Ledger type not found: {$paymentData['ledger_type_KEY']}");
        }

        // Validate subtype (only if provided - memos may not have subtypes)
        if (! empty($paymentData['subtype_KEY'])) {
            $subtype = DB::connection($connection)->selectOne(
                'SELECT 1 AS found FROM Ledger_Entry_Subtype WHERE ledger_entry_subtype_KEY = ?',
                [$paymentData['subtype_KEY']]
            );
            if (! $subtype) {
                throw new \Exception("Ledger subtype not found: {$paymentData['subtype_KEY']}");
            }
        }
    }

    /**
     * Write a memo (debit or credit) to PracticeCS
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

        $connection = config('practicecs.payment_integration.connection', 'sqlsrv');

        // Get memo type KEY from config
        $ledgerTypeKey = config("practicecs.payment_integration.memo_types.{$memoType}");
        if (! $ledgerTypeKey) {
            return [
                'success' => false,
                'error' => "Invalid memo type: {$memoType}",
            ];
        }

        try {
            DB::connection($connection)->beginTransaction();

            // Generate next keys
            $nextLedgerKey = DB::connection($connection)->selectOne(
                'SELECT ISNULL(MAX(ledger_entry_KEY), 0) + 1 AS next_key FROM Ledger_Entry WITH (TABLOCKX)'
            )->next_key;

            $nextEntryNumber = DB::connection($connection)->selectOne(
                'SELECT ISNULL(MAX(entry_number), 0) + 1 AS next_num FROM Ledger_Entry'
            )->next_num;

            // Validate foreign keys
            $this->validateForeignKeys($connection, $memoData);

            // Insert memo entry
            $entryDate = now()->startOfDay();

            // Truncate reference to 30 chars (nvarchar(30) column limit)
            // Full transaction ID is preserved in internal_comments (nvarchar(MAX))
            $reference = Str::limit($memoData['reference'], 30, '');

            // Memos: amount should be positive in parameter, sign determined by ledger type
            // Debit Memo (type 3): positive amount (increases AR)
            // Credit Memo (type 5): negative amount (decreases AR)
            $amount = $memoType === 'debit'
                ? abs($memoData['amount'])   // Debit: positive
                : -abs($memoData['amount']);  // Credit: negative

            DB::connection($connection)->insert('
                INSERT INTO Ledger_Entry (
                    ledger_entry_KEY,
                    update__staff_KEY,
                    update_date_utc,
                    bank_account_KEY,
                    control_date,
                    ledger_entry_type_KEY,
                    client_KEY,
                    entry_date,
                    reference,
                    amount,
                    comments,
                    internal_comments,
                    approved_date,
                    approved__staff_KEY,
                    posted_date,
                    posted__staff_KEY,
                    entry_number,
                    create_date_utc,
                    ledger_entry_subtype_KEY
                )
                VALUES (?, ?, GETUTCDATE(), ?, ?, ?, ?, ?, ?, ?, ?, ?, CAST(GETDATE() AS DATE), ?, CAST(GETDATE() AS DATE), ?, ?, GETUTCDATE(), ?)
            ', [
                $nextLedgerKey,
                $memoData['staff_KEY'],
                $memoData['bank_account_KEY'],
                $entryDate,
                $ledgerTypeKey,
                $memoData['client_KEY'],
                $entryDate,
                $reference,
                $amount,
                $memoData['comments'],
                $memoData['internal_comments'],
                $memoData['staff_KEY'],
                $memoData['staff_KEY'],
                $nextEntryNumber,
                $memoData['subtype_KEY'] ?? null,
            ]);

            Log::info("PracticeCS: {$memoType} memo created", [
                'ledger_entry_KEY' => $nextLedgerKey,
                'entry_number' => $nextEntryNumber,
                'reference' => $memoData['reference'],
                'client_KEY' => $memoData['client_KEY'],
                'amount' => $amount,
            ]);

            // Apply memo to invoices if provided
            if (! empty($memoData['invoices'])) {
                $this->applyPaymentToInvoices(
                    $connection,
                    $nextLedgerKey,
                    $memoData['invoices'],
                    $memoData['staff_KEY']
                );
            }

            DB::connection($connection)->commit();

            Log::info("PracticeCS: {$memoType} memo written successfully", [
                'ledger_entry_KEY' => $nextLedgerKey,
                'client_KEY' => $memoData['client_KEY'],
                'amount' => $memoData['amount'],
            ]);

            return [
                'success' => true,
                'ledger_entry_KEY' => $nextLedgerKey,
                'entry_number' => $nextEntryNumber,
            ];

        } catch (\Exception $e) {
            if (DB::connection($connection)->transactionLevel() > 0) {
                DB::connection($connection)->rollBack();
            }

            Log::error("PracticeCS: {$memoType} memo write failed", [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'memo_data' => $memoData,
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Apply payment to invoices
     *
     * @param  array  $invoices  [['ledger_entry_KEY' => int, 'amount' => float], ...]
     */
    protected function applyPaymentToInvoices(string $connection, int $paymentLedgerKey, array $invoices, int $staffKey): void
    {
        foreach ($invoices as $invoice) {
            // Verify invoice exists and is posted, get its type and total amount
            $invoiceEntry = DB::connection($connection)->selectOne(
                'SELECT ledger_entry_KEY, ledger_entry_type_KEY, amount 
                 FROM Ledger_Entry 
                 WHERE ledger_entry_KEY = ? AND posted__staff_KEY IS NOT NULL',
                [$invoice['ledger_entry_KEY']]
            );

            if (! $invoiceEntry) {
                throw new \Exception("Invoice not found or not posted: {$invoice['ledger_entry_KEY']}");
            }

            // CRITICAL: from = INVOICE, to = PAYMENT
            DB::connection($connection)->insert('
                INSERT INTO Ledger_Entry_Application (
                    update__staff_KEY,
                    update_date_utc,
                    from__ledger_entry_KEY,
                    to__ledger_entry_KEY,
                    applied_amount,
                    create_date_utc
                )
                VALUES (?, GETUTCDATE(), ?, ?, ?, GETUTCDATE())
            ', [
                $staffKey,
                $invoice['ledger_entry_KEY'], // FROM = Invoice
                $paymentLedgerKey, // TO = Payment
                $invoice['amount'],
            ]);

            Log::info('PracticeCS: Payment applied to invoice', [
                'payment_ledger_KEY' => $paymentLedgerKey,
                'invoice_ledger_KEY' => $invoice['ledger_entry_KEY'],
                'amount' => $invoice['amount'],
            ]);

            // Insert Billing_Decision_Collection entries for invoices (type 1) only
            // Debit memos (type 3) applied TO payments don't need BDC entries
            if ($invoiceEntry->ledger_entry_type_KEY == 1) {
                $this->insertBillingDecisionCollection(
                    $connection,
                    $paymentLedgerKey,
                    $invoice['ledger_entry_KEY'],
                    $invoice['amount'],
                    abs($invoiceEntry->amount), // Invoice total (positive)
                    $staffKey
                );
            }
        }
    }

    /**
     * Insert Billing_Decision_Collection entries for a payment applied to an invoice
     *
     * This is CRITICAL for PracticeCS to recognize the invoice as paid.
     * The BDC table links the payment to the individual time/expense entries
     * (sheet_entry_KEY) that make up the invoice.
     *
     * @param  int  $collectionSourceKey  The payment or credit memo ledger_entry_KEY
     * @param  int  $invoiceKey  The invoice ledger_entry_KEY being paid
     * @param  float  $appliedAmount  Amount being applied to this invoice
     * @param  float  $invoiceTotal  Total invoice amount (for proportional distribution)
     * @param  int  $staffKey  Staff KEY for audit trail
     */
    protected function insertBillingDecisionCollection(
        string $connection,
        int $collectionSourceKey,
        int $invoiceKey,
        float $appliedAmount,
        float $invoiceTotal,
        int $staffKey
    ): void {
        // Get all Billing_Decision entries for this invoice
        $billingDecisions = DB::connection($connection)->select(
            'SELECT sheet_entry_KEY, invoiced, bill_amount, surcharge, discount, sales_tax, service_tax
             FROM Billing_Decision 
             WHERE ledger_entry_KEY = ?',
            [$invoiceKey]
        );

        if (empty($billingDecisions)) {
            Log::warning('PracticeCS: No Billing_Decision entries found for invoice', [
                'invoice_KEY' => $invoiceKey,
            ]);

            return;
        }

        // Calculate the payment ratio (for partial payments)
        $ratio = $invoiceTotal > 0 ? $appliedAmount / $invoiceTotal : 1.0;

        Log::info('PracticeCS: Inserting BDC entries', [
            'collection_source_KEY' => $collectionSourceKey,
            'invoice_KEY' => $invoiceKey,
            'applied_amount' => $appliedAmount,
            'invoice_total' => $invoiceTotal,
            'ratio' => $ratio,
            'billing_decision_count' => count($billingDecisions),
        ]);

        foreach ($billingDecisions as $bd) {
            // Calculate proportional amounts
            // For simple cases (no discounts/surcharges): bill_amount_collected = collected
            $billAmountCollected = round($bd->invoiced * $ratio, 2);
            $surchargeCollected = round(($bd->surcharge ?? 0) * $ratio, 2);
            $discountCollected = round(($bd->discount ?? 0) * $ratio, 2);
            $salesTaxCollected = round(($bd->sales_tax ?? 0) * $ratio, 2);
            $serviceTaxCollected = round(($bd->service_tax ?? 0) * $ratio, 2);

            // collected = bill_amount + surcharge - discount + sales_tax + service_tax
            $collected = $billAmountCollected + $surchargeCollected - $discountCollected
                       + $salesTaxCollected + $serviceTaxCollected;

            DB::connection($connection)->insert('
                INSERT INTO Billing_Decision_Collection (
                    update__staff_KEY,
                    update_date_utc,
                    create_date_utc,
                    collection_source__ledger_entry_KEY,
                    ledger_entry_KEY,
                    from__ledger_entry_KEY,
                    sheet_entry_KEY,
                    bill_amount_collected,
                    surcharge_collected,
                    discount_collected,
                    sales_tax_collected,
                    service_tax_collected,
                    collected
                )
                VALUES (?, GETUTCDATE(), GETUTCDATE(), ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ', [
                $staffKey,
                $collectionSourceKey,
                $invoiceKey,
                $invoiceKey, // from__ledger_entry_KEY = invoice KEY
                $bd->sheet_entry_KEY,
                $billAmountCollected,
                $surchargeCollected,
                $discountCollected,
                $salesTaxCollected,
                $serviceTaxCollected,
                $collected,
            ]);

            Log::debug('PracticeCS: BDC entry inserted', [
                'sheet_entry_KEY' => $bd->sheet_entry_KEY,
                'invoiced' => $bd->invoiced,
                'collected' => $collected,
            ]);
        }

        Log::info('PracticeCS: BDC entries inserted successfully', [
            'collection_source_KEY' => $collectionSourceKey,
            'invoice_KEY' => $invoiceKey,
            'entries_count' => count($billingDecisions),
        ]);
    }

    /**
     * Record payment in Online_Payment tracking table
     */
    protected function recordOnlinePayment(string $connection, int $ledgerEntryKey, array $paymentData): void
    {
        // Generate GUID from transaction ID
        $guid = $this->generateGuidFromString($paymentData['reference']);

        DB::connection($connection)->insert('
            INSERT INTO Online_Payment (
                ledger_entry_KEY,
                online_payment_guid,
                update__staff_KEY,
                update_date_utc,
                create_date_utc
            )
            VALUES (?, ?, ?, GETUTCDATE(), GETUTCDATE())
        ', [
            $ledgerEntryKey,
            $guid,
            $paymentData['staff_KEY'],
        ]);

        Log::info('PracticeCS: Online_Payment record created', [
            'ledger_entry_KEY' => $ledgerEntryKey,
            'guid' => $guid,
        ]);
    }

    /**
     * Generate a SQL Server UNIQUEIDENTIFIER (GUID) from a string
     */
    protected function generateGuidFromString(string $input): string
    {
        // Create a deterministic GUID from the input string
        $hash = md5($input);

        // Format as GUID: xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx
        return sprintf(
            '%08s-%04s-%04s-%04s-%012s',
            substr($hash, 0, 8),
            substr($hash, 8, 4),
            substr($hash, 12, 4),
            substr($hash, 16, 4),
            substr($hash, 20, 12)
        );
    }
}
