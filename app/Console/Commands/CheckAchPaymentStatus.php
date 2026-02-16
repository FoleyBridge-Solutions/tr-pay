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
 * Uses report-based reconciliation instead of per-transaction polling:
 *   Job 1 — Settle PROCESSING payments on their effective date (or mark failed if returned)
 *   Job 2 — Monitor COMPLETED payments for post-settlement returns (up to 60 days)
 *   Job 3 — Check corrections (NOC) and log them
 *
 * Matching logic:
 *   Primary:  payment.metadata.kotapay_account_name_id === report row EntryID
 *   Fallback: effective_date + total_amount + routing/account (for legacy payments)
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
     * Settle processing payments by checking them against the returns report.
     *
     * Logic:
     *   - If payment's EntryID is found in returns → STATUS_FAILED
     *   - If NOT in returns AND effective_date <= today → STATUS_COMPLETED
     *   - If NOT in returns AND effective_date > today → still PROCESSING
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

        // Determine the date range for the returns report.
        // We need returns from the earliest effective date among our processing payments.
        $earliestDate = $this->getEarliestEffectiveDate($payments);
        $startDate = $earliestDate->format('Y-m-d');

        $this->line("Fetching returns report from {$startDate}...");

        $returnsReport = $reportService->getReturnsReport($startDate);
        $returnRows = $returnsReport['rows'] ?? [];
        $this->line("Returns report: {$returnsReport['rowCount']} row(s).");
        $this->newLine();

        // Index returns by EntryID for fast primary lookup
        $returnsByEntryId = $this->indexReturnsByEntryId($returnRows);

        foreach ($payments as $payment) {
            $this->processPaymentSettlement($payment, $returnRows, $returnsByEntryId, $dryRun);
        }

        $this->newLine();
    }

    /**
     * Process a single payment for settlement against the returns report.
     *
     * @param  Payment  $payment  The processing payment
     * @param  array  $returnRows  All return report rows
     * @param  array  $returnsByEntryId  Returns indexed by EntryID
     * @param  bool  $dryRun  Whether to skip actual updates
     */
    protected function processPaymentSettlement(Payment $payment, array $returnRows, array $returnsByEntryId, bool $dryRun): void
    {
        $accountNameId = $payment->metadata['kotapay_account_name_id'] ?? null;
        $effectiveDate = $payment->metadata['kotapay_effective_date'] ?? null;

        $this->line("Payment #{$payment->id} — \${$payment->total_amount} — AccountNameId: ".($accountNameId ?? 'NONE'));

        if ($this->output->isVerbose()) {
            $this->line("  vendor_transaction_id: {$payment->vendor_transaction_id}");
            $this->line('  effective_date: '.($effectiveDate ?? 'unknown'));
            $this->line('  is_fallback_txn: '.($this->isFallbackTransactionId($payment->vendor_transaction_id) ? 'yes' : 'no'));
        }

        // Try to find this payment in the returns report
        $matchedReturn = $this->findPaymentInReturns($payment, $returnRows, $returnsByEntryId);

        if ($matchedReturn !== null) {
            // Payment was returned — mark as failed
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

            // Store return details in metadata
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

            Log::warning('ACH payment returned (pre-settlement)', [
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

            // Revert plan tracking if applicable
            $this->revertPlanTrackingOnReturn($payment);

            return;
        }

        // Not in returns — check if effective date has passed
        $today = Carbon::today();

        if ($effectiveDate === null) {
            // Legacy payment with no effective date stored.
            // Use created_at + 5 business days as a surrogate effective date.
            // ACH typically settles in 2-3 business days; 5 is conservative.
            $surrogateDate = Carbon::parse($payment->created_at)->addWeekdays(5);

            if ($surrogateDate->gt($today)) {
                // Still within the settlement window — give it time
                $daysUntil = $today->diffInDays($surrogateDate);
                $this->line("  [PROCESSING] No effective date — surrogate settlement in {$daysUntil} day(s)");
                $this->stillProcessing++;

                return;
            }

            // Surrogate date has passed and NOT in returns → settled by inference
            if ($dryRun) {
                $this->info("  [DRY RUN] Would mark COMPLETED — legacy payment, created {$payment->created_at->format('Y-m-d')}, not in returns");
                $this->settled++;

                return;
            }

            $payment->update([
                'status' => Payment::STATUS_COMPLETED,
                'processed_at' => $surrogateDate,
            ]);

            // Flag in metadata that this was settled by inference, not by confirmed effective date
            $metadata = $payment->metadata ?? [];
            $metadata['settled_by_inference'] = true;
            $metadata['surrogate_effective_date'] = $surrogateDate->format('Y-m-d');
            $payment->update(['metadata' => $metadata]);

            $this->info("  [SETTLED] Legacy payment — settled by inference (created: {$payment->created_at->format('Y-m-d')}, not in returns)");
            $this->settled++;

            Log::info('ACH payment settled by inference (no effective date, not in returns)', [
                'payment_id' => $payment->id,
                'created_at' => $payment->created_at->toIso8601String(),
                'surrogate_effective_date' => $surrogateDate->format('Y-m-d'),
                'vendor_transaction_id' => $payment->vendor_transaction_id,
            ]);

            // Write deferred PracticeCS data
            $this->writeDeferredPracticeCs($payment);

            return;
        }

        $effectiveDateCarbon = Carbon::parse($effectiveDate);

        if ($effectiveDateCarbon->lte($today)) {
            // Effective date has passed and NOT in returns → settled
            if ($dryRun) {
                $this->info("  [DRY RUN] Would mark COMPLETED — effective date {$effectiveDate} <= today");
                $this->settled++;

                return;
            }

            $payment->update([
                'status' => Payment::STATUS_COMPLETED,
                'processed_at' => $effectiveDateCarbon,
            ]);

            $this->info("  [SETTLED] Marked completed (effective: {$effectiveDate})");
            $this->settled++;

            Log::info('ACH payment settled via report reconciliation', [
                'payment_id' => $payment->id,
                'effective_date' => $effectiveDate,
                'account_name_id' => $accountNameId,
            ]);

            // Write deferred PracticeCS data
            $this->writeDeferredPracticeCs($payment);

            return;
        }

        // Effective date is in the future — still processing
        $daysUntil = $today->diffInDays($effectiveDateCarbon);
        $this->line("  [PROCESSING] Effective date {$effectiveDate} is {$daysUntil} day(s) away");
        $this->stillProcessing++;

        Log::info('ACH payment still processing — effective date in future', [
            'payment_id' => $payment->id,
            'effective_date' => $effectiveDate,
            'days_until' => $daysUntil,
        ]);
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
        $this->line("Returns report: {$returnsReport['rowCount']} row(s).");
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
     * Fallback match: effective_date + total_amount + routing/account number.
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
                $this->line("  Matched by EntryID: {$accountNameId}");
            }

            return $returnsByEntryId[$accountNameId];
        }

        // Fallback match: for legacy payments without AccountNameId
        // Match on effective date + amount (+ routing/account if available)
        if ($accountNameId === null) {
            return $this->fallbackMatch($payment, $returnRows);
        }

        return null;
    }

    /**
     * Attempt to match a legacy payment (no AccountNameId) against return rows.
     *
     * Matches on ALL of: effective date, debit amount, and optionally routing/account.
     * Requires an effective date to avoid false positives from amount-only matching.
     * Legacy payments without an effective date are handled by age-based auto-settlement
     * in processPaymentSettlement() instead.
     *
     * @param  Payment  $payment  The legacy payment
     * @param  array  $returnRows  All return report rows
     * @return array|null The matched return row, or null
     */
    protected function fallbackMatch(Payment $payment, array $returnRows): ?array
    {
        $effectiveDate = $payment->metadata['kotapay_effective_date'] ?? null;
        $amount = (float) $payment->total_amount;
        $routingNumber = $payment->metadata['routing_number'] ?? null;
        $accountNumber = $payment->metadata['account_number'] ?? null;

        // Without an effective date, fallback matching degrades to amount-only,
        // which risks false positives. Skip fallback and let age-based settlement handle it.
        if ($effectiveDate === null) {
            if ($this->output->isVerbose()) {
                $this->line('  Skipping fallback match — no effective date for reliable matching');
            }

            return null;
        }

        if (empty($returnRows)) {
            return null;
        }

        foreach ($returnRows as $row) {
            $rowDate = isset($row['EffectiveDate']) ? trim($row['EffectiveDate']) : null;
            $rowRouting = isset($row['RoutingNbr']) ? trim($row['RoutingNbr']) : null;
            $rowAccount = isset($row['AccountNbr']) ? trim($row['AccountNbr']) : null;

            // Use whichever amount is non-zero (debits use DebitAmt, but some
            // returns reverse the direction and populate CreditAmt instead)
            $rowDebit = (float) ($row['DebitAmt'] ?? 0);
            $rowCredit = (float) ($row['CreditAmt'] ?? 0);
            $rowAmount = $rowDebit > 0 ? $rowDebit : $rowCredit;

            // Date must match (if we have one)
            if ($effectiveDate !== null && $rowDate !== null && $rowDate !== '') {
                // Normalize both dates to Y-m-d for comparison
                $normalizedPayment = Carbon::parse($effectiveDate)->format('Y-m-d');
                $normalizedRow = Carbon::parse($rowDate)->format('Y-m-d');

                if ($normalizedPayment !== $normalizedRow) {
                    continue;
                }
            }

            // Amount must match (within 1 cent tolerance for rounding)
            if (abs($amount - $rowAmount) > 0.01) {
                continue;
            }

            // If we have routing/account, they must match too (trim for comparison)
            if ($routingNumber !== null && $rowRouting !== null && $routingNumber !== $rowRouting) {
                continue;
            }
            if ($accountNumber !== null && $rowAccount !== null && $accountNumber !== $rowAccount) {
                continue;
            }

            if ($this->output->isVerbose()) {
                $this->line('  Fallback matched by date+amount'.
                    ($routingNumber ? '+routing' : '').
                    ($accountNumber ? '+account' : ''));
            }

            Log::info('ACH payment matched via fallback (no AccountNameId)', [
                'payment_id' => $payment->id,
                'matched_effective_date' => $rowDate,
                'matched_amount' => $rowAmount,
            ]);

            return $row;
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
     * Get the earliest effective date among a set of payments.
     *
     * Falls back to 30 days ago if no payments have an effective date stored.
     *
     * @param  Collection  $payments  The payments to scan
     * @return Carbon The earliest date
     */
    protected function getEarliestEffectiveDate(Collection $payments): Carbon
    {
        $earliest = null;

        foreach ($payments as $payment) {
            $date = $payment->metadata['kotapay_effective_date'] ?? null;

            if ($date !== null) {
                $parsed = Carbon::parse($date);

                if ($earliest === null || $parsed->lt($earliest)) {
                    $earliest = $parsed;
                }
            }
        }

        // Fall back to 30 days ago if no effective dates stored (legacy payments)
        return $earliest ?? Carbon::today()->subDays(30);
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
                ['Settled (completed)', $this->settled],
                ['Returned (pre-settlement)', $this->returned],
                ['Returned (post-settlement)', $this->postSettlementReturned],
                ['Still Processing', $this->stillProcessing],
                ['Corrections/NOC', $this->corrections],
                ['Errors', $this->errors],
            ]
        );
    }
}
