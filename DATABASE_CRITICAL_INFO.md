# ⚠️⚠️⚠️ CRITICAL DATABASE INFORMATION ⚠️⚠️⚠️

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

### 2. Microsoft SQL Server (READ-ONLY! DO NOT WRITE!)
- **Connection Name**: `sqlsrv`
- **Host**: `practicecs.bpc.local:65454`
- **Database**: `CSP_345844_BurkhartPeterson`
- **Owner**: ANOTHER APPLICATION (NOT US!)
- **Permissions**: READ-ONLY
- **Use for**:
  - Reading Client data
  - Reading Invoice data
  - Reading LedgerEntry data
  - Reading Entity data
  - NOTHING ELSE!

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
**CORRECT .env settings:**
```
DB_CONNECTION=sqlite  # Default for migrations and app tables

# SQL Server connection (READ-ONLY - only for explicit queries)
DB_HOST=practicecs.bpc.local
DB_PORT=65454
DB_DATABASE=CSP_345844_BurkhartPeterson
DB_USERNAME=graphana
DB_PASSWORD=Tw3nt05!
DB_TRUST_SERVER_CERTIFICATE=true
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
