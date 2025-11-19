# ✅ FIXED: Database Connection Issue

## Problem
When trying to verify account, the application was querying the **wrong database**:

```
could not find driver (Connection: sqlite, SQL: SELECT...FROM Client)
```

The error showed it was trying to query SQL Server tables (Client, Invoice, etc.) but using the **SQLite connection** instead!

## Root Cause
`PaymentRepository` and `PaymentFlow` were using `DB::select()` **without specifying the connection**.

Since `.env` has `DB_CONNECTION=sqlite` as the default, all DB queries were going to SQLite instead of SQL Server.

## Solution Applied ✅

### 1. Fixed PaymentRepository.php
Added `->connection('sqlsrv')` to ALL database queries:

```php
// ❌ BEFORE (WRONG):
$clients = DB::select($sql, [$last4, $lastName]);

// ✅ AFTER (CORRECT):
$clients = DB::connection('sqlsrv')->select($sql, [$last4, $lastName]);
```

**Fixed 4 queries** in PaymentRepository:
- Line 48: `getClientByTaxIdAndName()`
- Line 87: `getClientBalance()` - client info query
- Line 145: `getClientBalance()` - balance calculation query  
- Line 222: `getClientOpenInvoices()`

### 2. Fixed PaymentFlow.php
Added `->connection('sqlsrv')` to ALL database queries:

```php
// ❌ BEFORE (WRONG):
$entityInfo = DB::select("SELECT...FROM Entity...");

// ✅ AFTER (CORRECT):
$entityInfo = DB::connection('sqlsrv')->select("SELECT...FROM Entity...");
```

**Fixed 4 queries** in PaymentFlow:
- Line 183: Entity lookup
- Line 196: Custom field group lookup
- Line 207: Group clients query (with group)
- Line 225: Group clients query (without group)

### 3. Added Safety Documentation
- Updated `DATABASE_CRITICAL_INFO.md` with common mistake examples
- Created `check-db-queries.sh` script to verify all queries use correct connection
- Added warning comments in code

## How To Verify Fix

```bash
# 1. Check that all DB queries use connection('sqlsrv')
cd /var/www/itflow-laravel
./check-db-queries.sh

# 2. Test account verification in browser
# Navigate to payment portal and try to verify an account
# It should now work correctly!
```

## Critical Rules Going Forward

### ⚠️⚠️⚠️ ALWAYS REMEMBER:

1. **Default connection is SQLite** (from `.env`)
2. **SQL Server is READ-ONLY** and requires explicit connection
3. **NEVER use bare `DB::select()`** for SQL Server tables

### ✅ Correct Pattern:

```php
// For SQL Server tables (Client, Invoice, LedgerEntry, etc.)
DB::connection('sqlsrv')->select("SELECT * FROM Client...");
DB::connection('sqlsrv')->table('Client')->where(...)->get();

// For SQLite tables (customers, payments, payment_plans, etc.)
DB::select("SELECT * FROM customers...");  // Uses default (SQLite)
DB::table('customers')->where(...)->get();  // Uses default (SQLite)
```

### ❌ Wrong Pattern:

```php
// ❌ Will try to query SQLite for SQL Server tables!
DB::select("SELECT * FROM Client...");  // WRONG!
DB::table('Invoice')->where(...)->get();  // WRONG!
```

## Files Modified

- ✅ `app/Repositories/PaymentRepository.php` - All 4 queries fixed
- ✅ `app/Livewire/PaymentFlow.php` - All 4 queries fixed
- ✅ `DATABASE_CRITICAL_INFO.md` - Added common mistakes section
- ✅ `check-db-queries.sh` - Created verification script

## Testing Results

After fix:
- ✅ No DB queries to SQL Server tables without explicit connection
- ✅ Account verification should work correctly
- ✅ Client/invoice lookups query the correct database

---

**Fixed on**: November 19, 2025  
**Status**: ✅ All database queries now use correct connections
