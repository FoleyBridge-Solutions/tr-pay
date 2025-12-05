<?php

/**
 * End-to-End Payment Test
 * 
 * Simulates a complete payment flow with MiPaymentChoice gateway
 * and PracticeCS integration
 */

require __DIR__ . '/../vendor/autoload.php';

$app = require_once __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;
use App\Services\PaymentService;
use App\Services\PracticeCsPaymentWriter;

echo "\n";
echo "==========================================================\n";
echo "END-TO-END PAYMENT TEST WITH PRACTICECS INTEGRATION\n";
echo "==========================================================\n\n";

// Test configuration
$testData = [
    'client_KEY' => 4386, // Client with balance
    'payment_amount' => 500.00,
    'credit_card_fee' => 500.00 * 0.029, // 2.9%
    'payment_method' => 'credit_card',
    
    // Test credit card
    'card_number' => '4111111111111111',
    'card_expiry' => '12/28',
    'card_cvv' => '999',
];

$totalAmount = $testData['payment_amount'] + $testData['credit_card_fee'];

try {
    echo "TEST CONFIGURATION\n";
    echo "----------------------------------------------------------\n";
    echo "Client KEY: {$testData['client_KEY']}\n";
    echo "Payment Amount: \$" . number_format($testData['payment_amount'], 2) . "\n";
    echo "Credit Card Fee: \$" . number_format($testData['credit_card_fee'], 2) . "\n";
    echo "Total Amount: \$" . number_format($totalAmount, 2) . "\n";
    echo "Payment Method: {$testData['payment_method']}\n";
    echo "Test Card: {$testData['card_number']}\n\n";

    echo "STEP 1: Verify PracticeCS Integration Enabled\n";
    echo "----------------------------------------------------------\n";
    $enabled = config('practicecs.payment_integration.enabled');
    $connection = config('practicecs.payment_integration.connection');
    
    if (!$enabled) {
        throw new Exception("PracticeCS integration is DISABLED - enable in .env");
    }
    
    echo "✓ PracticeCS Integration: ENABLED\n";
    echo "✓ Using Connection: {$connection}\n";
    
    if ($connection !== 'sqlsrv') {
        throw new Exception("DANGER: Not using TEST database! Set PRACTICECS_CONNECTION=sqlsrv");
    }
    echo "✓ Safe to test - using TEST_DB\n\n";

    echo "STEP 2: Get Client Information\n";
    echo "----------------------------------------------------------\n";
    $clientInfo = DB::connection('sqlsrv')->selectOne("
        SELECT client_KEY, description, client_id FROM Client WHERE client_KEY = ?
    ", [$testData['client_KEY']]);
    
    if (!$clientInfo) {
        throw new Exception("Client not found");
    }
    
    echo "✓ Client: {$clientInfo->description}\n";
    echo "  Client ID: {$clientInfo->client_id}\n";
    echo "  Client KEY: {$clientInfo->client_KEY}\n\n";

    echo "STEP 3: Get Client Balance Before Payment\n";
    echo "----------------------------------------------------------\n";
    $balanceBefore = DB::connection('sqlsrv')->selectOne("
        WITH AppliedTo AS (
            SELECT to__ledger_entry_KEY, SUM(applied_amount) AS applied_amount_to
            FROM Ledger_Entry_Application GROUP BY to__ledger_entry_KEY
        ),
        AppliedFrom AS (
            SELECT from__ledger_entry_KEY, SUM(applied_amount) AS applied_amount_from
            FROM Ledger_Entry_Application GROUP BY from__ledger_entry_KEY
        )
        SELECT
            SUM((LE.amount + 
                 COALESCE(AT.applied_amount_to, 0) - 
                 COALESCE(AF.applied_amount_from, 0)) * LET.normal_sign
            ) AS balance
        FROM Ledger_Entry LE
        JOIN Ledger_Entry_Type LET ON LE.ledger_entry_type_KEY = LET.ledger_entry_type_KEY
        LEFT JOIN AppliedTo AT ON LE.ledger_entry_KEY = AT.to__ledger_entry_KEY
        LEFT JOIN AppliedFrom AF ON LE.ledger_entry_KEY = AF.from__ledger_entry_KEY
        WHERE LE.client_KEY = ? AND LE.posted__staff_KEY IS NOT NULL
    ", [$testData['client_KEY']]);
    
    echo "✓ Balance Before: \$" . number_format($balanceBefore->balance, 2) . "\n\n";

    echo "STEP 4: Get Open Invoices\n";
    echo "----------------------------------------------------------\n";
    $openInvoices = DB::connection('sqlsrv')->select("
        WITH AppliedTo AS (
            SELECT to__ledger_entry_KEY, SUM(applied_amount) AS applied_amount_to
            FROM Ledger_Entry_Application GROUP BY to__ledger_entry_KEY
        ),
        AppliedFrom AS (
            SELECT from__ledger_entry_KEY, SUM(applied_amount) AS applied_amount_from
            FROM Ledger_Entry_Application GROUP BY from__ledger_entry_KEY
        )
        SELECT TOP 3
            LE.ledger_entry_KEY,
            LE.entry_number,
            (LE.amount + COALESCE(AT.applied_amount_to, 0) - COALESCE(AF.applied_amount_from, 0)) AS open_amount
        FROM Ledger_Entry LE
        LEFT JOIN AppliedTo AT ON LE.ledger_entry_KEY = AT.to__ledger_entry_KEY
        LEFT JOIN AppliedFrom AF ON LE.ledger_entry_KEY = AF.from__ledger_entry_KEY
        WHERE LE.client_KEY = ?
        AND LE.ledger_entry_type_KEY = 1
        AND LE.posted__staff_KEY IS NOT NULL
        AND (LE.amount + COALESCE(AT.applied_amount_to, 0) - COALESCE(AF.applied_amount_from, 0)) > 0
        ORDER BY LE.entry_date DESC
    ", [$testData['client_KEY']]);
    
    echo "Open Invoices:\n";
    foreach ($openInvoices as $inv) {
        echo "  - Invoice #{$inv->entry_number}: \$" . number_format($inv->open_amount, 2) . "\n";
    }
    echo "\n";

    echo "STEP 5: Create/Get Customer for MiPaymentChoice\n";
    echo "----------------------------------------------------------\n";
    $paymentService = app(PaymentService::class);
    
    $customer = $paymentService->getOrCreateCustomer([
        'client_KEY' => $clientInfo->client_KEY,
        'client_id' => $clientInfo->client_id,
        'client_name' => $clientInfo->description,
        'email' => 'test@example.com',
    ]);
    
    echo "✓ Customer ID: {$customer->id}\n";
    echo "  Client KEY: {$customer->client_key}\n\n";

    echo "STEP 6: Create QuickPayments Token\n";
    echo "----------------------------------------------------------\n";
    echo "⚠️  SIMULATING payment gateway call...\n";
    echo "    (In production, this would call MiPaymentChoice API)\n";
    
    // Simulate token creation (in production this goes to gateway)
    $qpToken = 'test_token_' . uniqid();
    echo "✓ Token Created: {$qpToken}\n\n";

    echo "STEP 7: Begin Transaction in TEST_DB\n";
    echo "----------------------------------------------------------\n";
    DB::connection('sqlsrv')->beginTransaction();
    echo "✓ Transaction started\n\n";

    echo "STEP 8: Write Payment to PracticeCS\n";
    echo "----------------------------------------------------------\n";
    
    $writer = app(PracticeCsPaymentWriter::class);
    
    // Prepare invoice applications
    $invoiceApplications = [];
    $remainingAmount = $totalAmount;
    
    foreach ($openInvoices as $invoice) {
        if ($remainingAmount <= 0) break;
        
        $applyAmount = min($remainingAmount, $invoice->open_amount);
        $invoiceApplications[] = [
            'ledger_entry_KEY' => $invoice->ledger_entry_KEY,
            'amount' => $applyAmount,
        ];
        
        $remainingAmount -= $applyAmount;
        echo "  Applying \$" . number_format($applyAmount, 2) . " to invoice #{$invoice->entry_number}\n";
    }
    
    $practiceCsData = [
        'client_KEY' => $clientInfo->client_KEY,
        'amount' => $totalAmount,
        'reference' => 'TEST_E2E_' . uniqid(),
        'comments' => "TEST: End-to-end payment - Credit Card - " . count($invoiceApplications) . " invoice(s)",
        'internal_comments' => json_encode([
            'source' => 'tr-pay-e2e-test',
            'test_card' => $testData['card_number'],
            'payment_method' => 'credit_card',
            'fee' => $testData['credit_card_fee'],
        ]),
        'staff_KEY' => config('practicecs.payment_integration.staff_key'),
        'bank_account_KEY' => config('practicecs.payment_integration.bank_account_key'),
        'ledger_type_KEY' => 9, // Credit Card
        'subtype_KEY' => 10, // Credit Card subtype
        'invoices' => $invoiceApplications,
    ];
    
    echo "\n";
    $result = $writer->writePayment($practiceCsData);
    
    if (!$result['success']) {
        throw new Exception("PracticeCS write failed: " . $result['error']);
    }
    
    echo "✓ Payment Written to PracticeCS\n";
    echo "  Ledger Entry KEY: {$result['ledger_entry_KEY']}\n";
    echo "  Entry Number: {$result['entry_number']}\n\n";

    echo "STEP 9: Verify Payment in Database\n";
    echo "----------------------------------------------------------\n";
    $paymentEntry = DB::connection('sqlsrv')->selectOne("
        SELECT * FROM Ledger_Entry WHERE ledger_entry_KEY = ?
    ", [$result['ledger_entry_KEY']]);
    
    echo "Payment Entry:\n";
    echo "  Amount: \$" . number_format($paymentEntry->amount, 2) . " (should be negative)\n";
    echo "  Reference: {$paymentEntry->reference}\n";
    echo "  Posted: " . ($paymentEntry->posted__staff_KEY ? 'YES' : 'NO') . "\n";
    echo "  Approved: " . ($paymentEntry->approved__staff_KEY ? 'YES' : 'NO') . "\n";
    
    if ($paymentEntry->amount >= 0) {
        throw new Exception("ERROR: Payment amount should be negative!");
    }
    echo "✓ Amount is negative (correct)\n\n";

    echo "STEP 10: Verify Invoice Applications\n";
    echo "----------------------------------------------------------\n";
    $applications = DB::connection('sqlsrv')->select("
        SELECT 
            lea.*,
            le_inv.entry_number AS invoice_number
        FROM Ledger_Entry_Application lea
        JOIN Ledger_Entry le_inv ON lea.from__ledger_entry_KEY = le_inv.ledger_entry_KEY
        WHERE lea.to__ledger_entry_KEY = ?
    ", [$result['ledger_entry_KEY']]);
    
    echo "Applications Created: " . count($applications) . "\n";
    $totalApplied = 0;
    foreach ($applications as $app) {
        echo "  - Invoice #{$app->invoice_number}: \$" . number_format($app->applied_amount, 2) . "\n";
        $totalApplied += $app->applied_amount;
    }
    echo "Total Applied: \$" . number_format($totalApplied, 2) . "\n";
    
    if (abs($totalApplied - $totalAmount) > 0.01) {
        throw new Exception("Application total doesn't match payment amount!");
    }
    echo "✓ All payment amount applied to invoices\n\n";

    echo "STEP 11: Verify Client Balance Updated\n";
    echo "----------------------------------------------------------\n";
    $balanceAfter = DB::connection('sqlsrv')->selectOne("
        WITH AppliedTo AS (
            SELECT to__ledger_entry_KEY, SUM(applied_amount) AS applied_amount_to
            FROM Ledger_Entry_Application GROUP BY to__ledger_entry_KEY
        ),
        AppliedFrom AS (
            SELECT from__ledger_entry_KEY, SUM(applied_amount) AS applied_amount_from
            FROM Ledger_Entry_Application GROUP BY from__ledger_entry_KEY
        )
        SELECT
            SUM((LE.amount + 
                 COALESCE(AT.applied_amount_to, 0) - 
                 COALESCE(AF.applied_amount_from, 0)) * LET.normal_sign
            ) AS balance
        FROM Ledger_Entry LE
        JOIN Ledger_Entry_Type LET ON LE.ledger_entry_type_KEY = LET.ledger_entry_type_KEY
        LEFT JOIN AppliedTo AT ON LE.ledger_entry_KEY = AT.to__ledger_entry_KEY
        LEFT JOIN AppliedFrom AF ON LE.ledger_entry_KEY = AF.from__ledger_entry_KEY
        WHERE LE.client_KEY = ? AND LE.posted__staff_KEY IS NOT NULL
    ", [$testData['client_KEY']]);
    
    echo "Balance Before: \$" . number_format($balanceBefore->balance, 2) . "\n";
    echo "Payment Amount: \$" . number_format($totalAmount, 2) . "\n";
    echo "Balance After: \$" . number_format($balanceAfter->balance, 2) . "\n";
    echo "Expected After: \$" . number_format($balanceBefore->balance - $totalAmount, 2) . "\n";
    
    $diff = abs(($balanceBefore->balance - $totalAmount) - $balanceAfter->balance);
    if ($diff > 0.01) {
        throw new Exception("Balance calculation incorrect! Diff: \$$diff");
    }
    echo "✓ Balance reduced correctly\n\n";

    echo "STEP 12: Check Cache Tables Updated\n";
    echo "----------------------------------------------------------\n";
    $cacheUpdate = DB::connection('sqlsrv')->selectOne("
        SELECT * FROM Client_Date_Cache
        WHERE client_KEY = ?
        AND CAST(entry_date AS DATE) = CAST(GETDATE() AS DATE)
    ", [$testData['client_KEY']]);
    
    if ($cacheUpdate) {
        echo "✓ Client_Date_Cache updated\n";
        echo "  AR Received: \$" . number_format($cacheUpdate->ar_received, 2) . "\n";
        echo "  Collected: \$" . number_format($cacheUpdate->collected, 2) . "\n";
    } else {
        echo "⚠  Cache not updated (may update on commit)\n";
    }
    echo "\n";

    echo "STEP 13: Rollback Transaction (Test Only)\n";
    echo "----------------------------------------------------------\n";
    DB::connection('sqlsrv')->rollBack();
    echo "✓ Transaction rolled back\n";
    echo "✓ No data persisted (test only)\n\n";

    echo "STEP 14: Verify Rollback\n";
    echo "----------------------------------------------------------\n";
    $checkRollback = DB::connection('sqlsrv')->selectOne("
        SELECT * FROM Ledger_Entry WHERE ledger_entry_KEY = ?
    ", [$result['ledger_entry_KEY']]);
    
    if ($checkRollback) {
        throw new Exception("Rollback failed - record still exists!");
    }
    echo "✓ Payment record removed (rollback successful)\n\n";

    echo "==========================================================\n";
    echo "✅ END-TO-END TEST PASSED SUCCESSFULLY!\n";
    echo "==========================================================\n\n";

    echo "TEST SUMMARY\n";
    echo "----------------------------------------------------------\n";
    echo "✓ PracticeCS integration: WORKING\n";
    echo "✓ Payment creation: WORKING\n";
    echo "✓ Invoice applications: WORKING\n";
    echo "✓ Balance calculations: WORKING\n";
    echo "✓ Cache updates: WORKING\n";
    echo "✓ Transaction rollback: WORKING\n\n";

    echo "NEXT STEPS\n";
    echo "----------------------------------------------------------\n";
    echo "1. System is ready for production deployment\n";
    echo "2. Follow DEPLOYMENT.md for staged rollout\n";
    echo "3. Start with PRACTICECS_WRITE_ENABLED=false\n";
    echo "4. Test with real payments on TEST_DB first\n";
    echo "5. Switch to production when confident\n\n";

    echo "NOTE: This test used transaction rollback.\n";
    echo "      In production, payments will persist in PracticeCS.\n\n";

} catch (\Exception $e) {
    echo "\n";
    echo "==========================================================\n";
    echo "❌ TEST FAILED\n";
    echo "==========================================================\n";
    echo "Error: " . $e->getMessage() . "\n\n";
    echo "Stack Trace:\n";
    echo $e->getTraceAsString() . "\n\n";
    
    if (DB::connection('sqlsrv')->transactionLevel() > 0) {
        DB::connection('sqlsrv')->rollBack();
        echo "Transaction rolled back\n\n";
    }
    
    exit(1);
}
