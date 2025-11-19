# Migration Guide: itflow-ng → Laravel

## Project Overview
Migrating payment portal from custom PHP MVC to Laravel 12.

**Original Location:** `/var/www/itflow-ng`  
**Laravel Location:** `/var/www/itflow-laravel`

---

## Phase 1: Models & Database ✓ IN PROGRESS

### Models to Create

#### 1. Client Model
```bash
php artisan make:model Client
```

**File:** `app/Models/Client.php`
- Table: `Client`
- Primary Key: `client_KEY`
- Relationships: contacts, ledgerEntries, invoices

#### 2. Employee Model
```bash
php artisan make:model Employee
```

**File:** `app/Models/Employee.php`
- Table: `employees` 
- Primary Key: `staff_KEY`
- Fields: first_name, last_name, staff_status_KEY, benefits, salary, hours

#### 3. Contact Model
```bash
php artisan make:model Contact
```

**File:** `app/Models/Contact.php`
- Table: `Contact`
- Primary Key: `contact_KEY`

#### 4. LedgerEntry Model
```bash
php artisan make:model LedgerEntry
```

**File:** `app/Models/LedgerEntry.php`
- Table: `Ledger_Entry`
- Primary Key: `ledger_entry_KEY`

#### 5. Invoice Model
```bash
php artisan make:model Invoice
```

**File:** `app/Models/Invoice.php`
- Table: `Invoice`
- Relationships: ledgerEntry

---

## Phase 2: Repositories

Create repository pattern for complex queries:

```bash
mkdir app/Repositories
```

### PaymentRepository
**File:** `app/Repositories/PaymentRepository.php`

Methods to port from `src/Model/PaymentModel.php`:
- `getClientByEmail(string $email)`
- `getClientBalance(int $clientKey): array`
- `getClientOpenInvoices(int $clientKey): array`

### EmployeeRepository
**File:** `app/Repositories/EmployeeRepository.php`

Methods to port from `src/Model/Employee.php`:
- `sync()` - Complex employee synchronization

---

## Phase 3: Controllers

### Controllers to Create

```bash
php artisan make:controller PaymentController
php artisan make:controller EmployeeController --resource
php artisan make:controller AdminController
php artisan make:controller HomeController
```

### Mapping

| Old Controller | New Controller | Methods |
|----------------|----------------|---------|
| PaymentController | App\Http\Controllers\PaymentController | verifyEmail, verifyCode, savePaymentInfo, savePaymentMethod, confirmPayment |
| EmployeeController | App\Http\Controllers\EmployeeController | index, edit, update, sync |
| AdminController | App\Http\Controllers\AdminController | users, addUser, editUser, updateUserField |
| HomeController | App\Http\Controllers\HomeController | index |

---

## Phase 4: Routes

### Web Routes (routes/web.php)

```php
// Payment Portal (Public)
Route::prefix('payment')->name('payment.')->group(function () {
    Route::get('/', [PaymentController::class, 'start'])->name('start');
    Route::post('/verify-email', [PaymentController::class, 'verifyEmail'])->name('verify-email');
    Route::post('/verify-code', [PaymentController::class, 'verifyCode'])->name('verify-code');
    Route::get('/payment-info', [PaymentController::class, 'paymentInfo'])->name('payment-info');
    Route::post('/save-payment-info', [PaymentController::class, 'savePaymentInfo'])->name('save-payment-info');
    Route::get('/payment-method', [PaymentController::class, 'paymentMethod'])->name('payment-method');
    Route::post('/save-payment-method', [PaymentController::class, 'savePaymentMethod'])->name('save-payment-method');
    Route::get('/confirmation', [PaymentController::class, 'confirmation'])->name('confirmation');
});

// Admin Routes (Protected)
Route::middleware(['auth'])->prefix('admin')->name('admin.')->group(function () {
    Route::get('/users', [AdminController::class, 'users'])->name('users');
    Route::get('/users/create', [AdminController::class, 'create'])->name('users.create');
    Route::post('/users', [AdminController::class, 'store'])->name('users.store');
    Route::get('/users/{id}/edit', [AdminController::class, 'edit'])->name('users.edit');
});

// Employee Routes (Protected)
Route::middleware(['auth'])->group(function () {
    Route::resource('employees', EmployeeController::class);
    Route::post('/employees/sync', [EmployeeController::class, 'sync'])->name('employees.sync');
});

// API Routes
Route::prefix('api')->middleware(['auth:sanctum'])->group(function () {
    Route::patch('/employees/{id}/field', [EmployeeController::class, 'updateField']);
    Route::patch('/users/{id}/field', [AdminController::class, 'updateUserField']);
});
```

