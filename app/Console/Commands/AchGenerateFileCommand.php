<?php

namespace App\Console\Commands;

use App\Models\Ach\AchBatch;
use App\Services\Ach\AchFileService;
use Illuminate\Console\Command;

class AchGenerateFileCommand extends Command
{
    protected $signature = 'ach:generate-file 
                            {batch? : Specific batch ID to generate}
                            {--all : Generate file for all pending batches}
                            {--preview : Preview file contents without saving}';

    protected $description = 'Generate a NACHA file from ACH batch(es)';

    public function handle(AchFileService $achService): int
    {
        $this->info('ACH File Generation');
        $this->line('===================');

        $batchId = $this->argument('batch');
        $generateAll = $this->option('all');

        if (! $batchId && ! $generateAll) {
            // Show available batches
            $pendingBatches = AchBatch::whereIn('status', [AchBatch::STATUS_PENDING, AchBatch::STATUS_READY])
                ->whereNull('ach_file_id')
                ->withCount('entries')
                ->get();

            if ($pendingBatches->isEmpty()) {
                $this->warn('No pending batches found.');
                $this->line("Run 'php artisan ach:create-batch' to create a new batch.");

                return Command::SUCCESS;
            }

            $this->table(
                ['ID', 'Batch #', 'Status', 'Entries', 'Debit Total', 'Effective Date'],
                $pendingBatches->map(fn ($b) => [
                    $b->id,
                    $b->batch_number,
                    $b->status,
                    $b->entries_count,
                    '$'.number_format($b->total_debit_dollars, 2),
                    $b->effective_entry_date->format('Y-m-d'),
                ])
            );

            $this->newLine();
            $this->line('Usage:');
            $this->line('  php artisan ach:generate-file {batch_id}  - Generate file for specific batch');
            $this->line('  php artisan ach:generate-file --all      - Generate file for all pending batches');

            return Command::SUCCESS;
        }

        try {
            if ($generateAll) {
                $batches = AchBatch::whereIn('status', [AchBatch::STATUS_PENDING, AchBatch::STATUS_READY])
                    ->whereNull('ach_file_id')
                    ->where('entry_count', '>', 0)
                    ->get()
                    ->all();

                if (empty($batches)) {
                    $this->warn('No batches with entries found.');

                    return Command::SUCCESS;
                }

                $this->info('Generating file for '.count($batches).' batch(es)...');
                $achFile = $achService->generateFileForBatches($batches);
            } else {
                $batch = AchBatch::findOrFail($batchId);

                if ($batch->ach_file_id) {
                    $this->error("Batch {$batch->batch_number} already has a file generated.");

                    return Command::FAILURE;
                }

                if ($batch->entries()->count() === 0) {
                    $this->error("Batch {$batch->batch_number} has no entries.");

                    return Command::FAILURE;
                }

                $this->info("Generating file for batch {$batch->batch_number}...");
                $achFile = $achService->generateFile($batch);
            }

            if ($this->option('preview')) {
                $this->newLine();
                $this->line('=== NACHA File Preview ===');
                $this->line($achFile->file_contents);
                $this->line('=== End Preview ===');
            }

            $this->newLine();
            $this->info('File Generated Successfully!');
            $this->table(
                ['Property', 'Value'],
                [
                    ['File ID', $achFile->id],
                    ['Filename', $achFile->filename],
                    ['Batches', $achFile->batch_count],
                    ['Entries', $achFile->entry_addenda_count],
                    ['Total Debit', '$'.number_format($achFile->total_debit_dollars, 2)],
                    ['Total Credit', '$'.number_format($achFile->total_credit_dollars, 2)],
                    ['Status', $achFile->status],
                ]
            );

            $this->newLine();
            $this->info("Download with: php artisan ach:download-file {$achFile->id}");

            return Command::SUCCESS;
        } catch (\Exception $e) {
            $this->error("Generation failed: {$e->getMessage()}");

            return Command::FAILURE;
        }
    }
}
