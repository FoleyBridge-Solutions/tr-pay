<?php

namespace App\Console\Commands;

use App\Models\Customer;
use App\Models\CustomerPaymentMethod;
use App\Models\RecurringPayment;
use App\Services\CustomerPaymentMethodService;
use FoleyBridgeSolutions\MiPaymentChoiceCashier\Services\QuickPaymentsService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Backfill saved payment methods for existing recurring payments.
 *
 * Finds recurring payments that store raw encrypted card/ACH data in
 * payment_method_token (legacy format from imports) and creates proper
 * CustomerPaymentMethod records, updating the recurring payment to
 * reference the saved method's reusable token instead.
 *
 * This command is idempotent — it skips records that already reference
 * a valid CustomerPaymentMethod token.
 */
class BackfillSavedPaymentMethods extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'payments:backfill-saved-methods
                            {--dry-run : Show what would be processed without making changes}
                            {--batch= : Only process records from a specific import batch ID}
                            {--limit= : Maximum number of records to process}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Create saved payment methods for recurring payments with legacy encrypted data';

    /**
     * Execute the console command.
     */
    public function handle(CustomerPaymentMethodService $paymentMethodService): int
    {
        $dryRun = $this->option('dry-run');
        $batchId = $this->option('batch');
        $limit = $this->option('limit') ? (int) $this->option('limit') : null;

        $this->info('Backfilling saved payment methods for recurring payments...');
        if ($dryRun) {
            $this->warn('[DRY RUN] No changes will be made.');
        }
        $this->newLine();

        // Find recurring payments with payment method tokens that need migration
        $query = RecurringPayment::query()
            ->whereNotNull('payment_method_token')
            ->where('payment_method_token', '!=', '')
            ->whereNotNull('payment_method_type');

        if ($batchId) {
            $query->where('import_batch_id', $batchId);
        }

        if ($limit) {
            $query->limit($limit);
        }

        $recurringPayments = $query->get();

        $this->info("Found {$recurringPayments->count()} recurring payment(s) with payment method tokens.");
        $this->newLine();

        $migrated = 0;
        $skipped = 0;
        $failed = 0;
        $alreadyMigrated = 0;

        foreach ($recurringPayments as $recurringPayment) {
            $label = "#{$recurringPayment->id} {$recurringPayment->client_name} ({$recurringPayment->payment_method_type})";

            // Check if this token already references a saved method
            if ($this->isAlreadySavedMethodToken($recurringPayment)) {
                $this->line("  [SKIP] {$label} - already references a saved payment method");
                $alreadyMigrated++;

                continue;
            }

            // Try to decrypt the token to extract raw payment data
            $paymentData = $this->decryptToken($recurringPayment->payment_method_token);

            if (! $paymentData) {
                $this->warn("  [SKIP] {$label} - could not decrypt token (may be corrupted)");
                $skipped++;

                continue;
            }

            // Validate the decrypted data has the expected structure
            if (! isset($paymentData['type'])) {
                $this->warn("  [SKIP] {$label} - decrypted data missing 'type' field");
                $skipped++;

                continue;
            }

            if ($dryRun) {
                $lastFour = $this->extractLastFour($paymentData);
                $this->info("  [WOULD MIGRATE] {$label} - {$paymentData['type']} ****{$lastFour}");
                $migrated++;

                continue;
            }

            // Perform the migration
            try {
                $result = $this->migrateRecord($paymentMethodService, $recurringPayment, $paymentData);

                if ($result) {
                    $this->info("  [MIGRATED] {$label} - saved method ID: {$result->id}");
                    $migrated++;
                } else {
                    $this->error("  [FAILED] {$label} - could not create saved method");
                    $failed++;
                }
            } catch (\Exception $e) {
                $this->error("  [ERROR] {$label} - {$e->getMessage()}");
                $failed++;

                Log::error('Backfill saved payment method failed', [
                    'recurring_payment_id' => $recurringPayment->id,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]);
            }
        }

        $this->newLine();
        $this->info('Backfill complete:');
        $this->line("  Migrated:         {$migrated}");
        $this->line("  Already migrated: {$alreadyMigrated}");
        $this->line("  Skipped:          {$skipped}");
        $this->line("  Failed:           {$failed}");

        return $failed > 0 ? Command::FAILURE : Command::SUCCESS;
    }

    /**
     * Check if a recurring payment's token already references a CustomerPaymentMethod.
     *
     * Tokens that are NOT legacy encrypted data:
     * - MPC gateway tokens (matched by CustomerPaymentMethod.mpc_token)
     * - ACH pseudo-tokens (start with "ach_local_")
     */
    protected function isAlreadySavedMethodToken(RecurringPayment $recurringPayment): bool
    {
        $token = $recurringPayment->payment_method_token;

        // ACH pseudo-tokens are clearly identifiable
        if (str_starts_with($token, 'ach_local_')) {
            return true;
        }

        // Check if a CustomerPaymentMethod exists with this exact token
        if ($recurringPayment->customer_id) {
            return CustomerPaymentMethod::where('customer_id', $recurringPayment->customer_id)
                ->where('mpc_token', $token)
                ->exists();
        }

        return false;
    }

    /**
     * Try to decrypt a payment method token and parse the JSON data.
     *
     * @return array|null The decrypted payment data, or null if decryption fails
     */
    protected function decryptToken(string $token): ?array
    {
        try {
            $decrypted = decrypt($token);
            $data = json_decode($decrypted, true);

            if (! is_array($data)) {
                return null;
            }

            return $data;
        } catch (\Exception $e) {
            // Not encrypted data — could be an MPC token or corrupted
            return null;
        }
    }

    /**
     * Extract last four digits from decrypted payment data.
     */
    protected function extractLastFour(array $paymentData): string
    {
        if (($paymentData['type'] ?? '') === 'card') {
            $number = preg_replace('/\D/', '', $paymentData['number'] ?? '');

            return substr($number, -4) ?: '????';
        }

        $number = preg_replace('/\D/', '', $paymentData['account'] ?? '');

        return substr($number, -4) ?: '????';
    }

    /**
     * Migrate a single recurring payment from legacy encrypted data to a saved method.
     *
     * @param  CustomerPaymentMethodService  $service  The payment method service
     * @param  RecurringPayment  $recurringPayment  The recurring payment to migrate
     * @param  array  $paymentData  Decrypted payment data (type, number/account, etc.)
     * @return CustomerPaymentMethod|null The created/reused saved method, or null on failure
     */
    protected function migrateRecord(
        CustomerPaymentMethodService $service,
        RecurringPayment $recurringPayment,
        array $paymentData
    ): ?CustomerPaymentMethod {
        // Ensure we have a customer
        $customer = $recurringPayment->customer;
        if (! $customer) {
            $customer = Customer::where('client_id', $recurringPayment->client_id)->first();

            if (! $customer) {
                $customer = Customer::create([
                    'name' => $recurringPayment->client_name,
                    'client_id' => $recurringPayment->client_id,
                ]);
            }

            $recurringPayment->customer_id = $customer->id;
        }

        $lastFour = $this->extractLastFour($paymentData);
        $type = ($paymentData['type'] ?? '') === 'card'
            ? CustomerPaymentMethod::TYPE_CARD
            : CustomerPaymentMethod::TYPE_ACH;

        // Check for existing saved method with same type + last_four (avoid duplicates)
        $existing = $customer->customerPaymentMethods()
            ->where('type', $type)
            ->where('last_four', $lastFour)
            ->first();

        if ($existing) {
            // Reuse existing saved method
            $recurringPayment->payment_method_token = $existing->mpc_token;
            $recurringPayment->save();

            Log::info('Backfill: reused existing saved method', [
                'recurring_payment_id' => $recurringPayment->id,
                'payment_method_id' => $existing->id,
                'type' => $type,
                'last_four' => $lastFour,
            ]);

            return $existing;
        }

        // Create new saved method
        $savedMethod = null;

        if ($type === CustomerPaymentMethod::TYPE_CARD) {
            $savedMethod = $this->createCardMethod($service, $customer, $paymentData);
        } else {
            $savedMethod = $this->createAchMethod($service, $customer, $paymentData);
        }

        if ($savedMethod) {
            $recurringPayment->payment_method_token = $savedMethod->mpc_token;
            $recurringPayment->save();

            Log::info('Backfill: created saved payment method', [
                'recurring_payment_id' => $recurringPayment->id,
                'payment_method_id' => $savedMethod->id,
                'type' => $type,
                'last_four' => $lastFour,
            ]);
        }

        return $savedMethod;
    }

    /**
     * Create a saved card payment method from decrypted data.
     *
     * Uses the QuickPayments two-step tokenization flow because the direct
     * /merchants/{key}/tokens/cards endpoint returns 404 for our merchant.
     * The QP flow uses /api/quickpayments/qp-tokens (one-time token) then
     * /api/quickpayments/tokens (convert to reusable token), which works.
     *
     * @param  array  $paymentData  Decrypted card data (number, expiry, cvv, name)
     */
    protected function createCardMethod(
        CustomerPaymentMethodService $service,
        Customer $customer,
        array $paymentData
    ): CustomerPaymentMethod {
        $cardNumber = preg_replace('/\D/', '', $paymentData['number'] ?? '');

        // Normalize expiry to MM/YY before parsing — legacy imports stored
        // raw spreadsheet values like "9/1/2030" or "Aug-30" instead of "MM/YY"
        $expiry = $this->normalizeExpiry($paymentData['expiry'] ?? '');

        // Parse expiry from MM/YY format
        $expParts = explode('/', $expiry);
        $expMonth = isset($expParts[0]) ? (int) $expParts[0] : 12;
        $expYear = isset($expParts[1]) ? (int) $expParts[1] : (int) date('Y');
        if ($expYear < 100) {
            $expYear += 2000;
        }

        $cardDetails = [
            'number' => $cardNumber,
            'exp_month' => $expMonth,
            'exp_year' => $expYear,
            'cvc' => $paymentData['cvv'] ?? '',
            'name' => $paymentData['name'] ?? '',
        ];

        // Step 1: Create one-time QuickPayments token from raw card data
        $qpService = app(QuickPaymentsService::class);
        $qpResponse = $qpService->createQpToken($cardDetails);
        $qpToken = $qpResponse['QuickPaymentsToken'] ?? null;

        if (! $qpToken) {
            throw new \RuntimeException('Failed to create QuickPayments token for card ****'.substr($cardNumber, -4));
        }

        // Step 2: Convert QP token to reusable token and create CustomerPaymentMethod
        $lastFour = substr($cardNumber, -4);
        $brand = CustomerPaymentMethod::detectCardBrand($cardNumber);

        return $service->createFromQuickPaymentsToken(
            $customer,
            $qpToken,
            CustomerPaymentMethod::TYPE_CARD,
            [
                'last_four' => $lastFour,
                'brand' => $brand,
                'exp_month' => $expMonth,
                'exp_year' => $expYear,
            ],
            false // Not default
        );
    }

    /**
     * Normalize a card expiry value to MM/YY format.
     *
     * Legacy imports stored raw spreadsheet values in the encrypted token
     * instead of normalizing to MM/YY first. Observed formats:
     * - "9/1/2030"  (M/D/YYYY — Excel date)
     * - "Aug-30"    (Mon-YY — Excel short date)
     * - "12/28"     (MM/YY — already correct)
     * - "0930"      (MMYY — no separator)
     *
     * @param  string  $expiry  Raw expiry value from decrypted token
     * @return string Normalized MM/YY string
     */
    protected function normalizeExpiry(string $expiry): string
    {
        $expiry = trim($expiry);

        // M/D/YYYY or MM/D/YYYY (e.g., "9/1/2030", "12/1/2028")
        if (preg_match('/^(\d{1,2})\/\d{1,2}\/(\d{4})$/', $expiry, $matches)) {
            $month = str_pad($matches[1], 2, '0', STR_PAD_LEFT);
            $year = substr($matches[2], -2);

            return "{$month}/{$year}";
        }

        // Mon-YY (e.g., "Aug-30", "Dec-28")
        $months = [
            'jan' => '01', 'feb' => '02', 'mar' => '03', 'apr' => '04',
            'may' => '05', 'jun' => '06', 'jul' => '07', 'aug' => '08',
            'sep' => '09', 'oct' => '10', 'nov' => '11', 'dec' => '12',
        ];

        if (preg_match('/^([a-zA-Z]{3})-(\d{2,4})$/i', $expiry, $matches)) {
            $monthNum = $months[strtolower($matches[1])] ?? null;
            if ($monthNum) {
                $year = substr($matches[2], -2);

                return "{$monthNum}/{$year}";
            }
        }

        // Already MM/YY, MMYY, or MM/YYYY — return as-is for existing parser
        return $expiry;
    }

    /**
     * Create a saved ACH payment method from decrypted data.
     *
     * @param  array  $paymentData  Decrypted ACH data (routing, account, account_type, name)
     */
    protected function createAchMethod(
        CustomerPaymentMethodService $service,
        Customer $customer,
        array $paymentData
    ): CustomerPaymentMethod {
        return $service->createFromCheckDetails(
            $customer,
            [
                'routing_number' => $paymentData['routing'] ?? '',
                'account_number' => $paymentData['account'] ?? '',
                'account_type' => $paymentData['account_type'] ?? 'checking',
                'name' => $paymentData['name'] ?? '',
            ],
            null, // No bank name
            null, // No nickname
            false // Not default
        );
    }
}
