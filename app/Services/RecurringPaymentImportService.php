<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\CustomerPaymentMethod;
use App\Models\RecurringPayment;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\IOFactory;

/**
 * RecurringPaymentImportService
 *
 * Handles CSV and Excel import of recurring payments with card/ACH details.
 *
 * Supports multiple formats:
 * 1. CSV with standard column names
 * 2. CSV/TSV with user-friendly column names (aliases supported)
 * 3. Excel files (.xlsx, .xls)
 *
 * Column Aliases (spreadsheet -> system):
 * - "Next Due" -> start_date
 * - "Customer Name" -> client_name (supports "Company | Contact" format)
 * - "Customer ID" -> client_id
 * - "Method" -> payment_type
 * - "Ends" -> max_occurrences
 * - "CC#" -> card_number
 * - "CC-EXP" -> card_expiry
 * - "CC-CVV" -> card_cvv
 * - "ACH- ACT #" -> account_number
 * - "ACH- Rout#" -> routing_number
 *
 * Payment Info:
 * - If payment details (card or ACH) are missing, record is saved with status='pending'
 * - Pending records can be edited later to add payment method
 * - Records with complete payment info are saved with status='active'
 *
 * Expected CSV columns (standard names):
 * - client_id (required): Client ID from PracticeCS (alphanumeric supported)
 * - client_name (required): Client name for display (supports "Company | Contact" format)
 * - amount (required): Payment amount (can include $ and commas, e.g., "$1,234.56")
 * - frequency (required): weekly, biweekly, monthly, quarterly, yearly
 *                         Also accepts: "Every 1 month", "Every 7 days", "Every 14 days", "Every 3 months"
 * - start_date (required): YYYY-MM-DD or MM/DD/YYYY or MM-DD-YY
 * - end_date (optional): YYYY-MM-DD or MM/DD/YYYY or MM-DD-YY
 * - max_occurrences (optional): Number of payments before completion
 *                               Also accepts: "After 9 occurrences", "N/A (ongoing)", etc.
 * - description (optional): Payment description
 * - payment_type (optional): card or ach. Also accepts: VISA, MC, Mastercard, Amex, eCheck
 *                            If empty with no payment details, status will be 'pending'
 * - card_number (required if card): Full card number
 * - card_expiry (required if card): MM/YY or MMYY
 * - card_cvv (optional): CVV code
 * - card_name (optional): Name on card
 * - routing_number (required if ach): 9-digit routing number
 * - account_number (required if ach): Bank account number
 * - account_type (optional if ach): checking or savings
 * - account_name (optional): Account holder name
 */
class RecurringPaymentImportService
{
    protected CustomerPaymentMethodService $paymentMethodService;

    protected array $errors = [];

    protected array $imported = [];

    protected array $warnings = [];

    protected string $batchId;

    protected string $delimiter = ',';

    public function __construct(CustomerPaymentMethodService $paymentMethodService)
    {
        $this->paymentMethodService = $paymentMethodService;
    }

    /**
     * Column name aliases to support alternative spreadsheet formats.
     *
     * Maps lowercase versions of alternative column names to their standard equivalents.
     */
    protected array $columnAliases = [
        'next due' => 'start_date',
        'customer name' => 'client_name',
        'customer id' => 'client_id',
        'method' => 'payment_type',
        'ends' => 'max_occurrences',
        'cc#' => 'card_number',
        'cc-exp' => 'card_expiry',
        'cc-cvv' => 'card_cvv',
        'ach- act #' => 'account_number',
        'ach- rout#' => 'routing_number',
    ];

    /**
     * Import recurring payments from an Excel file (.xlsx, .xls).
     *
     * @param  string  $filePath  Path to the Excel file
     * @return array Import results
     */
    public function importFromExcel(string $filePath): array
    {
        try {
            $spreadsheet = IOFactory::load($filePath);
            $worksheet = $spreadsheet->getActiveSheet();
            $rows = $worksheet->toArray();

            if (count($rows) < 2) {
                return [
                    'success' => false,
                    'error' => 'Excel file must contain a header row and at least one data row.',
                    'imported' => 0,
                    'errors' => [],
                ];
            }

            // Convert Excel rows to the format expected by importFromRows
            return $this->importFromRows($rows);
        } catch (\Exception $e) {
            Log::error('Excel import failed', [
                'error' => $e->getMessage(),
                'file' => $filePath,
            ]);

            return [
                'success' => false,
                'error' => 'Failed to read Excel file: '.$e->getMessage(),
                'imported' => 0,
                'errors' => [],
            ];
        }
    }

