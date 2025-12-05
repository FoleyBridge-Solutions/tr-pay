# PracticeCS Payment Integration - Deployment Guide

## Pre-Deployment Checklist

### 1. Database Setup

#### Production Database Permissions

Run as SQL Server admin:

```sql
-- Connect to production database
USE CSP_345844_BurkhartPeterson;

-- Grant permissions to application user (replace 'graphana' with actual user)
GRANT INSERT, UPDATE ON Ledger_Entry TO graphana;
GRANT INSERT, UPDATE ON Ledger_Entry_Application TO graphana;
GRANT SELECT, INSERT, UPDATE ON Client_Date_Cache TO graphana;
GRANT SELECT, INSERT, UPDATE ON Sheet_Entry_Open_Value_Cache TO graphana;

-- Verify permissions
SELECT 
    HAS_PERMS_BY_NAME('Ledger_Entry', 'OBJECT', 'INSERT') AS can_insert_ledger,
    HAS_PERMS_BY_NAME('Ledger_Entry_Application', 'OBJECT', 'INSERT') AS can_insert_application,
    HAS_PERMS_BY_NAME('Client_Date_Cache', 'OBJECT', 'UPDATE') AS can_update_cache
AS graphana;
```

#### Create Dedicated Staff Account (Recommended)

```sql
-- In PracticeCS, create new staff account:
-- Staff ID: ONLINEPAY
-- Name: Online Payment System
-- Note the generated staff_KEY
```

### 2. Environment Configuration

#### Add to Production `.env`

```bash
# PracticeCS Integration
PRACTICECS_WRITE_ENABLED=false          # Start disabled, enable after testing
PRACTICECS_CONNECTION=sqlsrv            # Production connection
PRACTICECS_STAFF_KEY=1552               # Update with actual staff_KEY
PRACTICECS_BANK_ACCOUNT_KEY=2           # Verify correct bank account
PRACTICECS_AUTO_POST=true               # Auto-approve payments
PRACTICECS_TRACK_ONLINE=false           # Optional: track in Online_Payment table

# Test Database Connection (for staging)
TEST_DB_HOST=${DB_HOST}
TEST_DB_PORT=${DB_PORT}
TEST_DB_DATABASE=TEST_DB
TEST_DB_USERNAME=${DB_USERNAME}
TEST_DB_PASSWORD=${DB_PASSWORD}
```

#### Verify Configuration Values

```bash
# Check staff account exists
php artisan tinker
DB::connection('sqlsrv')->select("
    SELECT staff_KEY, staff_id, description 
    FROM Staff 
    WHERE staff_KEY = ?
", [1552]);

# Check bank account exists
DB::connection('sqlsrv')->select("
    SELECT bank_account_KEY, description 
    FROM Bank_Account 
    WHERE bank_account_KEY = ?
", [2]);

# Verify ledger types
DB::connection('sqlsrv')->select("
    SELECT ledger_entry_type_KEY, description 
    FROM Ledger_Entry_Type 
    WHERE ledger_entry_type_KEY IN (8, 9, 11)
");
```

### 3. Staging Environment Testing

#### Step 1: Test with TEST_DB

```bash
# Set to use test database
PRACTICECS_WRITE_ENABLED=true
PRACTICECS_CONNECTION=sqlsrv_test

# Run integration tests
php tests/PracticeCsPaymentIntegrationTest.php
php tests/TestPaymentApplication.php

# Both should show "ALL TESTS PASSED"
```

#### Step 2: Test End-to-End Payment Flow

```bash
# Use MiPaymentChoice test cards
# See test card numbers below

# Test scenarios:
1. Single invoice payment (credit card)
2. Multiple invoice payment (credit card)
3. Partial payment (credit card)
4. ACH payment
5. Payment plan setup

# After each test, verify in TEST_DB:
SELECT TOP 5 * FROM Ledger_Entry 
WHERE reference LIKE 'mpc_%' 
ORDER BY create_date_utc DESC;

SELECT * FROM Ledger_Entry_Application 
WHERE to__ledger_entry_KEY IN (
    SELECT ledger_entry_KEY FROM Ledger_Entry 
    WHERE reference LIKE 'mpc_%'
);
```

### 4. Production Deployment

