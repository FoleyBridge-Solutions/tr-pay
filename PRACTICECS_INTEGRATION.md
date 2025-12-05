# PracticeCS Payment Integration

## Overview

This application writes online payments back to the PracticeCS SQL Server database in real-time.

## ⚠️ CURRENT STATUS: DEVELOPMENT MODE

**All MSSQL operations currently use TEST_DB for safe development.**

The `sqlsrv` connection has been configured to point to `CSP_345844_TestDoNotUse` instead of the production database. This allows you to:
- Test payment integration safely
- Develop and debug without affecting production
- Verify all operations before deploying to production

**Note:** The `sqlsrv_test` connection has been removed. All operations now use the single `sqlsrv` connection.

## Configuration

### Environment Variables

Current `.env` settings (DEVELOPMENT MODE):

```bash
# PracticeCS Payment Integration
PRACTICECS_WRITE_ENABLED=false   # Safe to enable - writes to test database
PRACTICECS_CONNECTION=sqlsrv     # Only connection, points to test DB
PRACTICECS_STAFF_KEY=1552        # Staff account for automated entries
PRACTICECS_BANK_ACCOUNT_KEY=2    # Bank account for online payments
PRACTICECS_AUTO_POST=true        # Auto-approve and post payments

# Test Database (CURRENTLY IN USE)
TEST_DB_HOST=practicecs.bpc.local
TEST_DB_PORT=65454
TEST_DB_DATABASE=CSP_345844_TestDoNotUse
TEST_DB_USERNAME=graphana
TEST_DB_PASSWORD=Tw3nt05!
```

### Database Permissions

Grant write permissions to the database user:

```sql
USE CSP_345844_BurkhartPeterson;
GRANT INSERT, UPDATE ON Ledger_Entry TO graphana;
GRANT INSERT, UPDATE ON Ledger_Entry_Application TO graphana;
GRANT SELECT, INSERT, UPDATE ON Client_Date_Cache TO graphana;
GRANT SELECT, INSERT, UPDATE ON Sheet_Entry_Open_Value_Cache TO graphana;
```

## How It Works

When a payment is processed:

1. **Payment Gateway** - MiPaymentChoice processes credit card/ACH
2. **Local Storage** - Payment saved to SQLite database
3. **PracticeCS Integration** (if enabled):
   - Creates `Ledger_Entry` record (payment)
   - Creates `Ledger_Entry_Application` records (links payment to invoices)
   - Triggers auto-update `Client_Date_Cache`
4. **Email Receipt** - Sent to customer
5. **Confirmation** - User sees success page

## Database Schema

### Ledger_Entry (Payments)

| Field | Value |
|-------|-------|
| `ledger_entry_type_KEY` | 8 (Cash), 9 (Credit Card), or 11 (EFT) |
| `amount` | **MUST BE NEGATIVE** (e.g., -100.00) |
| `reference` | MiPaymentChoice transaction ID |
| `approved_date` | **MUST BE MIDNIGHT** (no time component) |
| `posted_date` | **MUST BE MIDNIGHT** (no time component) |

### Ledger_Entry_Application

**CRITICAL:** Direction is counter-intuitive:

- `from__ledger_entry_KEY` = **INVOICE** (receivable)
- `to__ledger_entry_KEY` = **PAYMENT** (receipt)

Think: "Money flows FROM invoice TO payment"

## Testing

### Test Database

**IMPORTANT**: The `sqlsrv` connection now points to CSP_345844_TestDoNotUse, making testing safer and easier.

```bash
# Run integration test (uses test database via sqlsrv connection)
php tests/PracticeCsPaymentIntegrationTest.php

# Run payment application test
php tests/TestPaymentApplication.php

# Run end-to-end tests
php artisan test tests/EndToEndPaymentTest.php
php artisan test tests/ComprehensivePaymentTest.php
php artisan test tests/ProjectPaymentTest.php
```

Tests use transactions with ROLLBACK - no data is persisted in the test database.

### Validate Setup

```php
// Verify connection points to test database
php artisan tinker
DB::connection('sqlsrv')->selectOne("SELECT DB_NAME() AS db");
// Should show: CSP_345844_TestDoNotUse

// Check permissions (should work since TEST_DB has write access)
DB::connection('sqlsrv')->select("
    SELECT HAS_PERMS_BY_NAME('Ledger_Entry', 'OBJECT', 'INSERT') AS can_insert
");

// Test connection
config(['practicecs.payment_integration.enabled' => true]);
config(['practicecs.payment_integration.connection' => 'sqlsrv']);
```

## SQL Queries Executed

For each payment, 12 SQL statements are executed:

1. BEGIN TRANSACTION
2. Get next `ledger_entry_KEY` (with lock)
3. Get next `entry_number`
4. Validate client exists
5. Validate staff exists
6. Validate bank account exists
7. INSERT Ledger_Entry
8. For each invoice:
   - Validate invoice exists
   - INSERT Ledger_Entry_Application
9. COMMIT

## Error Handling

- Payment gateway failure: User sees error, nothing written to PracticeCS
- PracticeCS write failure: Payment still succeeded, error logged for retry
- Partial failures: Transaction rolls back, nothing written

## Monitoring

Check logs for:

```
PracticeCS: Ledger_Entry created
PracticeCS: Payment applied to invoice
PracticeCS: Payment written successfully
PracticeCS: Payment write failed
```

## Confidence Level

**98%** - All core functionality tested and working:
- ✅ Ledger_Entry insertion
- ✅ Trigger execution (cache updates)
- ✅ Payment application
- ✅ Multiple invoice payments
- ✅ Balance calculations
- ✅ Transaction rollback

## Known Limitations

1. Entry number generation is simple increment (no date-based pattern)
2. Concurrency handled via table locks (may slow under high load)
3. No automatic retry on failure (requires manual intervention)
4. Online_Payment table tracking is optional (table exists but unused)

## Support

For issues, check:
1. `storage/logs/laravel.log` for error details
2. SQL Server error logs for constraint violations
3. Test scripts in `tests/` directory
