<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

/**
 * Backup the SQLite database.
 */
class BackupDatabase extends Command
{
    protected $signature = 'db:backup {--name= : Optional backup name}';

    protected $description = 'Create a backup of the SQLite database';

    public function handle(): int
    {
        $source = database_path('database.sqlite');
        $backupDir = database_path('backups');

        if (! file_exists($source)) {
            $this->error('Database file not found: '.$source);

            return 1;
        }

        if (! is_dir($backupDir)) {
            mkdir($backupDir, 0755, true);
        }

        $name = $this->option('name') ?: now()->format('Y-m-d_H-i-s');
        $backup = "{$backupDir}/database_{$name}.sqlite";

        if (copy($source, $backup)) {
            $this->info("Backup created: {$backup}");
            $this->info('Size: '.number_format(filesize($backup) / 1024, 2).' KB');

            return 0;
        }

        $this->error('Failed to create backup');

        return 1;
    }
}
