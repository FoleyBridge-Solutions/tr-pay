# TR Pay - Payment Portal

A modern payment portal built with Laravel 12 and Livewire for FoleyBridge Solutions clients to make payments and accept project proposals.

## 🚀 Quick Start

```bash
cd /var/www/tr-pay
composer install
php artisan serve
```

Visit: `http://localhost:8000`

## 📋 Features

### Multi-Step Payment Flow
- **Account Verification** - Business or Personal account selection with SSN/EIN verification
- **Project Acceptance** - Review and accept EXP* engagement projects before payment
- **Invoice Selection** - View and select open invoices to pay
- **Payment Options** - Credit Card (3% fee), ACH, Check, or Payment Plans
- **Deferred Persistence** - Projects only saved after successful payment
- **MiPaymentChoice Integration** - Secure payment processing

### Project Acceptance Workflow
- Automatic detection of pending EXP* engagement type projects
- Checkbox acceptance (no signature typing required)
- Multi-project support with one-at-a-time review
- Option to decline projects and restart flow
- IP address tracking and timestamp logging

### Payment Options
- **Credit Card** - 3% processing fee, instant processing
- **ACH Transfer** - No fee, bank account processing
- **Check** - Mail-in payment option
- **Payment Plans** - Flexible installment schedules with customizable terms

## 🗂️ Project Structure

```
app/
├── Livewire/
│   └── PaymentFlow.php              # Main payment component
├── Models/
│   ├── Customer.php                  # Billable customer (MiPaymentChoice)
│   ├── ProjectAcceptance.php         # Project acceptance records
│   ├── Client.php                    # SQL Server client data
│   └── Invoice.php                   # SQL Server invoice data
├── Services/
│   ├── PaymentService.php            # MiPaymentChoice integration
│   └── PaymentPlanCalculator.php     # Payment plan scheduling
└── Repositories/
    └── PaymentRepository.php         # SQL Server queries

resources/views/livewire/
└── payment-flow.blade.php            # Payment UI

tests/
├── Feature/
│   ├── PaymentFlowTest.php           # Payment flow tests
│   ├── ProjectAcceptanceTest.php     # Project acceptance tests
│   ├── EndToEndPaymentFlowTest.php   # Integration tests
│   └── PaymentPlanTest.php           # Payment plan tests
└── Unit/
    └── PaymentServiceTest.php        # Payment service tests
```

## 🔄 Payment Flow

1. **Account Type Selection** → Business or Personal
2. **Account Verification** → Last 4 of SSN/EIN + Name
3. **Project Acceptance** → Review and accept EXP* projects (if any)
4. **Invoice Selection** → Select invoices to pay
5. **Payment Method** → Choose Credit Card, ACH, Check, or Payment Plan
6. **Payment Details** → Enter payment information
7. **Confirmation** → Transaction complete

## 📝 Available Routes

```
GET  /                           - Payment flow start
GET  /payment                    - Payment flow (Livewire component)
```

## 🔧 Configuration

### Environment Variables

```env
APP_NAME="TR Pay"

# SQLite (Local payment data)
DB_CONNECTION=sqlite

# MS SQL Server (PracticeCS client/invoice data)
SQLSRV_HOST=your_server
SQLSRV_DATABASE=your_database
SQLSRV_USERNAME=your_username
SQLSRV_PASSWORD=your_password

# MiPaymentChoice Gateway
MIPAYMENTCHOICE_USERNAME=your_api_username
MIPAYMENTCHOICE_PASSWORD=your_api_password
MIPAYMENTCHOICE_MERCHANT_KEY=your_merchant_key
MIPAYMENTCHOICE_BASE_URL=https://gateway.mipaymentchoice.com
```

## 🛠️ Common Commands

```bash
# Development
php artisan serve                  # Start dev server
php artisan test                   # Run test suite
php artisan migrate                # Run migrations

# Maintenance
php artisan optimize:clear         # Clear all caches
php artisan view:clear             # Clear view cache
composer install                   # Install dependencies
npm run build                      # Build assets
```

## 🧪 Testing

Comprehensive test suite with 70 tests covering:
- Payment flow functionality
- Project acceptance workflow
- Payment service integration
- End-to-end user journeys
- Payment plan calculations

```bash
php artisan test                   # Run all tests
php artisan test --filter PaymentFlowTest
```

See [TESTING.md](TESTING.md) for detailed testing documentation.

## 🔒 Security Features

- SSN/EIN + Name verification
- Session-based state management
- PracticeCS SQL Server integration
- SQLite for local payment data
- MiPaymentChoice secure payment processing
- IP address tracking for acceptance records

## 💳 Payment Methods

- **Credit Card** - 3% processing fee, instant
- **ACH Transfer** - No fee, 2-3 business days
- **Check** - Traditional mail-in payment
- **Payment Plans** - Installments with flexible schedules

## 📊 Database Architecture

- **SQLite** - Local storage for customers, project acceptances, payment methods
- **MS SQL Server** - PracticeCS clients, invoices, engagements

## 📚 Documentation

- [TESTING.md](TESTING.md) - Comprehensive testing guide
- [PROJECT_SUMMARY.md](PROJECT_SUMMARY.md) - Complete project overview
- [MIPAYMENTCHOICE_INTEGRATION.md](MIPAYMENTCHOICE_INTEGRATION.md) - Payment gateway docs

## ✅ Status

**Project:** TR Pay  
**Version:** 1.0.0  
**Framework:** Laravel 12  
**Frontend:** Livewire 3 + Flux UI  
**Payment Gateway:** MiPaymentChoice  
**Test Coverage:** 70 tests (45 passing)  
**Updated:** November 19, 2025

## 🚀 Deployment

See deployment checklist in [PROJECT_SUMMARY.md](PROJECT_SUMMARY.md) before going to production.

## 📝 License

Proprietary - FoleyBridge Solutions