#### Phase 1: Deploy Code (Integration Disabled)

```bash
# 1. Deploy code to production
git pull origin main

# 2. Install dependencies
composer install --no-dev --optimize-autoloader

# 3. Clear caches
php artisan config:clear
php artisan cache:clear
php artisan view:clear

# 4. Verify integration is DISABLED
php artisan tinker
config('practicecs.payment_integration.enabled');  # Should be false
exit
```

#### Phase 2: Monitor Without Writing

```bash
# Keep PRACTICECS_WRITE_ENABLED=false
# Monitor logs to see when integration WOULD have written:

tail -f storage/logs/laravel.log | grep "PracticeCS"

# Process some test payments
# Verify they complete successfully
# Check logs show payment details
```

#### Phase 3: Enable for Test Transactions

```bash
# Update .env
PRACTICECS_WRITE_ENABLED=true

# Clear config cache
php artisan config:clear

# Process ONE test payment with SMALL amount
# Use test card: 4111111111111111

# Immediately verify in PracticeCS:
SELECT TOP 1 * FROM Ledger_Entry 
WHERE reference LIKE '%[transaction_id]%'
ORDER BY create_date_utc DESC;

# Check client balance updated
# Check invoice balance reduced
# Check triggers fired correctly
```

#### Phase 4: Full Production Enable

```bash
# If test payment succeeded:
# 1. Monitor for 1-2 hours
# 2. Process 3-5 real customer payments
# 3. Verify each in PracticeCS
# 4. Confirm with accounting team

# If all checks pass:
# Integration is live!
```

## Monitoring & Alerts

### Log Monitoring

Add to monitoring system:

```bash
# Success pattern
PracticeCS: Payment written successfully

# Failure patterns (ALERT)
PracticeCS: Payment write failed
Failed to write payment to PracticeCS
SQLSTATE[23000]  # Constraint violation
SQLSTATE[42000]  # Permission denied
```

### Database Monitoring

```sql
-- Check recent payments
SELECT TOP 10 
    LE.ledger_entry_KEY,
    LE.entry_number,
    LE.entry_date,
    LE.amount,
    LE.reference,
    LE.comments
FROM Ledger_Entry LE
WHERE LE.reference LIKE 'mpc_%'
ORDER BY LE.create_date_utc DESC;

-- Check payment applications
SELECT 
    COUNT(*) as total_applications,
    SUM(applied_amount) as total_applied
FROM Ledger_Entry_Application
WHERE to__ledger_entry_KEY IN (
    SELECT ledger_entry_KEY 
    FROM Ledger_Entry 
    WHERE reference LIKE 'mpc_%'
);

-- Check for orphaned payments (no applications)
SELECT * FROM Ledger_Entry LE
WHERE LE.reference LIKE 'mpc_%'
AND NOT EXISTS (
    SELECT 1 FROM Ledger_Entry_Application LEA
    WHERE LEA.to__ledger_entry_KEY = LE.ledger_entry_KEY
);
```

### Daily Reconciliation

```sql
-- Compare SQLite vs PracticeCS payments
-- SQLite
SELECT 
    DATE(created_at) as payment_date,
    COUNT(*) as count,
    SUM(total_amount) as total
FROM payments
WHERE DATE(created_at) = CURRENT_DATE
GROUP BY DATE(created_at);

-- PracticeCS
SELECT 
    CAST(entry_date AS DATE) as payment_date,
    COUNT(*) as count,
    SUM(ABS(amount)) as total
FROM Ledger_Entry
WHERE reference LIKE 'mpc_%'
AND CAST(entry_date AS DATE) = CAST(GETDATE() AS DATE)
GROUP BY CAST(entry_date AS DATE);
```

## Rollback Plan

### Emergency Disable

```bash
# Immediate disable
# Update .env
PRACTICECS_WRITE_ENABLED=false

# Clear config
php artisan config:clear

# Verify disabled
php artisan tinker
config('practicecs.payment_integration.enabled');  # false
```

### Data Cleanup (if needed)

