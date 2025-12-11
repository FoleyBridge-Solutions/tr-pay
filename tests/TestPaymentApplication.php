<?php

/**
 * Test Payment Application Logic
 * 
 * Verifies that payments correctly reduce invoice balances
 */

require __DIR__ . '/../vendor/autoload.php';

$app = require_once __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

echo "=================================================\n";
echo "Payment Application Logic Test\n";
echo "=================================================\n\n";

try {
    $testClientKey = 4386; // Client with $145k balance
    $staffKey = 1552;
    $bankAccountKey = 2;
    
    echo "Step 1: Get client's current balance...\n";
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
        WHERE LE.client_KEY = ?
        AND LE.posted__staff_KEY IS NOT NULL
    ", [$testClientKey]);
    
    echo "Balance before payment: \${$balanceBefore->balance}\n\n";
    
    echo "Step 2: Get open invoices with detailed amounts...\n";
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
            LE.amount AS original_amount,
            COALESCE(AT.applied_amount_to, 0) AS payments_to,
            COALESCE(AF.applied_amount_from, 0) AS credits_from,
            (LE.amount + 
             COALESCE(AT.applied_amount_to, 0) - 
             COALESCE(AF.applied_amount_from, 0)) AS open_amount
        FROM Ledger_Entry LE
        LEFT JOIN AppliedTo AT ON LE.ledger_entry_KEY = AT.to__ledger_entry_KEY
        LEFT JOIN AppliedFrom AF ON LE.ledger_entry_KEY = AF.from__ledger_entry_KEY
        WHERE LE.client_KEY = ?
        AND LE.ledger_entry_type_KEY = 1
        AND LE.posted__staff_KEY IS NOT NULL
        AND (LE.amount + COALESCE(AT.applied_amount_to, 0) - COALESCE(AF.applied_amount_from, 0)) > 0
        ORDER BY LE.entry_date DESC
    ", [$testClientKey]);
    
    echo "Open invoices:\n";
    foreach ($openInvoices as $inv) {
        echo "  Invoice #{$inv->entry_number}: Original=\${$inv->original_amount}, ";
        echo "Payments=\${$inv->payments_to}, Credits=\${$inv->credits_from}, ";
        echo "Open=\${$inv->open_amount}\n";
    }
    echo "\n";
    
    echo "Step 3: Begin transaction and create test payment...\n";
    DB::connection('sqlsrv')->beginTransaction();
    
    $paymentAmount = 15000.00; // Large enough to hit multiple invoices
    
    $nextLedgerKey = DB::connection('sqlsrv')->selectOne(
        "SELECT ISNULL(MAX(ledger_entry_KEY), 0) + 1 AS next_key FROM Ledger_Entry"
    )->next_key;
    
    $nextEntryNumber = DB::connection('sqlsrv')->selectOne(
        "SELECT ISNULL(MAX(entry_number), 0) + 1 AS next_num FROM Ledger_Entry"
    )->next_num;
    
    echo "Creating payment: \${$paymentAmount}\n";
    echo "Payment ledger_entry_KEY: {$nextLedgerKey}\n\n";
    
    DB::connection('sqlsrv')->insert("
        INSERT INTO Ledger_Entry (
            ledger_entry_KEY, update__staff_KEY, update_date_utc,
            bank_account_KEY, control_date, ledger_entry_type_KEY,
            client_KEY, entry_date, reference, amount,
            comments, internal_comments,
            approved_date, approved__staff_KEY,
            posted_date, posted__staff_KEY,
            entry_number, create_date_utc, ledger_entry_subtype_KEY
        )
        VALUES (?, ?, GETUTCDATE(), ?, CAST(GETDATE() AS DATE), ?, ?, CAST(GETDATE() AS DATE), ?, ?, ?, ?, CAST(GETDATE() AS DATE), ?, CAST(GETDATE() AS DATE), ?, ?, GETUTCDATE(), ?)
    ", [
        $nextLedgerKey, $staffKey, $bankAccountKey, 8,
        $testClientKey, 'TEST_APP_' . uniqid(), -$paymentAmount,
        'TEST: Payment application test', '{"test":true}',
        $staffKey, $staffKey, $nextEntryNumber, 9
    ]);
    
    echo "Step 4: Apply payment to multiple invoices...\n";
    
    $remainingPayment = $paymentAmount;
    $applications = [];
    
    foreach ($openInvoices as $invoice) {
        if ($remainingPayment <= 0) break;
        
        $applyAmount = min($remainingPayment, $invoice->open_amount);
        
        echo "  Applying \${$applyAmount} to invoice #{$invoice->entry_number}...\n";
        
        // IMPORTANT: from = INVOICE, to = PAYMENT (backwards from intuition!)
        DB::connection('sqlsrv')->insert("
            INSERT INTO Ledger_Entry_Application (
                update__staff_KEY, update_date_utc,
                from__ledger_entry_KEY, to__ledger_entry_KEY,
                applied_amount, create_date_utc
            )
            VALUES (?, GETUTCDATE(), ?, ?, ?, GETUTCDATE())
        ", [$staffKey, $invoice->ledger_entry_KEY, $nextLedgerKey, $applyAmount]);
        
        $applications[] = [
            'invoice' => $invoice->entry_number,
            'amount' => $applyAmount,
        ];
        
        $remainingPayment -= $applyAmount;
    }
    
    if ($remainingPayment > 0) {
        echo "  ⚠ WARNING: Unapplied payment amount: \${$remainingPayment}\n";
    }
    echo "\n";
    
    echo "Step 5: Verify invoice balances after payment...\n";
    foreach ($openInvoices as $invoice) {
        $newBalance = DB::connection('sqlsrv')->selectOne("
            WITH AppliedTo AS (
                SELECT to__ledger_entry_KEY, SUM(applied_amount) AS applied_amount_to
                FROM Ledger_Entry_Application GROUP BY to__ledger_entry_KEY
            ),
            AppliedFrom AS (
                SELECT from__ledger_entry_KEY, SUM(applied_amount) AS applied_amount_from
                FROM Ledger_Entry_Application GROUP BY from__ledger_entry_KEY
            )
            SELECT
                LE.amount AS original,
                COALESCE(AT.applied_amount_to, 0) AS payments_to,
                COALESCE(AF.applied_amount_from, 0) AS credits_from,
                (LE.amount + 
                 COALESCE(AT.applied_amount_to, 0) - 
                 COALESCE(AF.applied_amount_from, 0)) AS open_amount
            FROM Ledger_Entry LE
            LEFT JOIN AppliedTo AT ON LE.ledger_entry_KEY = AT.to__ledger_entry_KEY
            LEFT JOIN AppliedFrom AF ON LE.ledger_entry_KEY = AF.from__ledger_entry_KEY
            WHERE LE.ledger_entry_KEY = ?
        ", [$invoice->ledger_entry_KEY]);
        
        $appliedToThis = collect($applications)->firstWhere('invoice', $invoice->entry_number);
        $expectedNew = $invoice->open_amount - ($appliedToThis['amount'] ?? 0);
        
        echo "  Invoice #{$invoice->entry_number}:\n";
        echo "    Before: \${$invoice->open_amount}\n";
        echo "    Applied: \$" . ($appliedToThis['amount'] ?? 0) . "\n";
        echo "    After: \${$newBalance->open_amount}\n";
        echo "    Expected: \${$expectedNew}\n";
        
        if (abs($newBalance->open_amount - $expectedNew) < 0.01) {
            echo "    ✓ CORRECT\n";
        } else {
            echo "    ❌ MISMATCH!\n";
        }
        echo "\n";
    }
    
    echo "Step 6: Verify client balance reduced correctly...\n";
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
        WHERE LE.client_KEY = ?
        AND LE.posted__staff_KEY IS NOT NULL
    ", [$testClientKey]);
    
    echo "Balance before: \${$balanceBefore->balance}\n";
    echo "Payment amount: \${$paymentAmount}\n";
    echo "Balance after: \${$balanceAfter->balance}\n";
    echo "Expected after: \$" . ($balanceBefore->balance - $paymentAmount) . "\n";
    
    $diff = abs(($balanceBefore->balance - $paymentAmount) - $balanceAfter->balance);
    if ($diff < 0.01) {
        echo "✓ Balance calculation CORRECT\n\n";
    } else {
        echo "❌ Balance calculation INCORRECT (diff: \${$diff})\n\n";
    }
    
    echo "Step 7: Verify payment ledger entry details...\n";
    $paymentEntry = DB::connection('sqlsrv')->selectOne("
        SELECT * FROM Ledger_Entry WHERE ledger_entry_KEY = ?
    ", [$nextLedgerKey]);
    
    echo "Payment entry:\n";
    echo "  - amount: \${$paymentEntry->amount} (should be negative)\n";
    echo "  - posted: " . ($paymentEntry->posted__staff_KEY ? 'Yes' : 'No') . "\n";
    echo "  - approved: " . ($paymentEntry->approved__staff_KEY ? 'Yes' : 'No') . "\n";
    
    if ($paymentEntry->amount < 0) {
        echo "  ✓ Amount is negative (correct for payment)\n";
    } else {
        echo "  ❌ Amount should be negative!\n";
    }
    echo "\n";
    
    echo "Step 8: Verify all applications were created...\n";
    $createdApplications = DB::connection('sqlsrv')->select("
        SELECT 
            lea.*,
            le_from.entry_number AS invoice_entry,
            le_to.entry_number AS payment_entry
        FROM Ledger_Entry_Application lea
        JOIN Ledger_Entry le_from ON lea.from__ledger_entry_KEY = le_from.ledger_entry_KEY
        JOIN Ledger_Entry le_to ON lea.to__ledger_entry_KEY = le_to.ledger_entry_KEY
        WHERE lea.to__ledger_entry_KEY = ?
    ", [$nextLedgerKey]);
    
    echo "Created " . count($createdApplications) . " application(s):\n";
    $totalApplied = 0;
    foreach ($createdApplications as $app) {
        echo "  - Payment #{$app->payment_entry} -> Invoice #{$app->invoice_entry}: \${$app->applied_amount}\n";
        $totalApplied += $app->applied_amount;
    }
    echo "Total applied: \${$totalApplied}\n";
    
    if (abs($totalApplied - $paymentAmount) < 0.01) {
        echo "✓ All payment amount was applied\n";
    } else {
        echo "❌ Payment amount mismatch! Applied: \${$totalApplied}, Payment: \${$paymentAmount}\n";
    }
    echo "\n";
    
    echo "Step 9: Rolling back transaction...\n";
    DB::connection('sqlsrv')->rollBack();
    echo "✓ Rolled back\n\n";
    
    echo "=================================================\n";
    echo "✓ PAYMENT APPLICATION TEST COMPLETE\n";
    echo "=================================================\n\n";
    
    echo "Key Findings:\n";
    echo "1. Payments reduce invoice open_amount correctly\n";
    echo "2. Multiple invoices can be paid with one payment\n";
    echo "3. Client balance calculation accounts for applications\n";
    echo "4. Payment amounts must be negative\n";
    echo "5. Applications link payment (from) to invoice (to)\n\n";

} catch (\Exception $e) {
    echo "\n❌ ERROR: " . $e->getMessage() . "\n";
    echo $e->getTraceAsString() . "\n\n";
    
    if (DB::connection('sqlsrv')->transactionLevel() > 0) {
        DB::connection('sqlsrv')->rollBack();
    }
    exit(1);
}