    /**
     * Import recurring payments from an array of rows.
     *
     * @param  array  $rows  Array of rows, first row is header
     * @return array Import results
     */
    public function importFromRows(array $rows): array
    {
        $this->errors = [];
        $this->imported = [];
        $this->warnings = [];
        $this->batchId = 'import_'.Str::random(16);

        if (count($rows) < 2) {
            return [
                'success' => false,
                'error' => 'Data must contain a header row and at least one data row.',
                'imported' => 0,
                'errors' => [],
            ];
        }

        // First row is header
        $headerRow = array_shift($rows);
        $header = array_map(fn ($h) => strtolower(trim((string) $h)), $headerRow);

        // Apply column aliases to normalize header names
        $header = $this->normalizeHeaders($header);

        // Validate required columns (client_id must match PracticeCS)
        $requiredColumns = ['client_id', 'client_name', 'amount', 'frequency', 'start_date'];
        $missingColumns = array_diff($requiredColumns, $header);

        if (! empty($missingColumns)) {
            return [
                'success' => false,
                'error' => 'Missing required columns: '.implode(', ', $missingColumns),
                'imported' => 0,
                'errors' => [],
            ];
        }

        DB::beginTransaction();

        try {
            foreach ($rows as $lineNum => $rowData) {
                $rowNum = $lineNum + 2; // +2 for 1-based index and header row

                // Skip empty rows
                $nonEmptyValues = array_filter($rowData, fn ($v) => $v !== null && $v !== '');
                if (empty($nonEmptyValues)) {
                    continue;
                }

                // Map to associative array
                $row = [];
                foreach ($header as $i => $col) {
                    $row[$col] = isset($rowData[$i]) ? (string) $rowData[$i] : '';
                }

                $this->processRow($row, $rowNum);
            }

            if (! empty($this->errors)) {
                DB::rollBack();

                return [
                    'success' => false,
                    'error' => 'Import failed with '.count($this->errors).' errors.',
                    'imported' => 0,
                    'errors' => $this->errors,
                    'warnings' => $this->warnings,
                ];
            }

            DB::commit();

            Log::info('Recurring payments imported successfully', [
                'batch_id' => $this->batchId,
                'count' => count($this->imported),
                'warnings' => count($this->warnings),
            ]);

            return [
                'success' => true,
                'imported' => count($this->imported),
                'batch_id' => $this->batchId,
                'errors' => [],
                'warnings' => $this->warnings,
            ];
        } catch (\Exception $e) {
            DB::rollBack();

            Log::error('Recurring payment import failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return [
                'success' => false,
                'error' => 'Import failed: '.$e->getMessage(),
                'imported' => 0,
                'errors' => $this->errors,
                'warnings' => $this->warnings,
            ];
        }
    }

    /**
     * Import recurring payments from CSV content.
     *
     * @param  string  $csvContent  Raw CSV content
     * @return array Import results
     */
    public function import(string $csvContent): array
    {
        $this->errors = [];
        $this->imported = [];
        $this->warnings = [];
        $this->batchId = 'import_'.Str::random(16);

        $lines = array_filter(explode("\n", $csvContent));

        if (count($lines) < 2) {
            return [
                'success' => false,
                'error' => 'CSV must contain a header row and at least one data row.',
                'imported' => 0,
                'errors' => [],
            ];
        }

        // Parse header - handle both comma and tab-separated values
        $headerLine = array_shift($lines);

        // Detect delimiter (tab or comma)
        $delimiter = str_contains($headerLine, "\t") ? "\t" : ',';

        $header = str_getcsv($headerLine, $delimiter);
        $header = array_map(fn ($h) => strtolower(trim($h)), $header);

        // Apply column aliases to normalize header names
        $header = $this->normalizeHeaders($header);

        // Store delimiter for data rows
        $this->delimiter = $delimiter;

        // Validate required columns (client_id must match PracticeCS)
        $requiredColumns = ['client_id', 'client_name', 'amount', 'frequency', 'start_date'];
        $missingColumns = array_diff($requiredColumns, $header);

        if (! empty($missingColumns)) {
            return [
                'success' => false,
                'error' => 'Missing required columns: '.implode(', ', $missingColumns),
                'imported' => 0,
                'errors' => [],
            ];
        }

        DB::beginTransaction();

        try {
            foreach ($lines as $lineNum => $line) {
                $rowNum = $lineNum + 2; // +2 for 1-based index and header row

                if (empty(trim($line))) {
                    continue;
                }

                $data = str_getcsv($line, $this->delimiter);

                // Map to associative array
                $row = [];
                foreach ($header as $i => $col) {
                    $row[$col] = $data[$i] ?? '';
                }

                $this->processRow($row, $rowNum);
            }

            if (! empty($this->errors)) {
                DB::rollBack();

                return [
                    'success' => false,
                    'error' => 'Import failed with '.count($this->errors).' errors.',
                    'imported' => 0,
                    'errors' => $this->errors,
                    'warnings' => $this->warnings,
                ];
            }

            DB::commit();

            Log::info('Recurring payments imported successfully', [
                'batch_id' => $this->batchId,
                'count' => count($this->imported),
                'warnings' => count($this->warnings),
            ]);

            return [
                'success' => true,
                'imported' => count($this->imported),
                'batch_id' => $this->batchId,
                'errors' => [],
                'warnings' => $this->warnings,
            ];
        } catch (\Exception $e) {
            DB::rollBack();

            Log::error('Recurring payment import failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return [
                'success' => false,
                'error' => 'Import failed: '.$e->getMessage(),
                'imported' => 0,
                'errors' => $this->errors,
                'warnings' => $this->warnings,
            ];
        }
    }

