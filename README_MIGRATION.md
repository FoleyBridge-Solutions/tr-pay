# itflow-ng → Laravel Migration

## Quick Start

This is the Laravel version of the itflow-ng payment portal.

### What's Been Done ✓

1. **Laravel 12 Installation** - Fresh Laravel project created
2. **Environment Configuration** - SQL Server connection configured
3. **Models Created** - Client, Employee, Contact, LedgerEntry, Invoice
4. **PaymentRepository** - Complex SQL queries ported from original app
5. **PaymentController** - Basic structure created
6. **Documentation** - Migration guide and reference documents

### Project Structure

```
itflow-laravel/
├── app/
│   ├── Http/
│   │   └── Controllers/
│   │       └── PaymentController.php (✓ Created)
│   ├── Models/
│   │   ├── Client.php (✓ Complete)
│   │   ├── Employee.php (✓ Complete)
│   │   ├── Contact.php (✓ Complete)
│   │   ├── LedgerEntry.php (✓ Complete)
│   │   └── Invoice.php (✓ Complete)
│   └── Repositories/
│       └── PaymentRepository.php (✓ Complete)
├── resources/
│   └── views/ (⏳ To be created)
├── routes/
│   └── web.php (⏳ To be configured)
└── database/ (No migrations needed - using existing DB)
```

### Next Steps

#### 1. Create Routes (routes/web.php)
```php
use App\Http\Controllers\PaymentController;

Route::prefix('payment')->name('payment.')->group(function () {
    Route::get('/', [PaymentController::class, 'start'])->name('start');
    Route::post('/verify-email', [PaymentController::class, 'verifyEmail'])->name('verify-email');
    // Add remaining routes...
});
```

#### 2. Create Blade Views
```bash
mkdir -p resources/views/payment
mkdir -p resources/views/layouts
```

Copy and convert from:
- `src/View/email_verification.php` → `resources/views/payment/email-verification.blade.php`
- `src/View/header.php` + `footer.php` → `resources/views/layouts/app.blade.php`

#### 3. Create Mailable for Verification Code
```bash
php artisan make:mail VerificationCodeMail
```

#### 4. Test Database Connection
```bash
php artisan tinker
>>> DB::connection()->getPdo();
>>> DB::table('Client')->first();
```

#### 5. Run Development Server
```bash
php artisan serve
# Visit: http://localhost:8000/payment
```

### Environment Setup

The `.env` file is already configured for:
- SQL Server connection to Practice CS database
- SMTP settings for email
- Session configuration

### Original vs Laravel Comparison

| Feature | Original (itflow-ng) | Laravel |
|---------|---------------------|---------|
| Framework | Custom MVC | Laravel 12 |
| Routing | Query params (`?page=x`) | Named routes (`/payment/verify`) |
| Database | Raw `sqlsrv_*` + PDO | Eloquent + Query Builder |
| Views | PHP templates | Blade templates |
| Email | PHPMailer | Laravel Mail |
| Sessions | `$_SESSION` | `session()` helper |
| Validation | Manual | Form Requests |
| Error Handling | Manual | Exception Handler |

### Key Files Reference

#### Original Files Location
- Controllers: `/var/www/itflow-ng/src/Controller/`
- Models: `/var/www/itflow-ng/src/Model/`
- Views: `/var/www/itflow-ng/src/View/`
- Config: `/var/www/itflow-ng/config/`

#### Laravel Files Location
- Controllers: `app/Http/Controllers/`
- Models: `app/Models/`
- Repositories: `app/Repositories/`
- Views: `resources/views/`
- Routes: `routes/web.php`
- Config: `.env` and `config/`

### Database Schema (Practice CS)

**Tables Used (Read-Only):**
- `Client` - Client information
- `Contact` - Contact details
- `Contact_Email` - Email addresses
- `Ledger_Entry` - Financial transactions
- `Ledger_Entry_Type` - Transaction types
- `Ledger_Entry_Application` - Payment applications
- `Invoice` - Invoice details

**App Tables (Can Modify):**
- `employees` - Synced employee data
- `users` - Internal users (to be created)

### Laravel Features to Implement

1. **Form Request Validation**
   ```bash
   php artisan make:request PaymentInformationRequest
   ```

2. **Middleware**
   ```bash
   php artisan make:middleware EnsurePaymentStep
   ```

3. **Events & Listeners**
   ```bash
   php artisan make:event PaymentReceived
   php artisan make:listener SendPaymentConfirmation
   ```

4. **Jobs (for employee sync)**
   ```bash
   php artisan make:job SyncEmployeesJob
   ```

### Testing

```bash
# Run tests
php artisan test

# Create test
php artisan make:test PaymentFlowTest
```

### Documentation

- **MIGRATION_GUIDE.md** - Detailed migration steps
- **LARAVEL_MIGRATION.md** - High-level overview
- **AGENTS.md** - Original project guidelines

### Common Commands

```bash
# Clear all caches
php artisan optimize:clear

# Generate app key
php artisan key:generate

# List routes
php artisan route:list

# Interactive shell
php artisan tinker

# Create controller
php artisan make:controller NameController

# Create model
php artisan make:model ModelName
```

### Troubleshooting

**Database Connection Issues:**
```bash
# Test connection in tinker
php artisan tinker
>>> DB::connection()->getPdo();
```

**Session Issues:**
```bash
# Clear sessions
php artisan cache:clear
rm -rf storage/framework/sessions/*
```

**View Compilation:**
```bash
php artisan view:clear
```

### Security Notes

- ✓ CSRF protection enabled by default
- ✓ XSS protection via Blade `{{ }}` escaping
- ✓ SQL injection protection via query builder
- ⏳ Rate limiting to be added
- ⏳ Email verification throttling to be added

### Performance

- Use Laravel caching for repeated queries
- Consider queue for email sending
- Add database query logging in development

### Deployment Checklist

- [ ] Set `APP_ENV=production`
- [ ] Set `APP_DEBUG=false`
- [ ] Run `php artisan config:cache`
- [ ] Run `php artisan route:cache`
- [ ] Run `php artisan view:cache`
- [ ] Set proper file permissions
- [ ] Configure web server (Apache/Nginx)
- [ ] Set up SSL certificates
- [ ] Configure logging
- [ ] Set up monitoring

---

## Contributing

When adding new features, follow Laravel conventions:
- Controllers in `app/Http/Controllers`
- Models in `app/Models`
- Views in `resources/views`
- Use named routes
- Add validation via Form Requests
- Document all complex queries

## Support

Refer to:
- [Laravel Documentation](https://laravel.com/docs/12.x)
- Original itflow-ng code in `/var/www/itflow-ng`
- Migration guides in this directory
