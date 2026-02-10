<?php

namespace App\Console\Commands;

use App\Models\Ach\AchFile;
use App\Services\Ach\AchFileService;
use Illuminate\Console\Command;

class AchDownloadFileCommand extends Command
{
    protected $signature = 'ach:download-file 
                            {file? : File ID to download}
                            {--output= : Output path (defaults to current directory)}
                            {--list : List all generated files}
                            {--show : Display file contents to stdout}';

    protected $description = 'Download/display a generated NACHA file';

    public function handle(AchFileService $achService): int
    {
        if ($this->option('list')) {
            return $this->listFiles();
        }

        $fileId = $this->argument('file');

        if (! $fileId) {
            $this->warn('Please specify a file ID or use --list to see available files.');
            $this->line('Usage: php artisan ach:download-file {file_id}');

            return $this->listFiles();
        }

        $achFile = AchFile::find($fileId);

        if (! $achFile) {
            $this->error("File ID {$fileId} not found.");

            return Command::FAILURE;
        }

        if ($achFile->status === AchFile::STATUS_PENDING) {
            $this->error("File {$achFile->filename} has not been generated yet.");

            return Command::FAILURE;
        }

        try {
            $contents = $achService->getFileContents($achFile);

            if ($this->option('show')) {
                $this->line($contents);

                return Command::SUCCESS;
            }

            // Determine output path
            $outputPath = $this->option('output') ?? getcwd();
            $fullPath = rtrim($outputPath, '/').'/'.$achFile->filename;

            file_put_contents($fullPath, $contents);

            $this->info("File saved to: {$fullPath}");
            $this->table(
                ['Property', 'Value'],
                [
                    ['Filename', $achFile->filename],
                    ['Size', strlen($contents).' bytes'],
                    ['Batches', $achFile->batch_count],
                    ['Entries', $achFile->entry_addenda_count],
                    ['Total Debit', '$'.number_format($achFile->total_debit_dollars, 2)],
                    ['Generated', $achFile->generated_at?->format('Y-m-d H:i:s')],
                ]
            );

            return Command::SUCCESS;
        } catch (\Exception $e) {
            $this->error("Failed to retrieve file: {$e->getMessage()}");

            return Command::FAILURE;
        }
    }

    protected function listFiles(): int
    {
        $files = AchFile::whereIn('status', [
            AchFile::STATUS_GENERATED,
            AchFile::STATUS_SUBMITTED,
            AchFile::STATUS_ACCEPTED,
            AchFile::STATUS_COMPLETED,
        ])
            ->orderBy('created_at', 'desc')
            ->limit(20)
            ->get();

        if ($files->isEmpty()) {
            $this->warn('No generated files found.');

            return Command::SUCCESS;
        }

        $this->info('Generated ACH Files:');
        $this->table(
            ['ID', 'Filename', 'Status', 'Batches', 'Entries', 'Debit Total', 'Generated'],
            $files->map(fn ($f) => [
                $f->id,
                $f->filename,
                $f->status,
                $f->batch_count,
                $f->entry_addenda_count,
                '$'.number_format($f->total_debit_dollars, 2),
                $f->generated_at?->format('Y-m-d H:i'),
            ])
        );

        return Command::SUCCESS;
    }
}
