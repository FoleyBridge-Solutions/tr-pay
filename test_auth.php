<?php

// test_auth.php - Test MiPaymentChoice authentication

require __DIR__.'/vendor/autoload.php';

$app = require_once __DIR__.'/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use MiPaymentChoice\Cashier\Services\ApiClient;

echo "Testing MiPaymentChoice Authentication...\n\n";

$username = env('MIPAYMENTCHOICE_USERNAME');
$password = env('MIPAYMENTCHOICE_PASSWORD');
$baseUrl = env('MIPAYMENTCHOICE_BASE_URL');

echo "Username: $username\n";
echo "Base URL: $baseUrl\n\n";

try {
    $apiClient = new ApiClient($username, $password, $baseUrl);
    
    // Make a simple test request that requires authentication
    echo "Attempting to authenticate...\n";
    
    $merchantKey = env('MIPAYMENTCHOICE_MERCHANT_KEY', '1234');
    echo "Merchant Key: $merchantKey\n\n";
    
    // Try to get merchant keys endpoint (this requires authentication)
    $response = $apiClient->get("/quickpayments/merchants/{$merchantKey}/keys");
    
    echo "✅ SUCCESS! Authentication worked!\n\n";
    echo "Response:\n";
    print_r($response);
    
} catch (\Exception $e) {
    echo "❌ FAILED: " . $e->getMessage() . "\n\n";
    echo "Stack trace:\n";
    echo $e->getTraceAsString();
}
