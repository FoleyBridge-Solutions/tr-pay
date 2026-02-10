<?php

namespace App\Console\Commands;

use App\Models\Payment;
use App\Models\PaymentPlan;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Backfill client_id values in Payment and PaymentPlan records.
 *
 * The `client_key` column in both tables previously stored PracticeCS
 * `client_KEY` (integer surrogate key) values. This command resolves
 * each to the human-readable `client_id` value from PracticeCS and
 * updates the records.
 *
 * This command is idempotent — it skips records that already contain
 * a non-numeric client_id value (indicating they were already migrated
 * or created after the migration).
 */
class BackfillClientIds extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'payments:backfill-client-ids
                            {--dry-run : Preview changes without applying them}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Update Payment and PaymentPlan client_key columns from PracticeCS client_KEY to client_id';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $dryRun = $this->option('dry-run');

        if ($dryRun) {
            $this->info('DRY RUN — no changes will be made.');
        }

        // Build mappings from PracticeCS
        $this->info('Loading client mappings from PracticeCS...');
        $clients = DB::connection('sqlsrv')->select('SELECT client_KEY, client_id FROM Client');
        $keyToIdMap = [];    // client_KEY => client_id
        $validClientIds = []; // set of known client_id values
        foreach ($clients as $client) {
            $keyToIdMap[(string) $client->client_KEY] = $client->client_id;
            $validClientIds[(string) $client->client_id] = true;
        }
        $this->info('Loaded '.count($keyToIdMap).' clients from PracticeCS.');

        // Process Payments
        $this->info('');
        $this->info('=== Processing Payments ===');
        $paymentStats = $this->backfillModel(Payment::class, $keyToIdMap, $validClientIds, $dryRun);

        // Process PaymentPlans
        $this->info('');
        $this->info('=== Processing Payment Plans ===');
        $planStats = $this->backfillModel(PaymentPlan::class, $keyToIdMap, $validClientIds, $dryRun);

        // Summary
        $this->info('');
        $this->info('=== Summary ===');
        $this->table(
            ['Model', 'Total', 'Updated', 'Already Correct', 'Not Found', 'Skipped (null)'],
            [
                ['Payment', $paymentStats['total'], $paymentStats['updated'], $paymentStats['already_correct'], $paymentStats['not_found'], $paymentStats['null']],
                ['PaymentPlan', $planStats['total'], $planStats['updated'], $planStats['already_correct'], $planStats['not_found'], $planStats['null']],
            ]
        );

        if ($dryRun) {
            $this->warn('DRY RUN complete — no changes were made. Run without --dry-run to apply.');
        } else {
            $totalUpdated = $paymentStats['updated'] + $planStats['updated'];
            Log::info('BackfillClientIds completed', [
                'payments_updated' => $paymentStats['updated'],
                'plans_updated' => $planStats['updated'],
            ]);
            $this->info("Done! Updated {$totalUpdated} records total.");
        }

        return self::SUCCESS;
    }

    /**
     * Backfill client_key values for a given model.
     *
     * @param  class-string  $modelClass
     * @param  array<string, string>  $keyToIdMap  Mapping of client_KEY => client_id
     * @param  array<string, true>  $validClientIds  Set of known client_id values
     * @return array{total: int, updated: int, already_correct: int, not_found: int, null: int}
     */
    private function backfillModel(string $modelClass, array $keyToIdMap, array $validClientIds, bool $dryRun): array
    {
        $stats = ['total' => 0, 'updated' => 0, 'already_correct' => 0, 'not_found' => 0, 'null' => 0];

        $records = $modelClass::whereNotNull('client_key')->get();
        $stats['total'] = $records->count();

        $nullRecords = $modelClass::whereNull('client_key')->count();
        $stats['null'] = $nullRecords;
        $stats['total'] += $nullRecords;

        $this->info("Found {$records->count()} records with client_key values ({$nullRecords} with null).");

        foreach ($records as $record) {
            $currentValue = (string) $record->client_key;

            // If the value is NOT purely numeric, it's already a client_id — skip
            if (! ctype_digit($currentValue)) {
                $stats['already_correct']++;

                continue;
            }

            // If the numeric value exists as a client_id in PracticeCS, it's already correct
            if (isset($validClientIds[$currentValue])) {
                $stats['already_correct']++;
                $this->line("  Record #{$record->id}: {$currentValue} is already a valid client_id");

                continue;
            }

            // Look up the client_id for this client_KEY
            $clientId = $keyToIdMap[$currentValue] ?? null;

            if (! $clientId) {
                $stats['not_found']++;
                $this->warn("  Record #{$record->id}: client_KEY={$currentValue} not found in PracticeCS");

                continue;
            }

            if ($dryRun) {
                $this->line("  Record #{$record->id}: {$currentValue} => {$clientId}");
            } else {
                $record->client_key = $clientId;
                $record->save();
            }

            $stats['updated']++;
        }

        $action = $dryRun ? 'Would update' : 'Updated';
        $this->info("{$action} {$stats['updated']} records. Already correct: {$stats['already_correct']}. Not found: {$stats['not_found']}.");

        return $stats;
    }
}
