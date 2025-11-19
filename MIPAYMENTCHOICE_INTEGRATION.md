# MiPaymentChoice Integration Guide

## ✅ Completed Setup

The payment portal has been successfully migrated from Stripe to **MiPaymentChoice Cashier**.

## Architecture Overview

### Two Separate Databases

#### 1. SQLite Database (Default - WRITABLE)
- **Purpose**: Store application-specific data
- **Location**: `/var/www/itflow-laravel/database/database.sqlite`
- **Tables**:
  - `customers` - Customer records with Billable trait
  - `payment_methods` - Saved payment methods (from MiPaymentChoice Cashier)
  - `subscriptions` - Recurring subscriptions (from MiPaymentChoice Cashier)
  - `payments` - Payment transaction records
  - `payment_plans` - Payment plan schedules
  - `users` - User accounts
  - Standard Laravel tables (sessions, cache, jobs, migrations)

#### 2. Microsoft SQL Server (READ-ONLY)
- **Purpose**: Read client/invoice data from Practice CS
- **Connection Name**: `sqlsrv`
- **Database**: `CSP_345844_BurkhartPeterson`
- **⚠️ CRITICAL**: This database is owned by another application - NEVER write to it!
- **Use For**: Reading Client, Invoice, LedgerEntry, Entity data only

## MiPaymentChoice Configuration

### Environment Variables (.env)
```env
MIPAYMENTCHOICE_USERNAME=mcnorthapi1
MIPAYMENTCHOICE_PASSWORD=MCGws6sP2
MIPAYMENTCHOICE_MERCHANT_KEY=your_merchant_key_here
MIPAYMENTCHOICE_BASE_URL=https://gateway.mipaymentchoice.com
CASHIER_CURRENCY=usd
CASHIER_MODEL=App\Models\Customer
```

## Key Models

### Customer Model (`app/Models/Customer.php`)
- Uses the `Billable` trait from MiPaymentChoice Cashier
- Stored in **SQLite** database
- Methods available:
  - `createQuickPaymentsToken()` - Create one-time payment token
  - `chargeWithQuickPayments()` - Process one-time payment
  - `tokenizeCard()` - Create reusable card token
  - `tokenizeCheck()` - Create reusable check token
  - `addPaymentMethod()` - Save payment method for future use
  - `charge()` - Charge using saved payment method
  - `newSubscription()` - Create recurring subscription

### SQL Server Models (READ-ONLY)
- `Client` - Client data from Practice CS
- `Invoice` - Invoice data from Practice CS
- `LedgerEntry` - Ledger entries from Practice CS
- `Contact` - Contact data from Practice CS
- **All have `protected $connection = 'sqlsrv';` set explicitly**

## Payment Flow

### One-Time Payments (QuickPayments)

1. **Frontend**: Collect card/bank details
2. **Frontend**: Create QuickPayments token using MiPaymentChoice JS SDK
3. **Backend**: Receive token from frontend
4. **Backend**: Call `PaymentService::chargeWithQuickPayments()`
5. **MiPaymentChoice**: Process payment
6. **Backend**: Save payment record to SQLite

```php
$customer = Customer::where('client_key', $clientKey)->firstOrFail();
$qpToken = $request->input('qp_token'); // From frontend

$result = app(PaymentService::class)->chargeWithQuickPayments(
    $customer,
    $qpToken,
    $amount,
    ['description' => 'Invoice Payment']
);
```

### Payment Plans (Saved Payment Methods)

1. **Frontend**: Collect card/bank details
2. **Frontend**: Create reusable token using MiPaymentChoice JS SDK
3. **Backend**: Receive token from frontend
4. **Backend**: Call `PaymentService::setupPaymentPlan()`
5. **Backend**: Token is saved as payment method in SQLite
6. **Backend**: Future charges use saved payment method

```php
$customer = Customer::where('client_key', $clientKey)->firstOrFail();
$token = $request->input('token'); // From frontend

$result = app(PaymentService::class)->setupPaymentPlan(
    $paymentData,
    $clientInfo,
    $token
);
```

## Important Notes

### Frontend Integration Required

The backend is now ready, but **frontend integration is still needed**:

1. **Add MiPaymentChoice JS SDK** to your payment forms
2. **Tokenize card/bank data** on the frontend (never send raw card numbers to backend)
3. **Send tokens** to backend instead of raw payment details

Example frontend flow:
```javascript
// Load MiPaymentChoice SDK
<script src="https://gateway.mipaymentchoice.com/js/sdk.js"></script>

// Create QuickPayments token
const qpToken = await MiPaymentChoice.createQuickPaymentsToken({
    number: cardNumber,
    exp_month: expMonth,
    exp_year: expYear,
    cvc: cvv,
    name: cardholderName,
    // ... other fields
});

// Send token to backend
await fetch('/api/payment/process', {
    method: 'POST',
    body: JSON.stringify({ qp_token: qpToken })
});
```

### Testing

Use the test credentials for development:
```env
MIPAYMENTCHOICE_USERNAME=mcnorthapi1
MIPAYMENTCHOICE_PASSWORD=MCGws6sP2
```

### Database Safety

The application is configured to prevent accidental writes to the SQL Server:

1. `.env` has `DB_CONNECTION=sqlite` (default)
2. All SQL Server models have `protected $connection = 'sqlsrv';`
3. Config file has warning comments
4. `DATABASE_CRITICAL_INFO.md` file documents the separation

**NEVER** change `DB_CONNECTION` to `sqlsrv` in `.env`!

## Files Modified

- ✅ `config/database.php` - Added warnings about READ-ONLY SQL Server
- ✅ `.env` - Added MiPaymentChoice credentials, changed default to sqlite
- ✅ `app/Models/Customer.php` - Created with Billable trait
- ✅ `app/Models/Client.php` - Added sqlsrv connection and warnings
- ✅ `app/Models/Invoice.php` - Added sqlsrv connection and warnings
- ✅ `app/Models/LedgerEntry.php` - Added sqlsrv connection and warnings
- ✅ `app/Models/Contact.php` - Added sqlsrv connection and warnings
- ✅ `app/Models/Payment.php` - Completed implementation
- ✅ `app/Models/PaymentPlan.php` - Completed implementation
- ✅ `app/Services/PaymentService.php` - Migrated from Stripe to MiPaymentChoice
- ✅ `app/Livewire/PaymentFlow.php` - Updated to use MiPaymentChoice
- ✅ Migrations - Published and ran MiPaymentChoice Cashier migrations

## Next Steps

1. **Update Frontend**: Add MiPaymentChoice JS SDK integration
2. **Configure Merchant Key**: Get actual merchant key from MiPaymentChoice
3. **Test Payments**: Use test credentials to verify integration
4. **Implement Webhooks**: Handle MiPaymentChoice webhook notifications
5. **Add Payment Scheduling**: Implement recurring payment processing for payment plans

## Documentation

- [MiPaymentChoice Cashier README](/var/www/mipaymentchoice-cashier/README.md)
- [Database Critical Info](/var/www/itflow-laravel/DATABASE_CRITICAL_INFO.md)
- [Package Documentation](https://github.com/o-psi/mipaymentchoice-cashier)
