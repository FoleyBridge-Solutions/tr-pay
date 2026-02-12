<?php

namespace App\Console\Commands;

use App\Services\Kotapay\KotapayApiService;
use Illuminate\Console\Command;

class KotapayTestCommand extends Command
{
    protected $signature = 'kotapay:test';

    protected $description = 'Test connection to Kotapay API';

    public function handle(KotapayApiService $kotapay): int
    {
        $this->info('Kotapay API Connection Test');
        $this->line('============================');
        $this->newLine();

        // Show current config
        $this->info('Configuration:');
        $this->table(['Setting', 'Value'], [
            ['Environment', config('kotapay.environment')],
            ['API Enabled', config('kotapay.enabled') ? 'Yes' : 'No'],
            ['API URL', 'https://api.kotapay.com'],
            ['Client ID', $this->maskString(config('kotapay.client_id'))],
            ['Username', config('kotapay.username') ?: '(not set)'],
        ]);
        $this->newLine();

        // Test authentication
        $this->info('Testing authentication...');

        try {
            $token = $kotapay->getAccessToken();
            $this->info('✓ Authentication successful!');
            $this->line('  Token: '.substr($token, 0, 30).'...');
        } catch (\Exception $e) {
            $this->error('✗ Authentication failed: '.$e->getMessage());

            return Command::FAILURE;
        }

        $this->newLine();
        $this->info('Connection test complete!');
        $this->newLine();

        $this->line('Available commands:');
        $this->line('  kotapay:report               - Get File Acknowledgement Report');

        return Command::SUCCESS;
    }

    protected function maskString(?string $value): string
    {
        if (empty($value)) {
            return '(not set)';
        }

        if (strlen($value) <= 8) {
            return str_repeat('*', strlen($value));
        }

        return substr($value, 0, 4).'...'.substr($value, -4);
    }
}
