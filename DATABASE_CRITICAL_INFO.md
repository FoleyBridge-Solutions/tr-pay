# ⚠️⚠️⚠️ CRITICAL DATABASE INFORMATION ⚠️⚠️⚠️

## ⚠️ DEVELOPMENT MODE - UPDATED CONFIGURATION ⚠️

**IMPORTANT CHANGE**: The `sqlsrv` connection now points to **TEST_DB** for safe development.

## TWO SEPARATE DATABASES - DO NOT CONFUSE!

### 1. SQLite Database (Default - WRITABLE)
- **Connection Name**: `sqlite`
- **File**: `/var/www/itflow-laravel/database/database.sqlite`
- **Purpose**: Store THIS application's data
- **Permissions**: FULL READ/WRITE access
- **Use for**:
  - User sessions
  - Cache
  - Migrations table
  - Payment methods (from mipaymentchoice-cashier)
  - Subscriptions (from mipaymentchoice-cashier)
  - Any new tables we create
  - Customer models with Billable trait

### 2. Microsoft SQL Server - PracticeCS Test Database
- **Connection Name**: `sqlsrv` (currently points to CSP_345844_TestDoNotUse)
- **Host**: Configured via TEST_DB_HOST
- **Database**: `CSP_345844_TestDoNotUse`
- **Permissions**: FULL READ/WRITE access (for testing)
- **Use for**:
  - Reading Client data
  - Reading Invoice data
  - Reading LedgerEntry data
  - Testing payment integration (SAFE to write)
  - All PracticeCS operations during development

### 3. Microsoft SQL Server - Production (NOT CURRENTLY USED)
- **Database**: `CSP_345844_BurkhartPeterson`
- **Status**: Not configured for use yet
- **Future Use**: Will be configured when ready for production deployment

## NEVER EVER DO THIS:
```php
// ❌ WRONG - Will try to create tables on SQL Server!
php artisan migrate

// ❌ WRONG - Using sqlsrv as default
DB_CONNECTION=sqlsrv  // in .env

// ❌ WRONG - Creating tables on SQL Server
Schema::create('users', function($table) { ... });
```

## ALWAYS DO THIS:
```php
// ✅ CORRECT - Run migrations on SQLite
DB_CONNECTION=sqlite  // in .env (already set)
php artisan migrate   // Creates tables in SQLite

// ✅ CORRECT - Explicit connection for reading SQL Server
DB::connection('sqlsrv')->table('Client')->where(...)->get();

// ✅ CORRECT - Models that read from SQL Server
class Client extends Model {
    protected $connection = 'sqlsrv'; // Explicit!
}

// ✅ CORRECT - Models for our app data (use default SQLite)
class Customer extends Model {
    use Billable;  // This will use SQLite for payment_methods, subscriptions
}
```

## Migration Command Safety
When you see this error:
```
CREATE TABLE permission denied in database 'CSP_345844_BurkhartPeterson'
```

This means `.env` has `DB_CONNECTION=sqlsrv` - CHANGE IT BACK TO `sqlite`!

## Environment Variable Settings
**CURRENT .env settings (DEVELOPMENT MODE):**
```
DB_CONNECTION=sqlite  # Default for migrations and app tables

# SQL Server connection - CURRENTLY USING CSP_345844_TestDoNotUse (SAFE FOR DEVELOPMENT)
DB_HOST=practicecs.bpc.local
DB_PORT=65454
DB_DATABASE=CSP_345844_BurkhartPeterson  # NOT CURRENTLY USED
DB_USERNAME=graphana
DB_PASSWORD=Tw3nt05!
DB_ENCRYPT=yes
DB_TRUST_SERVER_CERTIFICATE=true

# Test Database (CURRENTLY IN USE FOR ALL MSSQL OPERATIONS)
TEST_DB_HOST=${DB_HOST}
TEST_DB_PORT=${DB_PORT}
TEST_DB_DATABASE=CSP_345844_TestDoNotUse
TEST_DB_USERNAME=${DB_USERNAME}
TEST_DB_PASSWORD=${DB_PASSWORD}

# PracticeCS Payment Integration (safe to enable - writes to test database)
PRACTICECS_WRITE_ENABLED=false
PRACTICECS_CONNECTION=sqlsrv  # Only connection available, points to test DB
```

## ⚠️⚠️⚠️ COMMON MISTAKE - DB QUERIES WITHOUT EXPLICIT CONNECTION

### ❌ WRONG - Will use default (SQLite) connection:
```php
// This will try to query SQLite database!
DB::select("SELECT * FROM Client WHERE client_id = ?", [$id]);
DB::selectOne("SELECT * FROM Invoice WHERE ...");
DB::table('Client')->where(...)->get();
```

### ✅ CORRECT - Always specify 'sqlsrv' connection:
```php
// Correctly queries SQL Server database
DB::connection('sqlsrv')->select("SELECT * FROM Client WHERE client_id = ?", [$id]);
DB::connection('sqlsrv')->selectOne("SELECT * FROM Invoice WHERE ...");
DB::connection('sqlsrv')->table('Client')->where(...)->get();
```

### Files That MUST Use DB::connection('sqlsrv'):
- ✅ `app/Repositories/PaymentRepository.php` - Fixed
- ✅ `app/Livewire/PaymentFlow.php` - Fixed
- Any other file that queries: Client, Invoice, LedgerEntry, Contact, Entity, Custom_Value, etc.

### If You Get "could not find driver (Connection: sqlite, SQL: SELECT...FROM Client)"
This means you forgot to add `->connection('sqlsrv')` to your DB query!