    /**
     * Normalize column headers using aliases.
     *
     * Maps alternative column names (e.g., "Next Due") to standard names (e.g., "start_date").
     *
     * @param  array  $headers  Array of lowercase header names
     * @return array Normalized header names
     */
    protected function normalizeHeaders(array $headers): array
    {
        return array_map(function ($header) {
            return $this->columnAliases[$header] ?? $header;
        }, $headers);
    }

    /**
     * Process a single row from the CSV.
     *
     * Creates a RecurringPayment record and, if payment details are provided,
     * creates a CustomerPaymentMethod (saved method) so the recurring payment
     * references a reusable gateway token rather than raw encrypted card/ACH data.
     */
    protected function processRow(array $row, int $rowNum): void
    {
        // Normalize the row data before validation
        $row = $this->normalizeRowData($row);

        // Validate required fields
        $validationErrors = $this->validateRow($row, $rowNum);

        if (! empty($validationErrors)) {
            $this->errors = array_merge($this->errors, $validationErrors);

            return;
        }

        try {
            // Parse dates
            $startDate = $this->parseDate($row['start_date']);
            $endDate = ! empty($row['end_date']) ? $this->parseDate($row['end_date']) : null;

            // Parse max occurrences
            $maxOccurrences = $this->parseMaxOccurrences($row['max_occurrences'] ?? null);

            // Check if payment info is provided
            $hasPaymentInfo = $this->hasPaymentInfo($row);

            // Generate payment method token if payment info is provided
            $paymentType = null;
            $token = null;
            $lastFour = null;
            $status = RecurringPayment::STATUS_PENDING;

            // Parse amount and check for $0 amounts (needs review)
            $amount = $this->parseAmount($row['amount']);
            $needsAmountReview = $amount == 0;

            // Parse client name (handles "Company | Contact" format)
            $parsedName = $this->parseClientName($row['client_name']);

            // Get or create customer
            $customer = $this->getOrCreateCustomer($row, $parsedName['client_name']);

            if ($hasPaymentInfo) {
                $paymentType = strtolower(trim($row['payment_type'] ?? ''));
                $lastFour = $this->getLastFour($row, $paymentType);

                // Create or find a saved payment method (CustomerPaymentMethod)
                // so recurring payments reference a reusable token, not raw card data
                $savedMethod = $this->getOrCreateSavedPaymentMethod($customer, $row, $paymentType, $lastFour, $rowNum);

                if ($savedMethod) {
                    $token = $savedMethod->mpc_token;
                    $status = RecurringPayment::STATUS_ACTIVE;
                } else {
                    // Tokenization failed — fall back to encrypted raw data
                    // so the record is still created and can be fixed later
                    $token = $this->tokenizePaymentMethod($row, $paymentType);
                    $status = RecurringPayment::STATUS_ACTIVE;

                    $this->warnings[] = [
                        'row' => $rowNum,
                        'message' => "Could not create saved payment method for '{$parsedName['client_name']}' - payment info stored encrypted (legacy format)",
                    ];
                }
            }

            // If amount is $0, mark as pending regardless of payment info
            if ($needsAmountReview) {
                $status = RecurringPayment::STATUS_PENDING;
            }

            // Calculate next payment date
            $nextPaymentDate = $startDate->copy();
            if ($nextPaymentDate->lt(now())) {
                // If start date is in the past, find the next occurrence
                $nextPaymentDate = $this->calculateNextOccurrence($startDate, $row['frequency']);
            }

            // Build metadata
            $metadata = [
                'imported_at' => now()->toIso8601String(),
                'original_row' => $rowNum,
            ];

            // Add contact name to metadata if parsed from "Company | Contact" format
            if (! empty($parsedName['contact_name'])) {
                $metadata['contact_name'] = $parsedName['contact_name'];
            }

            // Track saved method linkage in metadata
            if (isset($savedMethod) && $savedMethod) {
                $metadata['saved_method_id'] = $savedMethod->id;
            }

            // Flag if $0 amount needs review
            if ($needsAmountReview) {
                $metadata['needs_amount_review'] = true;
                $metadata['review_reason'] = 'Amount is $0.00 - please update with correct amount';
                $this->warnings[] = [
                    'row' => $rowNum,
                    'message' => "Amount is \$0.00 for '{$parsedName['client_name']}' - saved as pending, needs review",
                ];
            }

            // Create the recurring payment
            $recurringPayment = RecurringPayment::create([
                'customer_id' => $customer?->id,
                'client_id' => trim($row['client_id']),
                'client_name' => $parsedName['client_name'],
                'frequency' => strtolower(trim($row['frequency'])),
                'amount' => $amount,
                'description' => trim($row['description'] ?? ''),
                'payment_method_type' => $paymentType,
                'payment_method_token' => $token,
                'payment_method_last_four' => $lastFour,
                'status' => $status,
                'start_date' => $startDate,
                'end_date' => $endDate,
                'max_occurrences' => $maxOccurrences,
                'next_payment_date' => $nextPaymentDate,
                'import_batch_id' => $this->batchId,
                'metadata' => $metadata,
            ]);

            $this->imported[] = $recurringPayment->id;
        } catch (\Exception $e) {
            $this->errors[] = [
                'row' => $rowNum,
                'message' => 'Failed to create recurring payment: '.$e->getMessage(),
            ];
        }
    }

