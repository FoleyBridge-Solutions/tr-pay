<?php

namespace App\Console\Commands;

use App\Services\Kotapay\KotapayApiService;
use Illuminate\Console\Command;

class KotapayReportCommand extends Command
{
    protected $signature = 'kotapay:report 
                            {--start= : Start date (Y-m-d), defaults to today}
                            {--end= : End date (Y-m-d), defaults to start date}
                            {--days=7 : Number of days to look back if no start date specified}';

    protected $description = 'Get File Acknowledgement Report (FAR) from Kotapay';

    public function handle(KotapayApiService $kotapay): int
    {
        $this->info('Kotapay File Acknowledgement Report');
        $this->line('====================================');
        $this->newLine();

        // Determine date range
        $startDate = $this->option('start');
        $endDate = $this->option('end');
        $days = (int) $this->option('days');

        if (! $startDate) {
            $startDate = now()->subDays($days)->format('Y-m-d');
        }

        if (! $endDate) {
            $endDate = now()->format('Y-m-d');
        }

        $this->info("Date range: {$startDate} to {$endDate}");
        $this->newLine();

        // Fetch report
        $this->line('Fetching report from Kotapay...');

        try {
            $report = $kotapay->getFileAcknowledgementReport($startDate, $endDate);
        } catch (\Exception $e) {
            $this->error('Failed to fetch report: '.$e->getMessage());

            return Command::FAILURE;
        }

        $rowCount = $report['rowCount'] ?? 0;
        $rows = $report['rows'] ?? [];

        if ($rowCount === 0 || empty($rows)) {
            $this->warn('No files found for the specified date range.');

            return Command::SUCCESS;
        }

        $this->info("Found {$rowCount} file(s):");
        $this->newLine();

        // Display results
        $tableData = [];
        foreach ($rows as $row) {
            $tableData[] = [
                $row['UniqueFileID'] ?? 'N/A',
                $row['BatchCompanyName'] ?? 'N/A',
                $row['AppEntryClass'] ?? 'N/A',
                $row['EffectiveDate'] ?? 'N/A',
                '$'.number_format(abs($row['DebitAmt'] ?? 0), 2),
                '$'.number_format($row['CreditAmt'] ?? 0, 2),
                ($row['DebitCnt'] ?? 0).'/'.($row['CreditCnt'] ?? 0),
                isset($row['TimeLoaded']) ? substr($row['TimeLoaded'], 0, 16) : 'N/A',
            ];
        }

        $this->table(
            ['File ID', 'Company', 'SEC', 'Effective', 'Debit', 'Credit', 'D/C Count', 'Loaded'],
            $tableData
        );

        return Command::SUCCESS;
    }
}