---

## Phase 5: Views (Blade Templates)

### Directory Structure
```
resources/views/
├── layouts/
│   ├── app.blade.php
│   ├── navbar.blade.php
│   └── footer.blade.php
├── payment/
│   ├── email-verification.blade.php
│   ├── verify-code.blade.php
│   ├── payment-information.blade.php
│   ├── payment-method.blade.php
│   └── confirmation.blade.php
├── employees/
│   ├── index.blade.php
│   └── edit.blade.php
├── admin/
│   └── users/
│       ├── index.blade.php
│       ├── create.blade.php
│       └── edit.blade.php
└── components/
    └── simple-table.blade.php
```

### View Migration Map

| Old View | New View |
|----------|----------|
| src/View/header.php | layouts/app.blade.php (header section) |
| src/View/navbar.php | layouts/navbar.blade.php |
| src/View/footer.php | layouts/footer.blade.php |
| src/View/email_verification.php | payment/email-verification.blade.php |
| src/View/verify_code.php | payment/verify-code.blade.php |
| src/View/payment_information.php | payment/payment-information.blade.php |
| src/View/payment_method.php | payment/payment-method.blade.php |
| src/View/confirmation.php | payment/confirmation.blade.php |
| src/View/simpleTable.php | components/simple-table.blade.php |
| src/View/employee/edit_employee.php | employees/edit.blade.php |
| src/View/admin/user/add_user.php | admin/users/create.blade.php |
| src/View/admin/user/edit_user.php | admin/users/edit.blade.php |

---

## Phase 6: Mail

### Mailables to Create

```bash
php artisan make:mail VerificationCodeMail
```

**File:** `app/Mail/VerificationCodeMail.php`

Replace `src/Service/EmailService.php` functionality.

---

## Phase 7: Middleware

### Custom Middleware

```bash
php artisan make:middleware CheckRole
php artisan make:middleware EnsurePaymentStep
```

---

## Phase 8: Testing

### Feature Tests to Create

```bash
php artisan make:test PaymentFlowTest
php artisan make:test EmployeeSyncTest
php artisan make:test AdminUserManagementTest
```

---

## Session Data Migration

### Current Session Variables (itflow-ng)
- `current_step`
- `company_info`
- `verification_code`
- `code_expiry`
- `payment_amount`
- `selected_invoices`
- `payment_method`
- `open_invoices`
- `client_name`
- `client_id`
- `has_multiple_clients`
- `clients_data`

### Laravel Session Usage
Use `session()` helper or `Session` facade:
```php
session(['current_step' => 'email_verification']);
$step = session('current_step');
session()->forget('current_step');
```

---

## Database Connection Notes

- **Driver:** SQL Server (sqlsrv)
- **Host:** practicecs.bpc.local:65454
- **Database:** CSP_345844_BurkhartPeterson
- **No Migrations Needed:** Connecting to existing Practice CS database
- **Read-Only Tables:** Client, Contact, Ledger_Entry, Invoice (Practice CS tables)
- **App Tables:** users, employees (can be modified)

---

## Command Line Helpers

### Test Database Connection
```bash
php artisan tinker
>>> DB::connection()->getPdo();
>>> DB::table('Client')->first();
```

### Generate Application Key
```bash
php artisan key:generate
```

### Clear Cache
```bash
php artisan cache:clear
php artisan config:clear
php artisan route:clear
php artisan view:clear
```

### Run Development Server
```bash
php artisan serve
```

---

## Next Steps

1. ✓ Create Laravel project
2. ✓ Configure .env for SQL Server
3. ✓ Update database.php config
4. ⏳ Create Models
5. ⏳ Create Repositories
6. ⏳ Create Controllers
7. ⏳ Create Routes
8. ⏳ Create Blade Views
9. ⏳ Create Mailables
10. ⏳ Test Payment Flow
11. ⏳ Test Employee Management
12. ⏳ Deploy

---

## Important Notes

- Keep original `/var/www/itflow-ng` intact during migration
- Test each component before moving to next phase
- Use Laravel best practices (Eloquent, validation, etc.)
- Leverage Laravel features (queues, jobs, events where appropriate)
- Add comprehensive error handling
- Implement proper logging