    /**
     * Get or create a CustomerPaymentMethod for the imported payment data.
     *
     * First checks if the customer already has a saved method with the same
     * type and last four digits. If found, reuses it to avoid duplicates.
     * Otherwise, creates a new saved method via the gateway (cards) or
     * with a local pseudo-token (ACH).
     *
     * @param  Customer|null  $customer  The customer record
     * @param  array  $row  The CSV row data with payment details
     * @param  string  $paymentType  'card' or 'ach'
     * @param  string  $lastFour  Last 4 digits of card/account
     * @param  int  $rowNum  Row number for logging
     * @return CustomerPaymentMethod|null The saved method, or null if creation failed
     */
    protected function getOrCreateSavedPaymentMethod(
        ?Customer $customer,
        array $row,
        string $paymentType,
        string $lastFour,
        int $rowNum
    ): ?CustomerPaymentMethod {
        if (! $customer) {
            return null;
        }

        // Check for existing saved method with same type + last_four for this customer
        $existing = $customer->customerPaymentMethods()
            ->where('type', $paymentType === 'card' ? CustomerPaymentMethod::TYPE_CARD : CustomerPaymentMethod::TYPE_ACH)
            ->where('last_four', $lastFour)
            ->first();

        if ($existing) {
            Log::info('Reusing existing saved payment method for import', [
                'customer_id' => $customer->id,
                'payment_method_id' => $existing->id,
                'type' => $paymentType,
                'last_four' => $lastFour,
                'row' => $rowNum,
            ]);

            return $existing;
        }

        // Create new saved payment method
        try {
            if ($paymentType === 'card') {
                return $this->createSavedCardMethod($customer, $row);
            }

            return $this->createSavedAchMethod($customer, $row);
        } catch (\Exception $e) {
            Log::warning('Failed to create saved payment method during import', [
                'customer_id' => $customer->id,
                'type' => $paymentType,
                'last_four' => $lastFour,
                'row' => $rowNum,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Create a saved card payment method via MiPaymentChoice tokenization.
     *
     * @param  Customer  $customer  The customer
     * @param  array  $row  CSV row with card_number, card_expiry, card_cvv, card_name
     * @return CustomerPaymentMethod The created saved method with MPC token
     */
    protected function createSavedCardMethod(Customer $customer, array $row): CustomerPaymentMethod
    {
        $cardNumber = preg_replace('/\D/', '', $row['card_number']);

        // Parse expiry from various formats (MM/YY, MMYY, MM/YYYY)
        $expiry = trim($row['card_expiry']);
        $expMonth = null;
        $expYear = null;

        if (preg_match('/^(\d{1,2})\/?(\d{2,4})$/', $expiry, $matches)) {
            $expMonth = (int) $matches[1];
            $expYear = (int) $matches[2];
            if ($expYear < 100) {
                $expYear += 2000;
            }
        }

        return $this->paymentMethodService->createFromCardDetails(
            $customer,
            [
                'number' => $cardNumber,
                'exp_month' => $expMonth ?? 12,
                'exp_year' => $expYear ?? (int) date('Y'),
                'cvc' => $row['card_cvv'] ?? '',
                'name' => $row['card_name'] ?? '',
            ],
            null, // No nickname
            false // Not default
        );
    }

    /**
     * Create a saved ACH payment method with a local pseudo-token.
     *
     * @param  Customer  $customer  The customer
     * @param  array  $row  CSV row with routing_number, account_number, account_type, account_name
     * @return CustomerPaymentMethod The created saved method with pseudo-token
     */
    protected function createSavedAchMethod(Customer $customer, array $row): CustomerPaymentMethod
    {
        // Pad routing number to 9 digits with leading zeros if needed
        $routing = preg_replace('/\D/', '', $row['routing_number']);
        $routing = str_pad($routing, 9, '0', STR_PAD_LEFT);

        $accountNumber = preg_replace('/\D/', '', $row['account_number']);

        return $this->paymentMethodService->createFromCheckDetails(
            $customer,
            [
                'routing_number' => $routing,
                'account_number' => $accountNumber,
                'account_type' => strtolower($row['account_type'] ?? 'checking'),
                'name' => $row['account_name'] ?? '',
            ],
            null, // No bank name
            null, // No nickname
            false // Not default
        );
    }

    /**
     * Normalize row data to handle various input formats.
     */
    protected function normalizeRowData(array $row): array
    {
        // Normalize frequency
        if (isset($row['frequency'])) {
            $row['frequency'] = $this->normalizeFrequency($row['frequency']);
        }

        // Normalize payment type
        if (isset($row['payment_type'])) {
            $row['payment_type'] = $this->normalizePaymentType($row['payment_type']);
        }

        return $row;
    }

    /**
     * Normalize frequency values to standard format.
     *
     * Converts values like "Every 1 month" to "monthly".
     */
    protected function normalizeFrequency(string $frequency): string
    {
        $frequency = strtolower(trim($frequency));

        // Direct matches
        $validFrequencies = ['weekly', 'biweekly', 'monthly', 'quarterly', 'yearly'];
        if (in_array($frequency, $validFrequencies)) {
            return $frequency;
        }

        // Handle "Never" as a one-time payment (monthly with max_occurrences=1)
        if ($frequency === 'never') {
            return 'monthly'; // Will be combined with max_occurrences=1
        }

        // Pattern matching for "Every X days/weeks/months" format
        if (preg_match('/every\s+(\d+)\s+(day|week|month|year)s?/i', $frequency, $matches)) {
            $number = (int) $matches[1];
            $unit = strtolower($matches[2]);

            return match (true) {
                $unit === 'day' && $number === 7 => 'weekly',
                $unit === 'day' && $number === 14 => 'biweekly',
                $unit === 'week' && $number === 1 => 'weekly',
                $unit === 'week' && $number === 2 => 'biweekly',
                $unit === 'month' && $number === 1 => 'monthly',
                $unit === 'month' && $number === 3 => 'quarterly',
                $unit === 'year' && $number === 1 => 'yearly',
                default => $frequency, // Return original if no match
            };
        }

        return $frequency;
    }

    /**
     * Normalize payment type values to standard format.
     *
     * Converts values like "VISA", "MC", "eCheck" to "card" or "ach".
     */
    protected function normalizePaymentType(string $paymentType): string
    {
        $paymentType = strtolower(trim($paymentType));

        // Card types
        $cardTypes = ['visa', 'mc', 'mastercard', 'amex', 'american express', 'discover', 'card', 'credit', 'credit card', 'debit'];
        if (in_array($paymentType, $cardTypes)) {
            return 'card';
        }

        // ACH types
        $achTypes = ['ach', 'echeck', 'e-check', 'bank', 'bank account', 'checking', 'savings'];
        if (in_array($paymentType, $achTypes)) {
            return 'ach';
        }

        return $paymentType;
    }

    /**
     * Check if the row has payment information (card or ACH).
     *
     * Returns true if the row has enough info to create a payment method.
     */
    protected function hasPaymentInfo(array $row): bool
    {
        $paymentType = strtolower(trim($row['payment_type'] ?? ''));

        // Check for card payment info
        if (in_array($paymentType, ['card', 'visa', 'mc', 'mastercard', 'amex', 'american express', 'discover', 'credit', 'credit card', 'debit'])) {
            return ! empty($row['card_number']) && ! empty($row['card_expiry']);
        }

        // Check for ACH payment info
        if (in_array($paymentType, ['ach', 'echeck', 'e-check', 'bank', 'bank account', 'checking', 'savings'])) {
            return ! empty($row['routing_number']) && ! empty($row['account_number']);
        }

        // If payment type is empty but we have payment data, check both types
        if (empty($paymentType)) {
            // Has card info?
            if (! empty($row['card_number']) && ! empty($row['card_expiry'])) {
                return true;
            }
            // Has ACH info?
            if (! empty($row['routing_number']) && ! empty($row['account_number'])) {
                return true;
            }
        }

        return false;
    }

    /**
     * Parse client name to handle "Company | Contact" format.
     *
     * @param  string  $clientName  Raw client name from CSV
     * @return array ['client_name' => string, 'contact_name' => string|null]
     */
    protected function parseClientName(string $clientName): array
    {
        $clientName = trim($clientName);

        // Check for "Company | Contact" format
        if (str_contains($clientName, '|')) {
            $parts = explode('|', $clientName, 2);

            return [
                'client_name' => trim($parts[0]),
                'contact_name' => isset($parts[1]) ? trim($parts[1]) : null,
            ];
        }

        return [
            'client_name' => $clientName,
            'contact_name' => null,
        ];
    }

    /**
     * Parse amount string to float.
     *
     * Handles formats like "$1,234.56" and "1234.56".
     */
    protected function parseAmount(string $amount): float
    {
        // Remove currency symbols, commas, and whitespace
        $cleaned = preg_replace('/[^0-9.]/', '', $amount);

        return (float) $cleaned;
    }

    /**
     * Parse max occurrences from various formats.
     *
     * Handles: "After 9 occurrences", "9", "N/A (ongoing)", null
     */
    protected function parseMaxOccurrences(?string $value): ?int
    {
        if (empty($value)) {
            return null;
        }

        $value = strtolower(trim($value));

        // Check for "N/A", "ongoing", empty-like values
        if (in_array($value, ['n/a', 'n/a (ongoing)', 'ongoing', 'unlimited', 'none', '-', ''])) {
            return null;
        }

        // Try to extract number from "After X occurrences" format
        if (preg_match('/after\s+(\d+)\s+occurrence/i', $value, $matches)) {
            return (int) $matches[1];
        }

        // Try direct integer
        if (is_numeric($value)) {
            return (int) $value;
        }

        return null;
    }

    /**
     * Validate a row's data.
     */
    protected function validateRow(array $row, int $rowNum): array
    {
        $errors = [];

        // Required fields - client_id MUST match PracticeCS
        if (empty($row['client_id'])) {
            $errors[] = ['row' => $rowNum, 'message' => 'client_id (Customer ID) is required - must match PracticeCS'];
        }
        if (empty($row['client_name'])) {
            $errors[] = ['row' => $rowNum, 'message' => 'client_name is required'];
        }

        // Validate amount - $0 amounts are allowed but will be flagged as 'pending_review'
        // (no error, just a warning handled in processRow)
        $amount = $this->parseAmount($row['amount'] ?? '');
        if ($amount < 0) {
            $errors[] = ['row' => $rowNum, 'message' => 'amount cannot be negative'];
        }

        // Validate frequency (after normalization)
        $validFrequencies = ['weekly', 'biweekly', 'monthly', 'quarterly', 'yearly'];
        $frequency = strtolower(trim($row['frequency'] ?? ''));
        if (empty($frequency) || ! in_array($frequency, $validFrequencies)) {
            $errors[] = ['row' => $rowNum, 'message' => "frequency '{$row['frequency']}' is invalid. Must be one of: ".implode(', ', $validFrequencies)];
        }

        // Validate start date
        if (empty($row['start_date']) || ! $this->parseDate($row['start_date'])) {
            $errors[] = ['row' => $rowNum, 'message' => 'start_date is invalid (use YYYY-MM-DD, MM/DD/YYYY, or MM-DD-YY)'];
        }

        // Validate end date if provided
        if (! empty($row['end_date']) && ! $this->parseDate($row['end_date'])) {
            $errors[] = ['row' => $rowNum, 'message' => 'end_date is invalid (use YYYY-MM-DD, MM/DD/YYYY, or MM-DD-YY)'];
        }

        // Payment validation is only required if payment info is provided
        // If no payment info, record will be saved with 'pending' status
        if (! $this->hasPaymentInfo($row)) {
            return $errors;
        }

        // Validate payment type (after normalization)
        $paymentType = strtolower(trim($row['payment_type'] ?? ''));
        if (! in_array($paymentType, ['card', 'ach'])) {
            $errors[] = ['row' => $rowNum, 'message' => "payment_type '{$row['payment_type']}' is invalid. Must be 'card' or 'ach' (or VISA, MC, eCheck, etc.)"];

            return $errors; // Can't validate payment details without valid type
        }

        // Validate card details
        if ($paymentType === 'card') {
            if (empty($row['card_number'])) {
                $errors[] = ['row' => $rowNum, 'message' => 'card_number is required for card payments'];
            } else {
                $cardNumber = preg_replace('/\D/', '', $row['card_number']);
                if (strlen($cardNumber) < 13 || strlen($cardNumber) > 19) {
                    $errors[] = ['row' => $rowNum, 'message' => 'card_number is invalid'];
                }
            }
            if (empty($row['card_expiry'])) {
                $errors[] = ['row' => $rowNum, 'message' => 'card_expiry is required for card payments'];
            }
        }

        // Validate ACH details
        if ($paymentType === 'ach') {
            if (empty($row['routing_number'])) {
                $errors[] = ['row' => $rowNum, 'message' => 'routing_number is required for ACH payments'];
            } else {
                $routing = preg_replace('/\D/', '', $row['routing_number']);
                // Pad with leading zeros if less than 9 digits (common for older routing numbers)
                if (strlen($routing) < 9 && strlen($routing) >= 8) {
                    // Will be padded during processing
                } elseif (strlen($routing) !== 9) {
                    $errors[] = ['row' => $rowNum, 'message' => 'routing_number must be 8-9 digits (got '.strlen($routing).')'];
                }
            }
            if (empty($row['account_number'])) {
                $errors[] = ['row' => $rowNum, 'message' => 'account_number is required for ACH payments'];
            }
        }

        return $errors;
    }

    /**
     * Parse a date string into Carbon.
     *
     * Supports: YYYY-MM-DD, MM/DD/YYYY, MM/DD/YY, MM-DD-YY, MM-DD-YYYY
     */
    protected function parseDate(string $date): ?Carbon
    {
        $date = trim($date);

        // Try YYYY-MM-DD
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            return Carbon::parse($date);
        }

        // Try MM/DD/YYYY
        if (preg_match('/^\d{1,2}\/\d{1,2}\/\d{4}$/', $date)) {
            return Carbon::createFromFormat('m/d/Y', $date)->startOfDay();
        }

        // Try MM/DD/YY
        if (preg_match('/^\d{1,2}\/\d{1,2}\/\d{2}$/', $date)) {
            return Carbon::createFromFormat('m/d/y', $date)->startOfDay();
        }

        // Try MM-DD-YY (your format: 01-06-26)
        if (preg_match('/^\d{1,2}-\d{1,2}-\d{2}$/', $date)) {
            return Carbon::createFromFormat('m-d-y', $date)->startOfDay();
        }

        // Try MM-DD-YYYY
        if (preg_match('/^\d{1,2}-\d{1,2}-\d{4}$/', $date)) {
            return Carbon::createFromFormat('m-d-Y', $date)->startOfDay();
        }

        return null;
    }

    /**
     * Tokenize the payment method (legacy fallback).
     *
     * Encrypts raw card/ACH data for storage. This is only used as a fallback
     * when gateway tokenization fails during import. Prefer creating a
     * CustomerPaymentMethod via getOrCreateSavedPaymentMethod() instead.
     */
    protected function tokenizePaymentMethod(array $row, string $paymentType): string
    {
        // For now, we'll encrypt the card/account data
        // In production, this should call MiPaymentChoice to create a token

        if ($paymentType === 'card') {
            $data = [
                'type' => 'card',
                'number' => preg_replace('/\D/', '', $row['card_number']),
                'expiry' => $row['card_expiry'],
                'cvv' => $row['card_cvv'] ?? '',
                'name' => $row['card_name'] ?? '',
            ];
        } else {
            // Pad routing number to 9 digits with leading zeros if needed
            $routing = preg_replace('/\D/', '', $row['routing_number']);
            $routing = str_pad($routing, 9, '0', STR_PAD_LEFT);

            $data = [
                'type' => 'ach',
                'routing' => $routing,
                'account' => preg_replace('/\D/', '', $row['account_number']),
                'account_type' => strtolower($row['account_type'] ?? 'checking'),
                'name' => $row['account_name'] ?? '',
            ];
        }

        // Encrypt the data (using Laravel's encryption)
        return encrypt(json_encode($data));
    }

    /**
     * Get the last 4 digits of the payment method.
     */
    protected function getLastFour(array $row, string $paymentType): string
    {
        if ($paymentType === 'card') {
            $number = preg_replace('/\D/', '', $row['card_number']);

            return substr($number, -4);
        } else {
            $number = preg_replace('/\D/', '', $row['account_number']);

            return substr($number, -4);
        }
    }

    /**
     * Calculate the next occurrence of a recurring payment from a past start date.
     */
    protected function calculateNextOccurrence(Carbon $startDate, string $frequency): Carbon
    {
        $now = now()->startOfDay();
        $nextDate = $startDate->copy();

        while ($nextDate->lt($now)) {
            $nextDate = match (strtolower($frequency)) {
                'weekly' => $nextDate->addWeek(),
                'biweekly' => $nextDate->addWeeks(2),
                'monthly' => $nextDate->addMonth(),
                'quarterly' => $nextDate->addMonths(3),
                'yearly' => $nextDate->addYear(),
                default => $nextDate->addMonth(),
            };
        }

        return $nextDate;
    }

    /**
     * Get or create a customer for the recurring payment.
     *
     * @param  array  $row  The CSV row data
     * @param  string|null  $parsedClientName  The parsed client name (company only, without contact)
     */
    protected function getOrCreateCustomer(array $row, ?string $parsedClientName = null): ?Customer
    {
        $clientId = trim($row['client_id']);

        $customer = Customer::where('client_id', $clientId)->first();

        if (! $customer) {
            // Use parsed client name if provided, otherwise fall back to raw value
            $name = $parsedClientName ?? trim($row['client_name']);

            $customer = Customer::create([
                'name' => $name,
                'client_id' => $clientId,
            ]);
        }

        return $customer;
    }

    /**
     * Generate a sample CSV template.
     *
     * Supports two formats:
     * 1. Standard format with system column names
     * 2. Spreadsheet format with user-friendly column names (aliases supported)
     */
    public static function getSampleCsv(): string
    {
        // Using spreadsheet-friendly column names (aliases are supported)
        $headers = [
            'Next Due',
            'Amount',
            'Customer Name',
            'Customer ID',
            'Method',
            'Frequency',
            'Ends',
            'Status',
            'CC#',
            'CC-EXP',
            'CC-CVV',
            'ACH- ACT #',
            'ACH- Rout#',
        ];

        // Example with ACH payment (eCheck)
        $sampleAch = [
            '3/1/2026',
            '$1,275.00',
            'E & S FARMS | Sandy Fasler',
            '573030',
            'eCheck',
            'Every 3 months',
            'N/A (ongoing)',
            'Active',
            '',
            '',
            '',
            '1371483',
            '114903174',
        ];

        // Example with card payment
        $sampleCard = [
            '1/15/2026',
            '$150.00',
            'ACME Corp | John Smith',
            'ABC12345',
            'VISA',
            'Every 1 month',
            'After 12 occurrences',
            'Active',
            '4111111111111111',
            '12/26',
            '123',
            '',
            '',
        ];

        // Example with missing payment info (will be saved as pending)
        $samplePending = [
            '2/1/2026',
            '$500.00',
            'Pending Client | Jane Doe',
            '999999',
            '',
            'Monthly',
            'N/A (ongoing)',
            'Active',
            '',
            '',
            '',
            '',
            '',
        ];

        return implode(',', $headers)."\n".
               implode(',', $sampleAch)."\n".
               implode(',', $sampleCard)."\n".
               implode(',', $samplePending);
    }
}
