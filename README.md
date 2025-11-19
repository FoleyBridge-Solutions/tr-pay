# Payment Gateway - Laravel 12

A modern, public-facing payment gateway built with Laravel 12 for clients to make payments against their invoices.

## 🚀 Quick Start

```bash
cd /var/www/itflow-laravel
composer install
php artisan serve
```

Visit: `http://localhost:8000/payment`

## 📋 Features

### Public Payment Portal
- **Simple Account Verification** - Enter last 4 of SSN/EIN + last name (senior-friendly, no email codes)
- **Instant Access** - Immediately view balance and open invoices after verification
- **Multi-Client Support** - Handles companies with multiple client accounts
- **Invoice Viewing** - Displays all open invoices with balances
- **Flexible Payment Amounts** - Pay full balance or custom amount
- **Multiple Payment Methods** - Credit Card (3% fee), ACH, or Check
- **Payment Confirmation** - Transaction ID generation and confirmation page
- **Secure** - Tax ID + name verification against Practice CS database

## 🗂️ Project Structure

```
app/
├── Http/
│   └── Controllers/
│       └── PaymentController.php      # All payment flow logic
├── Models/
│   ├── Client.php                      # Client/company data
│   ├── Contact.php                     # Contact information
│   ├── Invoice.php                     # Invoice records
│   └── LedgerEntry.php                 # Payment ledger
├── Repositories/
│   └── PaymentRepository.php           # Database queries
└── Mail/
    └── VerificationCodeMail.php        # Email notifications

resources/views/payment/
├── email-verification.blade.php        # Step 1: SSN/EIN + Last name entry
├── payment-information.blade.php       # Step 2: Amount selection
├── payment-method.blade.php            # Step 3: Payment method
└── confirmation.blade.php              # Step 4: Confirmation
```

## 🔄 Payment Flow

1. **Account Verification** → Enter last 4 of SSN/EIN + last name
2. **Payment Information** → View invoices, select amount
3. **Payment Method** → Choose payment method (CC/ACH/Check)
4. **Confirmation** → Display transaction details

**Simple 4-step process - no email verification needed!**

## 📝 Available Routes

```
GET  /                                  - Redirects to payment portal
GET  /payment                           - Start payment flow (account verification)
POST /payment/verify-account            - Verify SSN/EIN + last name
GET  /payment/payment-information       - View invoices and select amount
POST /payment/save-payment-info         - Save payment details
GET  /payment/payment-method            - Select payment method
POST /payment/save-payment-method       - Save payment method
GET  /payment/confirmation              - Show confirmation page
```

Full route list: `php artisan route:list` (8 routes total)

## 🔧 Configuration

Database and email settings are configured in `.env`:

```env
DB_CONNECTION=sqlsrv
DB_HOST=practicecs.bpc.local
DB_PORT=65454
DB_DATABASE=your_database

MAIL_MAILER=smtp
MAIL_HOST=mail.smtp2go.com
MAIL_PORT=2525
```

## 🛠️ Common Commands

```bash
php artisan route:list          # List all routes
php artisan tinker              # Interactive shell
php artisan optimize:clear      # Clear all caches
composer install                # Install dependencies
npm run build                   # Build assets
```

## 🔒 Security Features

- Email-based authentication (no passwords)
- Time-limited verification codes (15 minutes)
- Session-based state management
- SQL Server integration for data security

## 💳 Payment Methods

- **Credit Card** - 3% processing fee applied
- **ACH Transfer** - No additional fees
- **Check** - Traditional payment option

## ✅ Status

**Type:** Payment Gateway (Public)
**Version:** 2.0.0  
**Framework:** Laravel 12.38.1  
**Updated:** November 17, 2024
