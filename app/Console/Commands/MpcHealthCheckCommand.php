<?php

// app/Console/Commands/MpcHealthCheckCommand.php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use MiPaymentChoice\Cashier\Services\ApiClient;
use MiPaymentChoice\Cashier\Services\QuickPaymentsService;
use Exception;
use ReflectionClass;

class MpcHealthCheckCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'mpc:health-check {--clear-cache : Clear the bearer token cache before testing}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Check MiPaymentChoice API integration health';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle(): int
    {
        $this->info('=== MiPaymentChoice Health Check ===');
        $this->newLine();

        $allPassed = true;

        // Optional: Clear cache
        if ($this->option('clear-cache')) {
            Cache::forget('mipaymentchoice_bearer_token');
            $this->info('✓ Bearer token cache cleared');
            $this->newLine();
        }

        // Test 1: Configuration
        $this->info('1. Configuration Check:');
        $config = config('mipaymentchoice');
        
        $this->checkValue('Username', $config['username']);
        $this->checkValue('Password', $config['password']);
        $this->checkValue('Merchant Key', $config['merchant_key']);
        $this->checkValue('QuickPayments Key', $config['quickpayments_key'] ?? null);
        $this->line("   Base URL: {$config['base_url']}");
        $this->newLine();

        // Test 2: Bearer Token Authentication
        $this->info('2. Bearer Token Authentication:');
        try {
            $api = app(ApiClient::class);
            $reflection = new ReflectionClass($api);
            $method = $reflection->getMethod('getBearerToken');
            $method->setAccessible(true);
            
            $startTime = microtime(true);
            $token = $method->invoke($api);
            $duration = round((microtime(true) - $startTime) * 1000, 2);
            
            $this->line("   <fg=green>✓</> Token retrieved (" . strlen($token) . " chars) in {$duration}ms");
            $this->line("   Token preview: " . substr($token, 0, 50) . "...");
        } catch (Exception $e) {
            $this->line("   <fg=red>✗</> Failed: " . $e->getMessage());
            $allPassed = false;
        }
        $this->newLine();

        // Test 3: QuickPayments Service
        $this->info('3. QuickPayments Service:');
        try {
            $qpService = app(QuickPaymentsService::class);
            $this->line("   <fg=green>✓</> Service initialized");
        } catch (Exception $e) {
            $this->line("   <fg=red>✗</> Failed: " . $e->getMessage());
            $allPassed = false;
        }
        $this->newLine();

        // Test 4: Cache Status
        $this->info('4. Cache Status:');
        $cached = Cache::has('mipaymentchoice_bearer_token');
        if ($cached) {
            $this->line("   <fg=green>✓</> Bearer token is cached");
        } else {
            $this->line("   <fg=yellow>⚠</> Bearer token is not cached (will be cached on first use)");
        }
        $this->newLine();

        // Final Result
        if ($allPassed) {
            $this->info('✓✓✓ ALL CHECKS PASSED ✓✓✓');
            $this->info('Payment integration is working correctly.');
            return Command::SUCCESS;
        } else {
            $this->error('✗✗✗ SOME CHECKS FAILED ✗✗✗');
            $this->error('Please review the errors above and check your configuration.');
            return Command::FAILURE;
        }
    }

    /**
     * Check and display a configuration value.
     *
     * @param string $name
     * @param mixed $value
     * @return void
     */
    protected function checkValue(string $name, $value): void
    {
        if (empty($value)) {
            $this->line("   <fg=red>✗</> {$name}: <fg=red>Missing</>");
        } else {
            // Mask sensitive values
            $displayValue = in_array(strtolower($name), ['password', 'key']) 
                ? str_repeat('*', min(strlen($value), 8))
                : $value;
            $this->line("   <fg=green>✓</> {$name}: {$displayValue}");
        }
    }
}
