<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

/**
 * Restore the SQLite database from a backup.
 */
class RestoreDatabase extends Command
{
    protected $signature = 'db:restore {backup? : Backup filename to restore (without path)}';

    protected $description = 'Restore the SQLite database from a backup';

    public function handle(): int
    {
        $backupDir = database_path('backups');
        $target = database_path('database.sqlite');

        $backups = glob("{$backupDir}/database_*.sqlite");

        if (empty($backups)) {
            $this->error('No backups found in '.$backupDir);

            return 1;
        }

        rsort($backups); // Most recent first

        if ($backup = $this->argument('backup')) {
            $backupPath = "{$backupDir}/{$backup}";
            if (! file_exists($backupPath)) {
                $this->error("Backup not found: {$backupPath}");

                return 1;
            }
        } else {
            $this->info('Available backups:');
            foreach (array_slice($backups, 0, 10) as $i => $b) {
                $this->line(sprintf(
                    '  [%d] %s (%s KB)',
                    $i + 1,
                    basename($b),
                    number_format(filesize($b) / 1024, 2)
                ));
            }

            $choice = $this->ask('Enter backup number to restore (or filename)');

            if (is_numeric($choice) && isset($backups[$choice - 1])) {
                $backupPath = $backups[$choice - 1];
            } else {
                $backupPath = "{$backupDir}/{$choice}";
            }

            if (! file_exists($backupPath)) {
                $this->error("Backup not found: {$backupPath}");

                return 1;
            }
        }

        if (! $this->confirm('Restore from '.basename($backupPath).'? This will overwrite the current database.')) {
            $this->info('Cancelled.');

            return 0;
        }

        // Backup current before restoring
        $preRestoreBackup = "{$backupDir}/database_pre-restore_".now()->format('Y-m-d_H-i-s').'.sqlite';
        if (file_exists($target)) {
            copy($target, $preRestoreBackup);
            $this->info('Current database backed up to: '.basename($preRestoreBackup));
        }

        if (copy($backupPath, $target)) {
            $this->info('Database restored from: '.basename($backupPath));

            return 0;
        }

        $this->error('Failed to restore database');

        return 1;
    }
}
