<?php

namespace App\Console\Commands;

use App\Models\Payment;
use App\Models\PaymentPlan;
use App\Notifications\AchReturnDetected;
use App\Notifications\PracticeCsWriteFailed;
use App\Services\AdminAlertService;
use App\Services\PracticeCsPaymentWriter;
use App\Support\AdminNotifiable;
use FoleyBridgeSolutions\KotapayCashier\Exceptions\KotapayException;
use FoleyBridgeSolutions\KotapayCashier\Services\ReportService;
use Illuminate\Console\Command;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Check the settlement status of ACH payments via Kotapay reports.
 *
 * Uses report-based reconciliation:
 *   Job 1 — Settle PROCESSING payments by confirming they appear in Kotapay's
 *           Processed Batches Report (positive confirmation, not inference).
 *           Also checks the Returns Report for returned payments.
 *   Job 2 — Monitor COMPLETED payments for post-settlement returns (up to 60 days)
 *   Job 3 — Check corrections (NOC) and log them
 *
 * Matching logic:
 *   Primary:  payment.metadata.kotapay_account_name_id === batch entry EntryID
 *   Fallback: customer name + amount + routing/account (for legacy payments without EntryID)
 *
 * Should be run daily via the scheduler (morning + evening).
 */
class CheckAchPaymentStatus extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'payments:check-ach-status
                            {--dry-run : Show what would change without updating}
                            {--id= : Check a specific payment by ID}
                            {--days=60 : Post-settlement monitoring window in days}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Check ACH payment settlement status via Kotapay reports';

    /**
     * Counters for the summary table.
     */
    protected int $settled = 0;

    protected int $returned = 0;

    protected int $postSettlementReturned = 0;

    protected int $stillProcessing = 0;

    protected int $corrections = 0;

    protected int $errors = 0;

    /**
     * Payments that failed/returned (for admin notification).
     *
     * @var array<int, array{payment: Payment, reason: string, status: string}>
     */
    protected array $failedPayments = [];

    /**
     * Execute the console command.
     */
    public function handle(ReportService $reportService): int
    {
        $dryRun = $this->option('dry-run');
        $specificId = $this->option('id');
        $monitorDays = (int) $this->option('days');

        $this->info('ACH Settlement Check — Report-Based Reconciliation');
        $this->info($dryRun ? '[DRY RUN MODE]' : '[LIVE MODE]');
        $this->newLine();

        try {
            // ─── Job 1: Settle processing payments ───────────────────────
            $this->settleProcessingPayments($reportService, $dryRun, $specificId);

            // ─── Job 2: Post-settlement return monitoring ────────────────
            if (! $specificId) {
                $this->monitorPostSettlementReturns($reportService, $dryRun, $monitorDays);
            }

            // ─── Job 3: Corrections / NOC ────────────────────────────────
            if (! $specificId) {
                $this->checkCorrections($reportService, $dryRun);
            }
        } catch (KotapayException $e) {
            $this->error("Kotapay API error: {$e->getMessage()}");

            Log::error('ACH status check failed — Kotapay API error', [
                'error' => $e->getMessage(),
            ]);

            return self::FAILURE;
        } catch (\Exception $e) {
            $this->error("Unexpected error: {$e->getMessage()}");

            Log::error('ACH status check failed — unexpected error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return self::FAILURE;
        }

        // ─── Summary ─────────────────────────────────────────────────
        $this->printSummary();

        // ─── Admin notification for returned payments ────────────────
        if (! empty($this->failedPayments) && ! $dryRun) {
            $this->sendFailureNotification($this->failedPayments);
        }

        return $this->errors > 0 ? self::FAILURE : self::SUCCESS;
    }

    // =====================================================================
    // Job 1 — Settle PROCESSING payments
    // =====================================================================

    /**
     * Settle processing payments using Kotapay's Processed Batches Report
     * for positive settlement confirmation, and Returns Report for failures.
     *
     * Logic:
     *   1. Fetch all processed batches covering the payment date range
     *   2. Fetch detail for each batch to get individual entries
     *   3. Build a lookup of all confirmed-settled entries
     *   4. For each processing payment:
     *      - If found in returns → STATUS_FAILED
     *      - If found in a processed batch → STATUS_COMPLETED (confirmed)
     *      - If not found in either → still PROCESSING (do NOT guess)
     *
     * @param  ReportService  $reportService  Kotapay report service
     * @param  bool  $dryRun  Whether to skip actual updates
     * @param  int|null  $specificId  Specific payment ID to check (null = all)
     */
    protected function settleProcessingPayments(ReportService $reportService, bool $dryRun, ?int $specificId): void
    {
        $this->info('─── Job 1: Settle Processing Payments ───');
        $this->newLine();

        $query = Payment::query()
            ->where('status', Payment::STATUS_PROCESSING)
            ->where('payment_vendor', 'kotapay');

        if ($specificId) {
            $query->where('id', $specificId);
        }

        $payments = $query->get();

        if ($payments->isEmpty()) {
            $this->info('No ACH payments currently processing.');
            $this->newLine();

            return;
        }

        $this->info("Found {$payments->count()} processing ACH payment(s).");
        $this->newLine();

        // ── Step 1: Fetch returns report ──────────────────────────────
        $earliestDate = $this->getEarliestPaymentDate($payments);
        $startDate = $earliestDate->format('Y-m-d');
        $endDate = Carbon::today()->format('Y-m-d');

        $this->line("Fetching returns report from {$startDate}...");

        $returnRows = [];
        $returnsByEntryId = [];

        try {
            $returnsReport = $reportService->getReturnsReport($startDate);
            $returnRows = $returnsReport['rows'] ?? [];

            // Guard: ensure rows is an array (Kotapay may return string in ACH format)
            if (! is_array($returnRows)) {
                $this->warn('  Returns report returned non-array rows — skipping returns check.');
                Log::warning('Returns report returned non-array rows', [
                    'type' => gettype($returnRows),
                ]);
                $returnRows = [];
            }

            $this->line('Returns report: '.count($returnRows).' row(s).');
            $returnsByEntryId = $this->indexReturnsByEntryId($returnRows);
        } catch (\Exception $e) {
            $this->warn("  Could not fetch returns report: {$e->getMessage()}");
            Log::warning('Failed to fetch returns report during settlement check', [
                'error' => $e->getMessage(),
            ]);
        }

        $this->newLine();

        // ── Step 2: Fetch processed batches ──────────────────────────
        $this->line("Fetching processed batches from {$startDate} to {$endDate}...");

        $settledEntries = $this->buildSettledEntriesLookup($reportService, $startDate, $endDate);

        $this->line('Settled entries indexed: '.count($settledEntries['by_entry_id']).' by EntryID, '.count($settledEntries['by_name_amount']).' by name+amount.');
        $this->newLine();

        // ── Step 3: Process each payment ─────────────────────────────
        foreach ($payments as $payment) {
            $this->processPaymentSettlement($payment, $returnRows, $returnsByEntryId, $settledEntries, $dryRun);
        }

        $this->newLine();
    }

    /**
     * Build a lookup of all entries from Kotapay's Processed Batches Report.
     *
     * Fetches batch summaries, then detail for each BILLING batch (skips
     * DISBURSE/Funding batches which are internal transfers).
     *
     * Returns a structure with two indexes for matching:
     *   - by_entry_id: EntryID → entry row (for payments with kotapay_account_name_id)
     *   - by_name_amount: "NORMALIZED_NAME|AMOUNT" → entry row (for legacy payments)
     *
     * @param  ReportService  $reportService  Kotapay report service
     * @param  string  $startDate  Start date (Y-m-d)
     * @param  string  $endDate  End date (Y-m-d)
     * @return array{by_entry_id: array, by_name_amount: array}
     */
    protected function buildSettledEntriesLookup(ReportService $reportService, string $startDate, string $endDate): array
    {
        $byEntryId = [];
        $byNameAmount = [];

        try {
            $batchSummary = $reportService->getProcessedBatchesSummary($startDate, $endDate);
            $batches = $batchSummary['rows'] ?? [];

            $this->line('Found '.count($batches).' batch(es) in date range.');

            foreach ($batches as $batch) {
                $batchId = $batch['BatchUniqueID'] ?? null;
                $desc = trim($batch['AppDescription'] ?? '');
                $disc = trim($batch['AppDiscretionary'] ?? '');

                // Skip non-collection batches (disbursements, funding, etc.)
                // We only care about BILLING / PAYMENT / DOWN PAYME batches — these
                // are the ones that pull money from customer accounts.
                $isCollection = in_array($desc, ['BILLING', 'PAYMENT', 'ADMIN PAYM', 'DOWN PAYME'], true)
                    || str_contains($disc, 'BILLING');

                if (! $isCollection) {
                    if ($this->output->isVerbose()) {
                        $this->line("  Skipping batch {$batchId} ({$desc} / {$disc})");
                    }

                    continue;
                }

                if ($batchId === null) {
                    continue;
                }

                // Fetch detail for this batch
                try {
                    $detail = $reportService->getProcessedBatchDetail($batchId);
                    $entries = $detail['rows'] ?? [];
                    $effectiveDate = $batch['EffectiveDate'] ?? null;

                    foreach ($entries as $entry) {
                        $entryName = trim($entry['EntryName'] ?? '');
                        $entryId = trim($entry['EntryID'] ?? '');
                        $creditAmt = (float) ($entry['CreditAmt'] ?? 0);

                        // Skip the Burkhart Peterson offset entry (the debit side)
                        if ($entryName === 'BURKHART PETERSO' || $creditAmt <= 0) {
                            continue;
                        }

                        // Enrich entry with batch-level effective date
                        $entry['_batch_effective_date'] = $effectiveDate;
                        $entry['_batch_id'] = $batchId;

                        // Index by EntryID (primary match for new payments)
                        if ($entryId !== '') {
                            $byEntryId[$entryId] = $entry;
                        }

                        // Index by normalized name + amount (fallback for legacy payments)
                        $normalizedName = $this->normalizeName($entryName);
                        $key = $normalizedName.'|'.number_format($creditAmt, 2, '.', '');
                        // Store as array — there could be multiple entries with same name+amount
                        $byNameAmount[$key][] = $entry;
                    }
                } catch (\Exception $e) {
                    $this->warn("  Failed to fetch detail for batch {$batchId}: {$e->getMessage()}");
                    $this->errors++;
                }
            }
        } catch (\Exception $e) {
            $this->error("Failed to fetch processed batches: {$e->getMessage()}");
            Log::error('Failed to fetch processed batches for settlement check', [
                'error' => $e->getMessage(),
            ]);
            $this->errors++;
        }

        return [
            'by_entry_id' => $byEntryId,
            'by_name_amount' => $byNameAmount,
        ];
    }

    /**
     * Process a single payment for settlement.
     *
     * Checks against both returns report and processed batches.
     *
     * @param  Payment  $payment  The processing payment
     * @param  array  $returnRows  All return report rows
     * @param  array  $returnsByEntryId  Returns indexed by EntryID
     * @param  array  $settledEntries  Settled entries lookup from processed batches
     * @param  bool  $dryRun  Whether to skip actual updates
     */
    protected function processPaymentSettlement(Payment $payment, array $returnRows, array $returnsByEntryId, array $settledEntries, bool $dryRun): void
    {
        $accountNameId = $payment->metadata['kotapay_account_name_id'] ?? null;
        $effectiveDate = $payment->metadata['kotapay_effective_date'] ?? null;

        $this->line("Payment #{$payment->id} — \${$payment->total_amount} — ".($payment->customer?->name ?? 'Unknown').' — AccountNameId: '.($accountNameId ?? 'NONE'));

        if ($this->output->isVerbose()) {
            $this->line("  vendor_transaction_id: {$payment->vendor_transaction_id}");
            $this->line('  effective_date: '.($effectiveDate ?? 'unknown'));
        }

        // ── Check 1: Is this payment in the returns report? ──────────
        $matchedReturn = $this->findPaymentInReturns($payment, $returnRows, $returnsByEntryId);

        if ($matchedReturn !== null) {
            $returnCode = $matchedReturn['Code'] ?? 'Unknown';
            $returnReason = $matchedReturn['Reason'] ?? 'No reason provided';

            if ($dryRun) {
                $this->warn("  [DRY RUN] Would mark FAILED — Return {$returnCode}: {$returnReason}");
                $this->returned++;

                return;
            }

            $payment->update([
                'status' => Payment::STATUS_FAILED,
                'failure_reason' => "ACH Return {$returnCode}: {$returnReason}",
                'failed_at' => now(),
            ]);

            $metadata = $payment->metadata ?? [];
            $metadata['ach_return_code'] = $returnCode;
            $metadata['ach_return_reason'] = $returnReason;
            $metadata['ach_returned_at'] = now()->toIso8601String();
            $payment->update(['metadata' => $metadata]);

            $this->error("  [RETURNED] {$returnCode}: {$returnReason}");
            $this->returned++;

            $this->failedPayments[] = [
                'payment' => $payment,
                'reason' => "{$returnCode}: {$returnReason}",
                'status' => 'returned',
            ];

            Log::warning('ACH payment returned', [
                'payment_id' => $payment->id,
                'return_code' => $returnCode,
                'return_reason' => $returnReason,
                'account_name_id' => $accountNameId,
            ]);

            try {
                AdminAlertService::notifyAll(new AchReturnDetected(
                    $payment->transaction_id,
                    $payment->metadata['client_name'] ?? $payment->customer?->name ?? 'Unknown',
                    $payment->client_id ?? 'unknown',
                    (float) $payment->total_amount,
                    $returnCode,
                    $returnReason,
                    false
                ));
            } catch (\Exception $notifyEx) {
                Log::warning('Failed to send admin notification', ['error' => $notifyEx->getMessage()]);
            }

            $this->revertPlanTrackingOnReturn($payment);

            return;
        }

        // ── Check 2: Is this payment in a processed batch? ───────────
        $matchedBatch = $this->findPaymentInBatches($payment, $settledEntries);

        if ($matchedBatch !== null) {
            $batchEffective = $matchedBatch['_batch_effective_date'] ?? null;
            $batchId = $matchedBatch['_batch_id'] ?? null;
            $entryName = trim($matchedBatch['EntryName'] ?? '');
            $processedAt = $batchEffective ? Carbon::parse($batchEffective) : now();

            // Don't mark completed until the effective date has passed — the batch
            // is scheduled but money hasn't actually moved yet.
            if ($batchEffective && Carbon::parse($batchEffective)->isFuture()) {
                $this->line("  [BATCHED] Found in batch {$batchId} but effective date {$batchEffective} is in the future — waiting");
                $this->stillProcessing++;

                // Store the batch info so we don't have to re-discover it later
                $metadata = $payment->metadata ?? [];
                if (! isset($metadata['settlement_batch_id'])) {
                    $metadata['settlement_batch_id'] = $batchId;
                    $metadata['settlement_effective_date'] = $batchEffective;
                    $payment->update(['metadata' => $metadata]);
                }

                return;
            }

            if ($dryRun) {
                $this->info("  [DRY RUN] Would mark COMPLETED — confirmed in batch {$batchId}, effective {$batchEffective}");
                $this->settled++;

                return;
            }

            $payment->update([
                'status' => Payment::STATUS_COMPLETED,
                'processed_at' => $processedAt,
            ]);

            $metadata = $payment->metadata ?? [];
            $metadata['settled_via'] = 'processed_batch';
            $metadata['settlement_batch_id'] = $batchId;
            $metadata['settlement_effective_date'] = $batchEffective;
            $metadata['settlement_confirmed_at'] = now()->toIso8601String();
            $payment->update(['metadata' => $metadata]);

            $this->info("  [SETTLED] Confirmed in batch {$batchId} (effective: {$batchEffective}, entry: {$entryName})");
            $this->settled++;

            Log::info('ACH payment settled — confirmed via processed batch', [
                'payment_id' => $payment->id,
                'batch_id' => $batchId,
                'effective_date' => $batchEffective,
                'account_name_id' => $accountNameId,
            ]);

            $this->writeDeferredPracticeCs($payment);

            // Send receipt email now that ACH payment is confirmed settled
            $payment->sendReceipt();

            return;
        }

        // ── Not found in either report — still processing ────────────
        $age = Carbon::parse($payment->created_at)->diffInDays(now());
        $this->line("  [PROCESSING] Not yet in any batch or returns report (age: {$age} day(s))");
        $this->stillProcessing++;

        // Warn if payment is unusually old and still not in any batch
        if ($age > 7) {
            $this->warn("  [WARNING] Payment is {$age} days old and not confirmed in any batch — may need manual review");

            Log::warning('ACH payment not found in any batch after extended period', [
                'payment_id' => $payment->id,
                'age_days' => $age,
                'vendor_transaction_id' => $payment->vendor_transaction_id,
                'account_name_id' => $accountNameId,
            ]);
        }
    }

    /**
     * Find a payment in the processed batches lookup.
     *
     * Primary match: payment's kotapay_account_name_id === entry's EntryID.
     * Fallback match: normalized customer name + amount.
     *
     * @param  Payment  $payment  The payment to find
     * @param  array  $settledEntries  The settled entries lookup
     * @return array|null The matched batch entry, or null
     */
    protected function findPaymentInBatches(Payment $payment, array $settledEntries): ?array
    {
        $accountNameId = $payment->metadata['kotapay_account_name_id'] ?? null;

        // Primary match: by AccountNameId / EntryID
        if ($accountNameId !== null && isset($settledEntries['by_entry_id'][$accountNameId])) {
            if ($this->output->isVerbose()) {
                $this->line("  Batch matched by EntryID: {$accountNameId}");
            }

            return $settledEntries['by_entry_id'][$accountNameId];
        }

        // Fallback match: by customer name + amount
        $customerName = $payment->customer?->name ?? null;
        $amount = (float) $payment->total_amount;

        if ($customerName === null) {
            return null;
        }

        $normalizedName = $this->normalizeName($customerName);
        $key = $normalizedName.'|'.number_format($amount, 2, '.', '');

        if (isset($settledEntries['by_name_amount'][$key])) {
            $candidates = $settledEntries['by_name_amount'][$key];

            // If only one candidate, use it
            if (count($candidates) === 1) {
                if ($this->output->isVerbose()) {
                    $this->line("  Batch matched by name+amount: {$customerName} / \${$amount}");
                }

                Log::info('ACH payment matched via fallback (name+amount) in batch', [
                    'payment_id' => $payment->id,
                    'matched_name' => $customerName,
                    'matched_amount' => $amount,
                ]);

                return $candidates[0];
            }

            // Multiple candidates — try to narrow by routing number
            $routingNumber = $payment->metadata['routing_number'] ?? null;

            if ($routingNumber !== null) {
                foreach ($candidates as $candidate) {
                    $entryRouting = trim($candidate['RoutingNbr'] ?? '');

                    if ($entryRouting === $routingNumber) {
                        if ($this->output->isVerbose()) {
                            $this->line("  Batch matched by name+amount+routing: {$customerName} / \${$amount}");
                        }

                        return $candidate;
                    }
                }
            }

            // Still ambiguous — log warning and skip (don't guess)
            Log::warning('ACH payment has multiple batch candidates — skipping auto-match', [
                'payment_id' => $payment->id,
                'customer_name' => $customerName,
                'amount' => $amount,
                'candidate_count' => count($candidates),
            ]);
        }

        // Second fallback: prefix match on name + exact amount.
        // Handles cases where Kotapay truncates a name mid-suffix (e.g., "PLLC" → "PLL")
        // causing normalized names to diverge. We compare the first 12 chars of both names.
        $match = $this->prefixMatchInBatches($payment, $customerName, $amount, $settledEntries);
        if ($match !== null) {
            return $match;
        }

        return null;
    }

    /**
     * Attempt to match a payment by name prefix + amount across all batch entries.
     *
     * This is a secondary fallback for when exact normalized name matching fails,
     * typically because Kotapay truncated a business name mid-suffix. Compares the
     * first 12 characters of the customer name against batch entry names.
     *
     * Requires exactly one match to avoid ambiguity. Logs all matches for audit.
     *
     * @param  Payment  $payment  The payment to match
     * @param  string  $customerName  The payment's customer name
     * @param  float  $amount  The payment amount
     * @param  array  $settledEntries  The settled entries lookup
     * @return array|null The matched entry, or null
     */
    protected function prefixMatchInBatches(Payment $payment, string $customerName, float $amount, array $settledEntries): ?array
    {
        $paymentPrefix = $this->namePrefix($customerName);

        if (strlen($paymentPrefix) < 6) {
            // Name too short for reliable prefix matching
            return null;
        }

        $amountStr = number_format($amount, 2, '.', '');
        $candidates = [];

        foreach ($settledEntries['by_name_amount'] as $key => $entries) {
            // Key format is "NORMALIZED_NAME|AMOUNT"
            $parts = explode('|', $key, 2);
            if (count($parts) !== 2) {
                continue;
            }

            $entryAmount = $parts[1];
            if ($entryAmount !== $amountStr) {
                continue;
            }

            // Check if the entry name prefix matches our customer name prefix
            foreach ($entries as $entry) {
                $entryName = trim($entry['EntryName'] ?? '');
                $entryPrefix = $this->namePrefix($entryName);

                if ($paymentPrefix === $entryPrefix) {
                    $candidates[] = $entry;
                }
            }
        }

        if (count($candidates) === 1) {
            $entryName = trim($candidates[0]['EntryName'] ?? '');

            if ($this->output->isVerbose()) {
                $this->line("  Batch matched by name-prefix+amount: \"{$customerName}\" ~ \"{$entryName}\" / \${$amount}");
            }

            Log::info('ACH payment matched via prefix fallback in batch', [
                'payment_id' => $payment->id,
                'customer_name' => $customerName,
                'matched_entry_name' => $entryName,
                'matched_amount' => $amount,
                'prefix_used' => $paymentPrefix,
            ]);

            return $candidates[0];
        }

        if (count($candidates) > 1) {
            Log::warning('ACH payment prefix match found multiple candidates — skipping', [
                'payment_id' => $payment->id,
                'customer_name' => $customerName,
                'amount' => $amount,
                'candidate_count' => count($candidates),
            ]);
        }

        return null;
    }

    // =====================================================================
    // Job 2 — Post-settlement return monitoring
    // =====================================================================

    /**
     * Monitor recently completed payments for post-settlement returns.
     *
     * Banks can return ACH debits up to 60 days after settlement.
     * This checks completed Kotapay payments against the returns report.
     *
     * @param  ReportService  $reportService  Kotapay report service
     * @param  bool  $dryRun  Whether to skip actual updates
     * @param  int  $days  Number of days back to monitor
     */
    protected function monitorPostSettlementReturns(ReportService $reportService, bool $dryRun, int $days): void
    {
        $this->info('─── Job 2: Post-Settlement Return Monitoring ───');
        $this->newLine();

        $completedPayments = Payment::recentlyCompletedAch($days)->get();

        if ($completedPayments->isEmpty()) {
            $this->info("No completed Kotapay ACH payments in the last {$days} days.");
            $this->newLine();

            return;
        }

        $this->info("Monitoring {$completedPayments->count()} completed payment(s) for post-settlement returns.");

        // Fetch returns from the earliest processed_at date
        $earliestProcessed = $completedPayments->min('processed_at');
        $startDate = Carbon::parse($earliestProcessed)->format('Y-m-d');

        $this->line("Fetching returns report from {$startDate}...");

        $returnsReport = $reportService->getReturnsReport($startDate);
        $returnRows = $returnsReport['rows'] ?? [];

        // Guard: ensure rows is an array
        if (! is_array($returnRows)) {
            $this->warn('  Returns report returned non-array rows — skipping post-settlement check.');
            $this->newLine();

            return;
        }

        $this->line('Returns report: '.count($returnRows).' row(s).');
        $this->newLine();

        $returnsByEntryId = $this->indexReturnsByEntryId($returnRows);

        foreach ($completedPayments as $payment) {
            $matchedReturn = $this->findPaymentInReturns($payment, $returnRows, $returnsByEntryId);

            if ($matchedReturn === null) {
                continue;
            }

            $returnCode = $matchedReturn['Code'] ?? 'Unknown';
            $returnReason = $matchedReturn['Reason'] ?? 'No reason provided';
            $accountNameId = $payment->metadata['kotapay_account_name_id'] ?? 'none';

            if ($dryRun) {
                $this->warn("  Payment #{$payment->id} — [DRY RUN] Would mark RETURNED — {$returnCode}: {$returnReason}");
                $this->postSettlementReturned++;

                continue;
            }

            $payment->markAsReturned($returnCode, $returnReason);

            $this->error("  Payment #{$payment->id} — [POST-SETTLEMENT RETURN] {$returnCode}: {$returnReason}");
            $this->postSettlementReturned++;

            $this->failedPayments[] = [
                'payment' => $payment,
                'reason' => "POST-SETTLEMENT {$returnCode}: {$returnReason}",
                'status' => 'returned_post_settlement',
            ];

            Log::warning('ACH payment returned after settlement', [
                'payment_id' => $payment->id,
                'return_code' => $returnCode,
                'return_reason' => $returnReason,
                'account_name_id' => $accountNameId,
                'settled_at' => $payment->processed_at?->toIso8601String(),
            ]);

            try {
                AdminAlertService::notifyAll(new AchReturnDetected(
                    $payment->transaction_id,
                    $payment->metadata['client_name'] ?? $payment->customer?->name ?? 'Unknown',
                    $payment->client_id ?? 'unknown',
                    (float) $payment->total_amount,
                    $returnCode,
                    $returnReason,
                    true
                ));
            } catch (\Exception $notifyEx) {
                Log::warning('Failed to send admin notification', ['error' => $notifyEx->getMessage()]);
            }

            // Revert plan tracking for post-settlement return
            $this->revertPlanTrackingOnReturn($payment);
        }

        $this->newLine();
    }

    // =====================================================================
    // Job 3 — Corrections / NOC
    // =====================================================================

    /**
     * Check for ACH corrections (Notification of Change) and log them.
     *
     * NOC entries indicate that bank account details should be updated
     * for future transactions. This job logs them for manual review.
     *
     * @param  ReportService  $reportService  Kotapay report service
     * @param  bool  $dryRun  Whether this is a dry run
     */
    protected function checkCorrections(ReportService $reportService, bool $dryRun): void
    {
        $this->info('─── Job 3: Corrections / NOC ───');
        $this->newLine();

        // Check corrections for the last 30 days
        $startDate = Carbon::today()->subDays(30)->format('Y-m-d');

        try {
            $corrReport = $reportService->getCorrectionsReport($startDate);
            $corrRows = $corrReport['rows'] ?? [];

            // Guard: ensure rows is an array
            if (! is_array($corrRows)) {
                $this->warn('Corrections report returned non-array rows — skipping.');
                $this->newLine();

                return;
            }

            if (empty($corrRows)) {
                $this->info('No corrections/NOC entries found.');
                $this->newLine();

                return;
            }

            $this->warn("Found {$corrReport['rowCount']} correction(s)/NOC:");

            foreach ($corrRows as $row) {
                $entryId = isset($row['EntryID']) ? trim($row['EntryID']) : 'N/A';
                $code = $row['Code'] ?? $row['ChangeCode'] ?? 'N/A';
                $reason = $row['Reason'] ?? $row['ChangeReason'] ?? 'N/A';

                $this->line("  EntryID: {$entryId} — Code: {$code} — {$reason}");
                $this->corrections++;

                Log::info('ACH correction/NOC received', [
                    'entry_id' => $entryId,
                    'code' => $code,
                    'reason' => $reason,
                    'raw' => $row,
                ]);
            }
        } catch (KotapayException $e) {
            // Corrections are informational — don't fail the command
            $this->warn("Could not fetch corrections report: {$e->getMessage()}");

            Log::warning('Failed to fetch corrections report', [
                'error' => $e->getMessage(),
            ]);
        }

        $this->newLine();
    }

    // =====================================================================
    // Report Matching Logic
    // =====================================================================

    /**
     * Find a payment in the returns report.
     *
     * Primary match: payment's kotapay_account_name_id === row's EntryID.
     * Fallback match: amount + routing/account number, or amount + name prefix.
     *
     * @param  Payment  $payment  The payment to find
     * @param  array  $returnRows  All return report rows
     * @param  array  $returnsByEntryId  Returns indexed by EntryID
     * @return array|null The matched return row, or null if not found
     */
    protected function findPaymentInReturns(Payment $payment, array $returnRows, array $returnsByEntryId): ?array
    {
        $accountNameId = $payment->metadata['kotapay_account_name_id'] ?? null;

        // Primary match: by AccountNameId / EntryID
        if ($accountNameId !== null && isset($returnsByEntryId[$accountNameId])) {
            if ($this->output->isVerbose()) {
                $this->line("  Matched return by EntryID: {$accountNameId}");
            }

            return $returnsByEntryId[$accountNameId];
        }

        // Fallback match: for legacy payments without AccountNameId
        if ($accountNameId === null) {
            return $this->fallbackReturnMatch($payment, $returnRows);
        }

        return null;
    }

    /**
     * Attempt to match a legacy payment (no AccountNameId) against return rows.
     *
     * Matching strategies (in order of preference):
     *   1. Amount + routing/account number (most reliable)
     *   2. Amount + name prefix match (when no routing/account available)
     *
     * Requires a unique match to avoid false positives.
     *
     * @param  Payment  $payment  The legacy payment
     * @param  array  $returnRows  All return report rows
     * @return array|null The matched return row, or null
     */
    protected function fallbackReturnMatch(Payment $payment, array $returnRows): ?array
    {
        $amount = (float) $payment->total_amount;
        $routingNumber = $payment->metadata['routing_number'] ?? null;
        $accountNumber = $payment->metadata['account_number'] ?? null;

        if (empty($returnRows)) {
            return null;
        }

        // Strategy 1: Match by amount + routing/account
        if ($routingNumber !== null || $accountNumber !== null) {
            foreach ($returnRows as $row) {
                $rowRouting = isset($row['RoutingNbr']) ? trim($row['RoutingNbr']) : null;
                $rowAccount = isset($row['AccountNbr']) ? trim($row['AccountNbr']) : null;

                $rowDebit = abs((float) ($row['DebitAmt'] ?? 0));
                $rowCredit = (float) ($row['CreditAmt'] ?? 0);
                $rowAmount = $rowDebit > 0 ? $rowDebit : $rowCredit;

                if (abs($amount - $rowAmount) > 0.01) {
                    continue;
                }

                if ($routingNumber !== null && $rowRouting !== null && $routingNumber !== $rowRouting) {
                    continue;
                }

                if ($accountNumber !== null && $rowAccount !== null && $accountNumber !== $rowAccount) {
                    continue;
                }

                if ($this->output->isVerbose()) {
                    $this->line('  Fallback matched return by amount+routing/account');
                }

                Log::info('ACH payment matched via fallback in returns report', [
                    'payment_id' => $payment->id,
                    'matched_amount' => $rowAmount,
                    'matched_routing' => $rowRouting,
                ]);

                return $row;
            }
        }

        // Strategy 2: Match by amount + customer name prefix
        $customerName = $payment->customer?->name ?? null;
        if ($customerName === null) {
            return null;
        }

        $paymentPrefix = $this->namePrefix($customerName);
        if (strlen($paymentPrefix) < 6) {
            return null;
        }

        $candidates = [];
        foreach ($returnRows as $row) {
            $rowDebit = abs((float) ($row['DebitAmt'] ?? 0));
            $rowCredit = (float) ($row['CreditAmt'] ?? 0);
            $rowAmount = $rowDebit > 0 ? $rowDebit : $rowCredit;

            if (abs($amount - $rowAmount) > 0.01) {
                continue;
            }

            $entryName = isset($row['EntryName']) ? trim($row['EntryName']) : '';
            if ($entryName === '') {
                continue;
            }

            $entryPrefix = $this->namePrefix($entryName);
            if ($paymentPrefix === $entryPrefix) {
                $candidates[] = $row;
            }
        }

        if (count($candidates) === 1) {
            $entryName = trim($candidates[0]['EntryName'] ?? '');

            if ($this->output->isVerbose()) {
                $this->line("  Fallback matched return by name-prefix+amount: \"{$customerName}\" ~ \"{$entryName}\"");
            }

            Log::info('ACH payment matched via name-prefix fallback in returns report', [
                'payment_id' => $payment->id,
                'customer_name' => $customerName,
                'matched_entry_name' => $entryName,
                'matched_amount' => $amount,
            ]);

            return $candidates[0];
        }

        if (count($candidates) > 1) {
            Log::warning('ACH payment return prefix match found multiple candidates — skipping', [
                'payment_id' => $payment->id,
                'customer_name' => $customerName,
                'amount' => $amount,
                'candidate_count' => count($candidates),
            ]);
        }

        return null;
    }

    /**
     * Index return rows by EntryID for O(1) primary lookups.
     *
     * @param  array  $returnRows  Raw return report rows
     * @return array Map of EntryID → return row
     */
    protected function indexReturnsByEntryId(array $returnRows): array
    {
        $indexed = [];

        foreach ($returnRows as $row) {
            $entryId = isset($row['EntryID']) ? trim($row['EntryID']) : null;

            if ($entryId !== null && $entryId !== '') {
                $indexed[$entryId] = $row;
            }
        }

        return $indexed;
    }

    /**
     * Get the earliest payment date (created_at) among a set of payments.
     *
     * Used to determine the start date for report queries.
     * Falls back to 30 days ago if no payments exist (shouldn't happen).
     *
     * @param  Collection  $payments  The payments to scan
     * @return Carbon The earliest date
     */
    protected function getEarliestPaymentDate(Collection $payments): Carbon
    {
        $earliest = $payments->min('created_at');

        if ($earliest) {
            // Go back 1 extra day to catch any batches filed before the payment was created
            return Carbon::parse($earliest)->subDay();
        }

        return Carbon::today()->subDays(30);
    }

    /**
     * Normalize a name for fuzzy matching between our DB and Kotapay batch entries.
     *
     * Kotapay truncates names to ~22 chars and uppercases them, which can cut off
     * suffixes mid-word (e.g., "PLLC" becomes "PLL"). This normalizes both sides
     * by removing punctuation, known suffixes, and truncating to 20 chars.
     *
     * @param  string  $name  The name to normalize
     * @return string Normalized name (uppercase, no punctuation, trimmed, max 20 chars)
     */
    protected function normalizeName(string $name): string
    {
        $name = strtoupper($name);
        // Remove common punctuation
        $name = str_replace([',', '.', "'", '"', '&'], '', $name);
        // Remove trailing business suffixes (full and partial from Kotapay truncation)
        $name = preg_replace('/\s+(LLC|INC|PA|PLLC|PLL|DBA|MD|DDS|PC|CORP|LTD|LP|GP|ASSO?C?)\s*$/', '', $name);
        $name = preg_replace('/\s+/', ' ', $name);
        $name = trim($name);

        // Truncate to 20 chars to match Kotapay's truncation
        return substr($name, 0, 20);
    }

    /**
     * Extract a short prefix from a name for fuzzy prefix matching.
     *
     * Returns the first N significant characters (letters/numbers only, uppercased).
     * Used as a secondary match when exact normalization fails due to Kotapay
     * truncating names differently than we strip suffixes.
     *
     * @param  string  $name  The name to extract prefix from
     * @param  int  $length  Prefix length (default 12)
     * @return string The prefix
     */
    protected function namePrefix(string $name, int $length = 12): string
    {
        $name = strtoupper($name);
        $name = str_replace([',', '.', "'", '"', '&'], '', $name);
        $name = preg_replace('/\s+/', ' ', trim($name));

        return substr($name, 0, $length);
    }

    // =====================================================================
    // PracticeCS Integration
    // =====================================================================

    /**
     * Write deferred PracticeCS payment data for a settled ACH payment.
     *
     * Reads the stored payload from the payment's metadata['practicecs_data']
     * and writes it to PracticeCS via PracticeCsPaymentWriter::writeDeferredPayment().
     *
     * @param  Payment  $payment  The settled payment with deferred PracticeCS data
     */
    protected function writeDeferredPracticeCs(Payment $payment): void
    {
        $practiceCsData = $payment->metadata['practicecs_data'] ?? null;

        if (! $practiceCsData) {
            $this->warn("  [PracticeCS] No deferred data found for payment #{$payment->id}");

            Log::info('ACH payment settled but no PracticeCS deferred data', [
                'payment_id' => $payment->id,
            ]);

            return;
        }

        $this->line('  [PracticeCS] Writing deferred payment to PracticeCS...');

        try {
            $writer = app(PracticeCsPaymentWriter::class);
            $result = $writer->writeDeferredPayment($practiceCsData);

            if ($result['success']) {
                // Record that PracticeCS write succeeded
                $metadata = $payment->metadata;
                $metadata['practicecs_written_at'] = now()->toIso8601String();
                $metadata['practicecs_ledger_entry_KEY'] = $result['ledger_entry_KEY'] ?? null;
                $payment->update(['metadata' => $metadata]);

                // If this payment belongs to a plan, move amounts from pending to applied
                $this->updatePlanTrackingOnSettlement($payment);

                $this->info("  [PracticeCS] Written successfully (ledger_entry_KEY: {$result['ledger_entry_KEY']})");

                Log::info('PracticeCS: Deferred ACH payment written on settlement', [
                    'payment_id' => $payment->id,
                    'ledger_entry_KEY' => $result['ledger_entry_KEY'],
                ]);

                if (! empty($result['warning'])) {
                    $this->warn("  [PracticeCS] Warning: {$result['warning']}");

                    Log::warning('PracticeCS: Deferred write completed with warning', [
                        'payment_id' => $payment->id,
                        'warning' => $result['warning'],
                    ]);
                }
            } else {
                $this->error("  [PracticeCS] Write failed: {$result['error']}");

                Log::error('PracticeCS: Deferred ACH payment write failed', [
                    'payment_id' => $payment->id,
                    'error' => $result['error'],
                ]);

                try {
                    AdminAlertService::notifyAll(new PracticeCsWriteFailed(
                        $payment->transaction_id,
                        $payment->client_id ?? 'unknown',
                        (float) $payment->total_amount,
                        $result['error'],
                        'ach_deferred'
                    ));
                } catch (\Exception $notifyEx) {
                    Log::warning('Failed to send admin notification', ['error' => $notifyEx->getMessage()]);
                }
            }
        } catch (\Exception $e) {
            $this->error("  [PracticeCS] Exception: {$e->getMessage()}");

            Log::error('PracticeCS: Exception writing deferred ACH payment', [
                'payment_id' => $payment->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
        }
    }

    // =====================================================================
    // Plan Tracking
    // =====================================================================

    /**
     * Update payment plan tracking after a plan payment settles.
     *
     * Moves allocated amounts from practicecs_pending to practicecs_applied
     * in the plan's metadata. Only applies to payments that belong to a plan.
     *
     * @param  Payment  $payment  The settled payment
     */
    protected function updatePlanTrackingOnSettlement(Payment $payment): void
    {
        if (! $payment->payment_plan_id) {
            return;
        }

        $increments = $payment->metadata['practicecs_increments'] ?? null;
        if (! $increments) {
            return;
        }

        try {
            $paymentPlan = PaymentPlan::find($payment->payment_plan_id);
            if (! $paymentPlan) {
                Log::warning('PracticeCS: Payment plan not found for settlement tracking', [
                    'payment_id' => $payment->id,
                    'payment_plan_id' => $payment->payment_plan_id,
                ]);

                return;
            }

            PracticeCsPaymentWriter::settlePlanTracking($paymentPlan, $increments);

            $this->line("  [PracticeCS] Plan tracking updated (pending -> applied) for plan {$paymentPlan->plan_id}");

            Log::info('PracticeCS: Plan tracking updated on ACH settlement', [
                'payment_id' => $payment->id,
                'plan_id' => $paymentPlan->plan_id,
                'increments' => $increments,
            ]);
        } catch (\Exception $e) {
            Log::error('PracticeCS: Failed to update plan tracking on settlement', [
                'payment_id' => $payment->id,
                'payment_plan_id' => $payment->payment_plan_id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Revert payment plan tracking after an ACH return/rejection.
     *
     * Removes allocated amounts from practicecs_pending in the plan's metadata,
     * freeing those invoice allocations for future payments.
     *
     * @param  Payment  $payment  The returned/failed payment
     */
    protected function revertPlanTrackingOnReturn(Payment $payment): void
    {
        if (! $payment->payment_plan_id) {
            return;
        }

        $increments = $payment->metadata['practicecs_increments'] ?? null;
        if (! $increments) {
            return;
        }

        try {
            $paymentPlan = PaymentPlan::find($payment->payment_plan_id);
            if (! $paymentPlan) {
                Log::warning('PracticeCS: Payment plan not found for return tracking revert', [
                    'payment_id' => $payment->id,
                    'payment_plan_id' => $payment->payment_plan_id,
                ]);

                return;
            }

            PracticeCsPaymentWriter::revertPlanTracking($paymentPlan, $increments);

            $this->line("  [PracticeCS] Plan tracking reverted (pending removed) for plan {$paymentPlan->plan_id}");

            Log::info('PracticeCS: Plan tracking reverted on ACH return', [
                'payment_id' => $payment->id,
                'plan_id' => $paymentPlan->plan_id,
                'increments' => $increments,
            ]);
        } catch (\Exception $e) {
            Log::error('PracticeCS: Failed to revert plan tracking on return', [
                'payment_id' => $payment->id,
                'payment_plan_id' => $payment->payment_plan_id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    // =====================================================================
    // Notifications
    // =====================================================================

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

                    $preSettlement = array_filter($this->failedPayments, fn ($item) => $item['status'] === 'returned');
                    $postSettlement = array_filter($this->failedPayments, fn ($item) => $item['status'] === 'returned_post_settlement');

                    $mail = (new MailMessage)
                        ->subject("[{$appName}] {$count} ACH Payment(s) Returned/Failed")
                        ->error()
                        ->greeting('ACH Payment Returns Detected')
                        ->line("{$count} ACH payment(s) were returned or rejected by the bank.");

                    if (! empty($postSettlement)) {
                        $mail->line('')
                            ->line('**POST-SETTLEMENT RETURNS (require manual review):**');

                        foreach ($postSettlement as $item) {
                            $payment = $item['payment'];
                            $metadata = $payment->metadata ?? [];
                            $clientName = $metadata['client_name'] ?? ($payment->customer?->name ?? 'Unknown');

                            $mail->line("- **{$clientName}** — \${$payment->total_amount} — {$item['reason']}");
                        }
                    }

                    if (! empty($preSettlement)) {
                        $mail->line('')
                            ->line('**PRE-SETTLEMENT RETURNS:**');

                        foreach ($preSettlement as $item) {
                            $payment = $item['payment'];
                            $metadata = $payment->metadata ?? [];
                            $clientName = $metadata['client_name'] ?? ($payment->customer?->name ?? 'Unknown');

                            $mail->line("- **{$clientName}** — \${$payment->total_amount} — {$item['reason']}");
                        }
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

    // =====================================================================
    // Helpers
    // =====================================================================

    /**
     * Check if a transaction ID is a fallback/fake ID.
     *
     * Fallback IDs are generated locally when Kotapay doesn't return
     * a real transaction ID. They start with 'ach_'.
     */
    protected function isFallbackTransactionId(?string $transactionId): bool
    {
        if (empty($transactionId)) {
            return true;
        }

        return str_starts_with($transactionId, 'ach_');
    }

    /**
     * Print the summary table at the end of the run.
     */
    protected function printSummary(): void
    {
        $this->newLine();
        $this->info('═══ Summary ═══');
        $this->table(
            ['Status', 'Count'],
            [
                ['Settled (confirmed via batch)', $this->settled],
                ['Returned (pre-settlement)', $this->returned],
                ['Returned (post-settlement)', $this->postSettlementReturned],
                ['Still Processing', $this->stillProcessing],
                ['Corrections/NOC', $this->corrections],
                ['Errors', $this->errors],
            ]
        );
    }
}
