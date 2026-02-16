<?php

namespace App\Console\Commands;

use App\Models\Payment;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * One-time command to backfill PracticeCS metadata for payments
 * that were successfully written to PracticeCS but whose metadata
 * was not persisted due to a tracking bug.
 *
 * Evidence source: laravel.log entries showing successful PracticeCS
 * Ledger_Entry creation and payment writes, cross-referenced with
 * payment IDs and transaction IDs.
 *
 * Usage:
 *   php artisan practicecs:backfill-metadata --dry-run   (preview only)
 *   php artisan practicecs:backfill-metadata              (apply changes)
 */
class BackfillPracticeCsMetadata extends Command
{
    protected $signature = 'practicecs:backfill-metadata {--dry-run : Preview changes without writing}';

    protected $description = 'Backfill PracticeCS metadata for payments written to PCS but missing tracking fields';

    /**
     * Log-verified payment data: payment_id => backfill fields.
     *
     * Each entry was verified against laravel.log entries showing
     * "PracticeCS: Payment written successfully" with matching
     * ledger_entry_KEY, client_KEY, and amount.
     *
     * @var array<int, array{ledger_entry_KEY: string, written_at: string, log_line: int}>
     */
    private const BACKFILL_DATA = [
        1 => [
            'ledger_entry_KEY' => '557158',
            'written_at' => '2026-02-06T23:07:23+00:00',
            'log_line' => 36672,
        ],
        67 => [
            'ledger_entry_KEY' => '557867',
            'written_at' => '2026-02-12T14:41:12+00:00',
            'log_line' => 44750,
        ],
        68 => [
            'ledger_entry_KEY' => '557873',
            'written_at' => '2026-02-12T16:17:36+00:00',
            'log_line' => 44784,
        ],
        73 => [
            'ledger_entry_KEY' => '557994',
            'written_at' => '2026-02-13T15:37:30+00:00',
            'log_line' => 45170,
        ],
        96 => [
            'ledger_entry_KEY' => '558007',
            'written_at' => '2026-02-14T07:00:54+00:00',
            'log_line' => 46669,
        ],
        97 => [
            'ledger_entry_KEY' => '558008',
            'written_at' => '2026-02-14T07:01:03+00:00',
            'log_line' => 46674,
        ],
        99 => [
            'ledger_entry_KEY' => '558009',
            'written_at' => '2026-02-14T07:01:09+00:00',
            'log_line' => 46702,
        ],
        163 => [
            'ledger_entry_KEY' => '558055',
            'written_at' => '2026-02-15T07:01:18+00:00',
            'log_line' => 48337,
        ],
        165 => [
            'ledger_entry_KEY' => '558056',
            'written_at' => '2026-02-15T07:01:23+00:00',
            'log_line' => 48365,
        ],
    ];

    public function handle(): int
    {
        $isDryRun = $this->option('dry-run');
        $mode = $isDryRun ? 'DRY RUN' : 'LIVE';

        $this->info("=== PracticeCS Metadata Backfill ({$mode}) ===");
        $this->newLine();

        Log::info("PracticeCS metadata backfill started", ['mode' => $mode]);

        $updated = 0;
        $skipped = 0;
        $errors = 0;

        foreach (self::BACKFILL_DATA as $paymentId => $data) {
            $payment = Payment::find($paymentId);

            if (! $payment) {
                $this->error("Payment #{$paymentId}: NOT FOUND — skipping");
                Log::error("Backfill: Payment not found", ['payment_id' => $paymentId]);
                $errors++;

                continue;
            }

            // Safety check: don't overwrite if already tracked
            $existingMeta = $payment->metadata ?? [];
            if (isset($existingMeta['practicecs_written_at'])) {
                $this->warn("Payment #{$paymentId}: Already has practicecs_written_at={$existingMeta['practicecs_written_at']} — skipping");
                $skipped++;

                continue;
            }

            // Merge new PCS tracking fields into existing metadata
            $newMeta = array_merge($existingMeta, [
                'practicecs_written_at' => $data['written_at'],
                'practicecs_ledger_entry_KEY' => $data['ledger_entry_KEY'],
                'practicecs_type' => 'payment',
                'practicecs_backfilled' => true,
                'practicecs_backfill_note' => 'Backfilled from log evidence (line ' . $data['log_line'] . '). PCS write succeeded but metadata was not persisted due to tracking bug.',
            ]);

            $metaBefore = $payment->metadata ? json_encode($payment->metadata) : 'NULL';

            if ($isDryRun) {
                $this->info("Payment #{$paymentId} (\${$payment->amount}):");
                $this->line("  Before: {$metaBefore}");
                $this->line("  After:  " . json_encode($newMeta));
                $this->line("  Ledger Entry KEY: {$data['ledger_entry_KEY']}");
                $this->line("  Written at: {$data['written_at']}");
                $this->newLine();
            } else {
                try {
                    $payment->metadata = $newMeta;
                    $payment->save();

                    // Verify the save persisted
                    $payment->refresh();
                    $verified = isset($payment->metadata['practicecs_written_at']);

                    if ($verified) {
                        $this->info("Payment #{$paymentId} (\${$payment->amount}): Updated — ledger_entry_KEY={$data['ledger_entry_KEY']} ✓");
                        Log::info("Backfill: Payment metadata updated", [
                            'payment_id' => $paymentId,
                            'ledger_entry_KEY' => $data['ledger_entry_KEY'],
                            'written_at' => $data['written_at'],
                            'meta_before' => $metaBefore,
                        ]);
                    } else {
                        $this->error("Payment #{$paymentId}: Save did NOT persist! Metadata after refresh: " . json_encode($payment->metadata));
                        Log::error("Backfill: Metadata save failed to persist", [
                            'payment_id' => $paymentId,
                            'metadata_after' => $payment->metadata,
                        ]);
                        $errors++;

                        continue;
                    }
                } catch (\Exception $e) {
                    $this->error("Payment #{$paymentId}: FAILED — {$e->getMessage()}");
                    Log::error("Backfill: Exception updating payment", [
                        'payment_id' => $paymentId,
                        'error' => $e->getMessage(),
                    ]);
                    $errors++;

                    continue;
                }
            }

            $updated++;
        }

        $this->newLine();
        $this->info("=== Summary ({$mode}) ===");
        $this->info("Updated: {$updated}");
        $this->info("Skipped (already tracked): {$skipped}");
        $this->info("Errors: {$errors}");

        Log::info("PracticeCS metadata backfill completed", [
            'mode' => $mode,
            'updated' => $updated,
            'skipped' => $skipped,
            'errors' => $errors,
        ]);

        return $errors > 0 ? self::FAILURE : self::SUCCESS;
    }
}