```sql
-- Find test/duplicate entries
SELECT * FROM Ledger_Entry
WHERE reference LIKE 'TEST_%'
OR comments LIKE '%TEST:%';

-- Delete test entries (within transaction)
BEGIN TRANSACTION;

-- Store keys to delete
DECLARE @keys TABLE (ledger_entry_KEY INT);

INSERT INTO @keys
SELECT ledger_entry_KEY FROM Ledger_Entry
WHERE reference LIKE 'TEST_%';

-- Delete applications first (foreign key)
DELETE FROM Ledger_Entry_Application
WHERE to__ledger_entry_KEY IN (SELECT ledger_entry_KEY FROM @keys);

-- Delete ledger entries
DELETE FROM Ledger_Entry
WHERE ledger_entry_KEY IN (SELECT ledger_entry_KEY FROM @keys);

-- Review before committing
SELECT @@ROWCOUNT as rows_deleted;

-- COMMIT or ROLLBACK;
ROLLBACK;  -- Safe default
```

## Test Card Numbers (MiPaymentChoice)

### Successful Transactions

| Card Type | Number | CVV | Result |
|-----------|--------|-----|--------|
| Visa | 4111 1111 1111 1111 | 999 | APPROVED |
| Visa | 4012 8888 8888 1881 | Any | APPROVED |
| MasterCard | 5555 5555 5555 4444 | 998 | APPROVED |
| MasterCard | 5105 1051 0510 5100 | Any | APPROVED |
| Amex | 3782 822463 10005 | 9997 | APPROVED |
| Discover | 6011 1111 1111 1117 | Any | APPROVED |

### Test Scenarios

| Card Type | Number | CVV | Result |
|-----------|--------|-----|--------|
| MasterCard | 5499 7400 0000 0057 | 997 | DECLINED |
| MasterCard | 5499 7400 0000 0057 | 998 | APPROVED (AVS No Match) |
| Visa | 4111 1111 1111 1111 | 998 | CVV2 MISMATCH |

**Important:** Use expiration date in future (format: MMYY)

## Post-Deployment Verification

### Week 1 Checklist

- [ ] Process 10+ test transactions
- [ ] Verify all appear in PracticeCS
- [ ] Confirm client balances match
- [ ] Check invoice balances reduced correctly
- [ ] Review logs daily for errors
- [ ] Reconcile totals with accounting

### Week 2 Checklist

- [ ] Validate cache tables updating correctly
- [ ] Test payment plan transactions
- [ ] Test multiple invoice payments
- [ ] Test partial payments
- [ ] Verify all payment methods (CC, ACH, Check)

### Monthly Checklist

- [ ] Run reconciliation report
- [ ] Review error logs
- [ ] Check for orphaned payments
- [ ] Verify entry_number sequence
- [ ] Review performance metrics

## Troubleshooting

### Payment Written to Gateway but Not PracticeCS

**Symptom:** Payment succeeded in MiPaymentChoice, but error in logs about PracticeCS

**Action:**
1. Check `storage/logs/laravel.log` for exact error
2. Payment is SAFE - customer charged and recorded in SQLite
3. Can manually create entry in PracticeCS or retry

**Manual Entry:**
```sql
-- Use data from SQLite payments table
-- Follow same INSERT pattern as PracticeCsPaymentWriter
```

### Constraint Violation Errors

**Common Issues:**
- Date has time component → Use `CAST(GETDATE() AS DATE)`
- Amount is positive → Must be negative for payments
- Invalid foreign key → Verify staff/bank account exists

### Permission Denied

**Action:**
1. Verify database permissions (see step 1)
2. Check user account: `SELECT USER_NAME(), SUSER_NAME()`
3. Re-grant permissions if needed

## Contact & Support

- **Development Team:** [Your contact]
- **PracticeCS Admin:** [Their contact]
- **Database Admin:** [Their contact]
- **Emergency:** Disable integration immediately, payments still work

## Success Criteria

✅ Integration is successful when:
1. All payments appear in both SQLite and PracticeCS
2. Client balances match between systems
3. Invoice balances reduce correctly
4. No errors in logs for 1 week
5. Accounting team confirms reconciliation
6. Performance acceptable (< 2 second payment processing)

## Notes

- Payment gateway charges happen FIRST
- PracticeCS write happens AFTER
- If PracticeCS write fails, payment is still successful
- Manual reconciliation may be needed for failed writes
- Keep TEST_DB available for ongoing testing
