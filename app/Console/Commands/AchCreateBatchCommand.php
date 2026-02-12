<?php

namespace App\Console\Commands;

use App\Models\Payment;
use App\Services\Ach\AchBatchService;
use Illuminate\Console\Command;

class AchCreateBatchCommand extends Command
{
    protected $signature = 'ach:create-batch 
                            {--date= : Effective entry date (Y-m-d format, defaults to next business day)}
                            {--sec-code=WEB : SEC code (WEB, PPD, CCD, TEL)}
                            {--description=PAYMENT : Entry description (max 10 chars)}
                            {--dry-run : Show what would be batched without creating}';

    protected $description = 'Create an ACH batch from pending ACH payments';

    public function handle(AchBatchService $achService): int
    {
        $this->info('ACH Batch Creation');
        $this->line('==================');

        // Determine effective date
        $effectiveDate = $this->option('date')
            ? new \DateTime($this->option('date'))
            : $achService->calculateEffectiveDate();

        $this->info('Effective Entry Date: '.$effectiveDate->format('Y-m-d'));

        // Find pending ACH payments
        $pendingPayments = Payment::where('payment_method', 'ach')
            ->where('status', 'pending')
            ->whereNull('ach_entry_id')
            ->with(['customer', 'customerPaymentMethod'])
            ->get();

        if ($pendingPayments->isEmpty()) {
            $this->warn('No pending ACH payments found.');

            return Command::SUCCESS;
        }

        $this->info("Found {$pendingPayments->count()} pending ACH payment(s)");

        if ($this->option('dry-run')) {
            $this->table(
                ['ID', 'Customer', 'Amount', 'Account (last 4)'],
                $pendingPayments->map(fn ($p) => [
                    $p->id,
                    $p->customer?->name ?? 'N/A',
                    '$'.number_format($p->amount, 2),
                    $p->customerPaymentMethod?->account_last_four ?? 'N/A',
                ])
            );
            $this->warn('Dry run - no batch created.');

            return Command::SUCCESS;
        }

        // Create the batch
        $batch = $achService->createBatch(
            $effectiveDate,
            $this->option('sec-code'),
            $this->option('description')
        );

        $this->info("Created batch: {$batch->batch_number}");

        $successCount = 0;
        $errorCount = 0;

        foreach ($pendingPayments as $payment) {
            $paymentMethod = $payment->customerPaymentMethod;

            if (! $paymentMethod || $paymentMethod->type !== 'ach') {
                $this->error("Payment {$payment->id}: No valid ACH payment method");
                $errorCount++;

                continue;
            }

            try {
                $entry = $achService->addDebitEntry(
                    batch: $batch,
                    routingNumber: $paymentMethod->routing_number,
                    accountNumber: $paymentMethod->account_number,
                    amount: $payment->amount,
                    name: $payment->customer?->name ?? $paymentMethod->account_holder_name,
                    accountType: $paymentMethod->account_type ?? 'checking',
                    payment: $payment,
                    customer: $payment->customer,
                    individualId: (string) $payment->id
                );

                // Link payment to entry
                $payment->update(['ach_entry_id' => $entry->id]);

                $this->line("  Added: {$payment->customer?->name} - \${$payment->amount}");
                $successCount++;
            } catch (\Exception $e) {
                $this->error("Payment {$payment->id}: {$e->getMessage()}");
                $errorCount++;
            }
        }

        $this->newLine();
        $this->info("Batch {$batch->batch_number} Summary:");
        $this->line("  Entries: {$successCount}");
        $this->line("  Errors: {$errorCount}");
        $this->line('  Total Debit: $'.number_format($batch->total_debit_dollars, 2));

        if ($successCount > 0) {
            $this->newLine();
            $this->info('Batch created successfully.');
        }

        return $errorCount > 0 ? Command::FAILURE : Command::SUCCESS;
    }
}
