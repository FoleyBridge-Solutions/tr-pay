<?php

namespace App\Console\Commands;

use App\Models\Payment;
use App\Services\PaymentService;
use Illuminate\Console\Command;

class VoidDuplicatePayments extends Command
{
    protected $signature = 'payments:void-duplicates
                            {--ids= : Comma-separated payment IDs to void/refund}
                            {--dry-run : Preview what would happen without making changes}
                            {--reason=Duplicate payment during BizPayO migration : Reason for void/refund}';

    protected $description = 'Void or refund duplicate payments (ACH=void, Card=refund)';

    public function handle(PaymentService $paymentService): int
    {
        $dryRun = $this->option('dry-run');
        $reason = $this->option('reason');
        $ids = $this->option('ids');

        if ($dryRun) {
            $this->warn('DRY RUN MODE - No changes will be made.');
            $this->newLine();
        }

        // Get target payments
        if ($ids) {
            $paymentIds = array_map('intval', explode(',', $ids));
            $payments = Payment::with('customer')->whereIn('id', $paymentIds)->get();
        } else {
            // Default: show all voidable/refundable payments
            $payments = Payment::with('customer')
                ->where(function ($q) {
                    $q->where(function ($sub) {
                        // Processing ACH payments (voidable)
                        $sub->where('status', Payment::STATUS_PROCESSING)
                            ->where('payment_vendor', 'kotapay');
                    })->orWhere(function ($sub) {
                        // Completed card payments (refundable)
                        $sub->where('status', Payment::STATUS_COMPLETED)
                            ->whereNull('payment_vendor');
                    });
                })
                ->get();
        }

        if ($payments->isEmpty()) {
            $this->info('No payments found to void/refund.');

            return self::SUCCESS;
        }

        // Display payments
        $this->info('Payments to process:');
        $this->newLine();

        $rows = $payments->map(function (Payment $p) {
            $action = match (true) {
                $p->status === Payment::STATUS_PROCESSING && $p->payment_vendor === 'kotapay' => 'VOID (ACH)',
                $p->status === Payment::STATUS_COMPLETED => 'REFUND (Card)',
                default => 'SKIP',
            };

            return [
                $p->id,
                $p->customer?->name ?? '-',
                '$'.number_format((float) $p->amount, 2),
                $p->status,
                $p->payment_method,
                $p->vendor_transaction_id ?? $p->transaction_id ?? '-',
                $action,
            ];
        })->toArray();

        $this->table(
            ['ID', 'Customer', 'Amount', 'Status', 'Method', 'Transaction ID', 'Action'],
            $rows
        );

        $voidable = $payments->filter(fn (Payment $p) => $p->status === Payment::STATUS_PROCESSING && $p->payment_vendor === 'kotapay');
        $refundable = $payments->filter(fn (Payment $p) => $p->status === Payment::STATUS_COMPLETED && ! $p->payment_vendor);
        $skipped = $payments->filter(fn (Payment $p) => ! ($p->status === Payment::STATUS_PROCESSING && $p->payment_vendor === 'kotapay')
            && ! ($p->status === Payment::STATUS_COMPLETED && ! $p->payment_vendor));

        $this->newLine();
        $this->info("ACH to void: {$voidable->count()} ($".number_format($voidable->sum('amount'), 2).')');
        $this->info("Cards to refund: {$refundable->count()} ($".number_format($refundable->sum('amount'), 2).')');
        if ($skipped->count() > 0) {
            $this->warn("Skipping: {$skipped->count()} (not voidable/refundable)");
        }
        $this->newLine();

        if ($dryRun) {
            $this->warn('DRY RUN complete. No changes were made.');

            return self::SUCCESS;
        }

        if (! $this->confirm('Proceed with voiding/refunding these payments?')) {
            $this->info('Cancelled.');

            return self::SUCCESS;
        }

        $successCount = 0;
        $failCount = 0;

        // Process voidable ACH payments
        foreach ($voidable as $payment) {
            $this->line("Voiding ACH payment #{$payment->id} (\${$payment->amount})...");

            $result = $paymentService->voidAchPayment($payment, $reason);

            if ($result['success']) {
                $this->info("  Voided successfully.");
                $successCount++;
            } else {
                $this->error("  Failed: {$result['error']}");
                $failCount++;
            }
        }

        // Process refundable card payments
        foreach ($refundable as $payment) {
            $this->line("Refunding card payment #{$payment->id} (\${$payment->amount})...");

            $result = $paymentService->refundCardPayment($payment, $reason);

            if ($result['success']) {
                $this->info("  Refunded successfully.");
                $successCount++;
            } else {
                $this->error("  Failed: {$result['error']}");
                $failCount++;
            }
        }

        $this->newLine();
        $this->info("Done. {$successCount} succeeded, {$failCount} failed.");

        return $failCount > 0 ? self::FAILURE : self::SUCCESS;
    }
}
