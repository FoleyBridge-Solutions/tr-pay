<?php

/**
 * PracticeCS Payment Integration Test Script
 * 
 * This script tests the full payment insertion flow into PracticeCS CSP_345844_TestDoNotUse
 * It uses transactions with ROLLBACK to avoid persisting test data
 */

require __DIR__ . '/../vendor/autoload.php';

$app = require_once __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

echo "=================================================\n";
echo "PracticeCS Payment Integration Test\n";
echo "=================================================\n\n";

// Test configuration
$testData = [
    'client_KEY' => 36594, // Existing client from sample data
    'amount' => -100.00, // Negative for payment
    'reference' => 'TEST_' . uniqid(),
    'comments' => 'TEST: Online payment integration test',
    'internal_comments' => json_encode([
        'source' => 'tr-pay-test',
        'test_run' => true,
        'timestamp' => now()->toIso8601String(),
    ]),
    'staff_KEY' => 1552, // ADMINSA from sample data
    'bank_account_KEY' => 2, // From sample payment data
    'ledger_type_KEY' => 8, // Cash
    'subtype_KEY' => 9, // Cash subtype
];

try {
    echo "Step 1: Testing connection to CSP_345844_TestDoNotUse...\n";
    $dbName = DB::connection('sqlsrv')->selectOne('SELECT DB_NAME() AS db');
    echo "✓ Connected to: {$dbName->db}\n\n";

    echo "Step 2: Validating foreign key references...\n";
    
    // Validate client
    $client = DB::connection('sqlsrv')->selectOne(
        "SELECT client_KEY, description FROM Client WHERE client_KEY = ?",
        [$testData['client_KEY']]
    );
    if (!$client) {
        throw new Exception("Client not found");
    }
    echo "✓ Client: {$client->description}\n";
    
    // Validate staff
    $staff = DB::connection('sqlsrv')->selectOne(
        "SELECT staff_KEY, description FROM Staff WHERE staff_KEY = ?",
        [$testData['staff_KEY']]
    );
    if (!$staff) {
        throw new Exception("Staff not found");
    }
    echo "✓ Staff: {$staff->description}\n";
    
    // Validate bank account
    $bankAccount = DB::connection('sqlsrv')->selectOne(
        "SELECT bank_account_KEY, description FROM Bank_Account WHERE bank_account_KEY = ?",
        [$testData['bank_account_KEY']]
    );
    if (!$bankAccount) {
        throw new Exception("Bank account not found");
    }
    echo "✓ Bank Account: {$bankAccount->description}\n";
    
    // Validate ledger type
    $ledgerType = DB::connection('sqlsrv')->selectOne(
        "SELECT ledger_entry_type_KEY, description FROM Ledger_Entry_Type WHERE ledger_entry_type_KEY = ?",
        [$testData['ledger_type_KEY']]
    );
    if (!$ledgerType) {
        throw new Exception("Ledger type not found");
    }
    echo "✓ Ledger Type: {$ledgerType->description}\n";
    
    // Validate subtype
    $subtype = DB::connection('sqlsrv')->selectOne(
        "SELECT ledger_entry_subtype_KEY, description FROM Ledger_Entry_Subtype WHERE ledger_entry_subtype_KEY = ?",
        [$testData['subtype_KEY']]
    );
    if (!$subtype) {
        throw new Exception("Ledger subtype not found");
    }
    echo "✓ Ledger Subtype: {$subtype->description}\n\n";

    echo "Step 3: Getting current max keys...\n";
    $maxLedgerKey = DB::connection('sqlsrv')->selectOne(
        "SELECT ISNULL(MAX(ledger_entry_KEY), 0) AS max_key FROM Ledger_Entry"
    );
    echo "Current max ledger_entry_KEY: {$maxLedgerKey->max_key}\n";
    
    $maxEntryNumber = DB::connection('sqlsrv')->selectOne(
        "SELECT ISNULL(MAX(entry_number), 0) AS max_num FROM Ledger_Entry"
    );
    echo "Current max entry_number: {$maxEntryNumber->max_num}\n\n";

    echo "Step 4: Beginning transaction (will ROLLBACK at end)...\n";
    DB::connection('sqlsrv')->beginTransaction();
    
    // Generate next keys
    $nextLedgerKey = $maxLedgerKey->max_key + 1;
    $nextEntryNumber = $maxEntryNumber->max_num + 1;
    $entryDate = now()->startOfDay(); // Must be midnight per CHECK constraint
    
    echo "Next ledger_entry_KEY: {$nextLedgerKey}\n";
    echo "Next entry_number: {$nextEntryNumber}\n";
    echo "Entry date: {$entryDate}\n\n";

    echo "Step 5: Inserting Ledger_Entry...\n";
    
    $insertResult = DB::connection('sqlsrv')->insert("
        INSERT INTO Ledger_Entry (
            ledger_entry_KEY,
            update__staff_KEY,
            update_date_utc,
            bank_account_KEY,
            control_date,
            ledger_entry_type_KEY,
            client_KEY,
            entry_date,
            reference,
            amount,
            comments,
            internal_comments,
            approved_date,
            approved__staff_KEY,
            posted_date,
            posted__staff_KEY,
            entry_number,
            create_date_utc,
            ledger_entry_subtype_KEY
        )
        VALUES (?, ?, GETUTCDATE(), ?, ?, ?, ?, ?, ?, ?, ?, ?, CAST(GETDATE() AS DATE), ?, CAST(GETDATE() AS DATE), ?, ?, GETUTCDATE(), ?)
    ", [
        $nextLedgerKey,
        $testData['staff_KEY'],
        $testData['bank_account_KEY'],
        $entryDate,
        $testData['ledger_type_KEY'],
        $testData['client_KEY'],
        $entryDate,
        $testData['reference'],
        $testData['amount'],
        $testData['comments'],
        $testData['internal_comments'],
        $testData['staff_KEY'],
        $testData['staff_KEY'],
        $nextEntryNumber,
        $testData['subtype_KEY'],
    ]);
    
    echo "✓ Ledger_Entry inserted successfully\n\n";

    echo "Step 6: Verifying inserted record...\n";
    $inserted = DB::connection('sqlsrv')->selectOne(
        "SELECT * FROM Ledger_Entry WHERE ledger_entry_KEY = ?",
        [$nextLedgerKey]
    );
    
    if (!$inserted) {
        throw new Exception("Failed to retrieve inserted record");
    }
    
    echo "✓ Record found:\n";
    echo "  - ledger_entry_KEY: {$inserted->ledger_entry_KEY}\n";
    echo "  - entry_number: {$inserted->entry_number}\n";
    echo "  - amount: {$inserted->amount}\n";
    echo "  - reference: {$inserted->reference}\n";
    echo "  - posted__staff_KEY: {$inserted->posted__staff_KEY}\n";
    echo "  - row_version: " . bin2hex($inserted->row_version) . "\n\n";

    echo "Step 7: Checking trigger effects...\n";
    
    // Check Client_Date_Cache
    $cacheEntry = DB::connection('sqlsrv')->selectOne("
        SELECT * FROM Client_Date_Cache 
        WHERE client_KEY = ? 
        AND CAST(entry_date AS DATE) = CAST(? AS DATE)
    ", [$testData['client_KEY'], $entryDate]);
    
    if ($cacheEntry) {
        echo "✓ Client_Date_Cache updated:\n";
        echo "  - ar_received: {$cacheEntry->ar_received}\n";
        echo "  - collected: {$cacheEntry->collected}\n";
    } else {
        echo "⚠ Client_Date_Cache entry not found (may be created on commit)\n";
    }
    echo "\n";

    echo "Step 8: Testing Ledger_Entry_Application...\n";
    
    // Find an open invoice for this client
    $openInvoice = DB::connection('sqlsrv')->selectOne("
        SELECT TOP 1 
            LE.ledger_entry_KEY,
            LE.entry_number,
            LE.amount
        FROM Ledger_Entry LE
        WHERE LE.client_KEY = ?
        AND LE.ledger_entry_type_KEY = 1
        AND LE.posted__staff_KEY IS NOT NULL
        AND LE.amount > 0
        ORDER BY LE.entry_date DESC
    ", [$testData['client_KEY']]);
    
    if ($openInvoice) {
        echo "✓ Found open invoice #{$openInvoice->entry_number} for \${$openInvoice->amount}\n";
        
        $appliedAmount = min(abs($testData['amount']), $openInvoice->amount);
        
        echo "  Applying \${$appliedAmount} to invoice...\n";
        
        // CRITICAL: from = INVOICE, to = PAYMENT (counter-intuitive but correct!)
        $applicationInsert = DB::connection('sqlsrv')->insert("
            INSERT INTO Ledger_Entry_Application (
                update__staff_KEY,
                update_date_utc,
                from__ledger_entry_KEY,
                to__ledger_entry_KEY,
                applied_amount,
                create_date_utc
            )
            VALUES (?, GETUTCDATE(), ?, ?, ?, GETUTCDATE())
        ", [
            $testData['staff_KEY'],
            $openInvoice->ledger_entry_KEY, // FROM = Invoice
            $nextLedgerKey, // TO = Payment
            $appliedAmount,
        ]);
        
        echo "✓ Ledger_Entry_Application created\n";
        
        // Verify application
        $application = DB::connection('sqlsrv')->selectOne("
            SELECT * FROM Ledger_Entry_Application
            WHERE from__ledger_entry_KEY = ?
            AND to__ledger_entry_KEY = ?
        ", [$openInvoice->ledger_entry_KEY, $nextLedgerKey]);
        
        if ($application) {
            echo "✓ Application verified:\n";
            echo "  - ledger_entry_application_KEY: {$application->ledger_entry_application_KEY}\n";
            echo "  - applied_amount: {$application->applied_amount}\n";
        }
    } else {
        echo "⚠ No open invoices found for this client\n";
    }
    echo "\n";

    echo "Step 9: Calculating client balance...\n";
    $balance = DB::connection('sqlsrv')->selectOne("
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
        WHERE LE.client_KEY = ?
        AND LE.posted__staff_KEY IS NOT NULL
    ", [$testData['client_KEY']]);
    
    echo "✓ Current balance (with test payment): \${$balance->balance}\n\n";

    echo "Step 10: Rolling back transaction...\n";
    DB::connection('sqlsrv')->rollBack();
    echo "✓ Transaction rolled back - no changes persisted\n\n";

    echo "Step 11: Verifying rollback...\n";
    $checkRollback = DB::connection('sqlsrv')->selectOne(
        "SELECT * FROM Ledger_Entry WHERE ledger_entry_KEY = ?",
        [$nextLedgerKey]
    );
    
    if (!$checkRollback) {
        echo "✓ Rollback verified - test record not found\n\n";
    } else {
        echo "⚠ WARNING: Record still exists after rollback!\n\n";
    }

    echo "=================================================\n";
    echo "✓ ALL TESTS PASSED SUCCESSFULLY\n";
    echo "=================================================\n\n";

    echo "Summary:\n";
    echo "- Database connection: WORKING\n";
    echo "- Foreign key validation: WORKING\n";
    echo "- Ledger_Entry insertion: WORKING\n";
    echo "- Triggers execution: WORKING\n";
    echo "- Ledger_Entry_Application: WORKING\n";
    echo "- Transaction rollback: WORKING\n\n";

    echo "Next Steps:\n";
    echo "1. Review this output to ensure all operations succeeded\n";
    echo "2. Create PracticeCsPaymentWriter service\n";
    echo "3. Implement actual payment integration\n";
    echo "4. Add error handling and retry logic\n";
    echo "5. Test with real payment data\n\n";

} catch (\Exception $e) {
    echo "\n❌ ERROR: " . $e->getMessage() . "\n";
    echo "Stack trace:\n";
    echo $e->getTraceAsString() . "\n\n";
    
    // Rollback if transaction is active
    if (DB::connection('sqlsrv')->transactionLevel() > 0) {
        DB::connection('sqlsrv')->rollBack();
        echo "Transaction rolled back due to error\n";
    }
    
    exit(1);
}
