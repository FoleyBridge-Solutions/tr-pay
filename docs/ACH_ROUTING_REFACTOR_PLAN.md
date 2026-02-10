# ACH Routing Refactor Plan

## Overview

This document outlines the changes made to ensure:
- **Credit/Debit Card payments** -> MiPaymentChoice (only)
- **ACH/Bank Account payments** -> Kotapay (only)

## Date: 2026-01-28

---

## Phase 1: Clean Up MiPaymentChoice Package

### Files Modified in `/var/www/mipaymentchoice-cashier/`

#### 1. `src/Traits/CardBillable.php`
**Removed Methods:**
- `createQuickPaymentsTokenFromCheck()` - Was creating ACH tokens via MPC
- `chargeCheckWithQuickPayments()` - Was charging ACH via MPC ECP endpoint
- `tokenizeCheck()` - Was creating reusable check tokens via MPC
- `updateCheckToken()` - Was updating check tokens in MPC
- `deleteCheckToken()` - Was deleting check tokens from MPC

#### 2. `src/Services/QuickPaymentsService.php`
**Removed Methods:**
- `createQpTokenFromCheck()` - Was creating QP tokens from check data
- `chargeCheck()` - Was charging via ECP endpoint
- `formatCheckAddress()` - Was formatting check addresses

#### 3. `src/Services/TokenService.php`
**Removed Methods:**
- `createCheckToken()` - Was creating check tokens
- `getCheckToken()` - Was retrieving check tokens
- `getCheckTokens()` - Was listing check tokens
- `updateCheckToken()` - Was updating check tokens
- `replaceCheckToken()` - Was replacing check tokens
- `deleteCheckTokens()` - Was deleting check tokens

---

## Phase 2: Update tr-pay Application

### Files Modified in `/var/www/tr-pay/`

#### 1. `app/Services/PaymentService.php`
- **Removed:** `chargeCheckWithQuickPayments()` method
- **Updated:** `processRecurringCharge()` to use Kotapay for ACH payments
- **Added:** Integration with `KotapayPaymentService` for ACH

#### 2. `app/Livewire/PaymentFlow.php`
- **Updated:** One-time ACH payments to use `$customer->chargeAch()` (Kotapay)
- **Updated:** Payment plan ACH to use Kotapay for down payments
- **Note:** ACH payment methods stored locally (Kotapay doesn't tokenize)

#### 3. `app/Livewire/Admin/Payments/Create.php`
- **Updated:** ACH payment path to use Kotapay instead of QuickPayments

#### 4. `app/Services/CustomerPaymentMethodService.php`
- **Updated:** `createFromCheckDetails()` to store ACH details locally only
- **Note:** Kotapay doesn't support token storage, so ACH details are stored encrypted locally

---

## Important Notes

### Kotapay Limitations
Kotapay does **NOT** support token storage for ACH payments. This means:
1. Bank account details must be stored securely in the local database
2. Each ACH charge requires sending the full bank details to Kotapay
3. The `CustomerPaymentMethod` model stores encrypted bank details for ACH

### Payment Vendor Tracking
The `payments` table now has:
- `payment_vendor` - 'mipaymentchoice' or 'kotapay'
- `vendor_transaction_id` - Transaction ID from the respective vendor

### Environment Variables Required

For Kotapay:
```
KOTAPAY_API_ENABLED=true
KOTAPAY_API_BASE_URL=https://api.kotapay.com
KOTAPAY_API_CLIENT_ID=your_client_id
KOTAPAY_API_CLIENT_SECRET=your_client_secret
KOTAPAY_API_USERNAME=your_username
KOTAPAY_API_PASSWORD=your_password
KOTAPAY_API_COMPANY_ID=your_company_id
```

---

## Verification Checklist

- [ ] No ACH methods remain in MiPaymentChoice package
- [ ] All ACH payments route to Kotapay
- [ ] All card payments route to MiPaymentChoice
- [ ] Payment vendor is tracked in payments table
- [ ] Recurring ACH payments work with Kotapay
- [ ] One-time ACH payments work with Kotapay
- [ ] Admin ACH payments work with Kotapay
