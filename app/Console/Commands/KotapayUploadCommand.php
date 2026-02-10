<?php

namespace App\Console\Commands;

use App\Models\Ach\AchFile;
use App\Services\Kotapay\KotapayApiService;
use Illuminate\Console\Command;

class KotapayUploadCommand extends Command
{
    protected $signature = 'kotapay:upload 
                            {file? : ACH File ID to upload}
                            {--test : Submit as test file (will not be processed)}
                            {--all : Upload all generated files that haven\'t been submitted}';

    protected $description = 'Upload ACH file(s) to Kotapay via API';

    public function handle(KotapayApiService $kotapay): int
    {
        $this->info('Kotapay ACH File Upload');
        $this->line('=======================');
        $this->newLine();

        $fileId = $this->argument('file');
        $uploadAll = $this->option('all');
        $isTest = $this->option('test');

        if (! $fileId && ! $uploadAll) {
            // Show available files
            $files = AchFile::where('status', AchFile::STATUS_GENERATED)
                ->orderBy('created_at', 'desc')
                ->get();

            if ($files->isEmpty()) {
                $this->warn('No files ready for upload.');
                $this->line('Generate a file first with: php artisan ach:generate-file --all');

                return Command::SUCCESS;
            }

            $this->info('Files ready for upload:');
            $this->table(
                ['ID', 'Filename', 'Batches', 'Entries', 'Total Debit', 'Created'],
                $files->map(fn ($f) => [
                    $f->id,
                    $f->filename,
                    $f->batch_count,
                    $f->entry_addenda_count,
                    '$'.number_format($f->total_debit_dollars, 2),
                    $f->created_at->format('Y-m-d H:i'),
                ])
            );

            $this->newLine();
            $this->line('Usage:');
            $this->line('  php artisan kotapay:upload {file_id}     - Upload specific file');
            $this->line('  php artisan kotapay:upload --all         - Upload all pending files');
            $this->line('  php artisan kotapay:upload {file_id} --test  - Upload as test (not processed)');

            return Command::SUCCESS;
        }

        // Test connection first
        $this->info('Testing API connection...');
        try {
            $kotapay->getAccessToken();
            $this->info('✓ Connected to Kotapay API');
        } catch (\Exception $e) {
            $this->error('✗ API connection failed: '.$e->getMessage());

            return Command::FAILURE;
        }

        $this->newLine();

        // Get files to upload
        if ($uploadAll) {
            $files = AchFile::where('status', AchFile::STATUS_GENERATED)->get();
        } else {
            $file = AchFile::find($fileId);
            if (! $file) {
                $this->error("File ID {$fileId} not found.");

                return Command::FAILURE;
            }
            if ($file->status !== AchFile::STATUS_GENERATED) {
                $this->error("File is not in 'generated' status. Current status: {$file->status}");

                return Command::FAILURE;
            }
            $files = collect([$file]);
        }

        if ($files->isEmpty()) {
            $this->warn('No files to upload.');

            return Command::SUCCESS;
        }

        $this->info('Uploading '.$files->count().' file(s)...');
        if ($isTest) {
            $this->warn('TEST MODE: Files will not be processed by Kotapay');
        }
        $this->newLine();

        $successCount = 0;
        $failCount = 0;

        foreach ($files as $file) {
            $this->line("Uploading: {$file->filename}");

            try {
                $result = $kotapay->uploadAchFile($file, $isTest);

                $this->info('  ✓ Uploaded successfully!');
                $this->line("    Reference: {$result['ref_num']}");

                if ($result['is_duplicate']) {
                    $this->warn('    Warning: File was flagged as duplicate');
                }
                if ($result['invalid_chars'] > 0) {
                    $this->warn("    Warning: {$result['invalid_chars']} invalid characters detected");
                }

                $successCount++;
            } catch (\Exception $e) {
                $this->error("  ✗ Upload failed: {$e->getMessage()}");
                $failCount++;
            }

            $this->newLine();
        }

        // Summary
        $this->info('Upload complete!');
        $this->table(['Result', 'Count'], [
            ['Successful', $successCount],
            ['Failed', $failCount],
        ]);

        return $failCount > 0 ? Command::FAILURE : Command::SUCCESS;
    }
}
