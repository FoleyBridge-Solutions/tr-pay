# ACH Settlement Tracking Redesign Plan

> **Date**: 2026-02-13
> **Status**: Implementation in progress

## Problem

The `payments:check-ach-status` artisan command polls Kotapay's `GET /payment/{transactionId}` endpoint, but Kotapay purges transaction records after batching. This means 11 of 13 processing payments show "ACH Transaction not found" and remain stuck in `processing` status forever.

## Solution

Replace per-transaction polling with a **report-based** system:

1. **`AccountNameId`** — a unique identifier generated per payment at creation time. Appears in Kotapay reports as `EntryID`. Persistent across batching.
2. **Returns report (`ret`)** — lists all returned/failed ACH transactions. If a payment's `EntryID` is NOT in returns, it settled successfully.
3. **Effective-date settlement** — mark payments as `completed` on their effective date (the day the bank posts the debit), so internal records match the bank statement for reconciliation.
4. **Post-settlement return monitoring** — continue checking returns for up to 60 days after settlement. If a return comes in post-settlement, mark as `returned` (new status).
5. **Corrections report (`cor`)** — detect NOC (Notification of Change) entries and log them.

## Architecture

### Kotapay API Behavior

- Payments are batched on a schedule, after which `transactionId` is purged
- `AccountNameId` persists and appears in reports as `EntryID`
- `EntryID` present ~99% of the time; fallback matching needed for the other ~1%
- Returns report (`POST /v1/Reports/ret`) returns all returned transactions with `EntryID`, `Code`, `Reason`
- Corrections report (`POST /v1/Reports/cor`) returns NOC entries

### Settlement Logic

```
Payment Created → status: PROCESSING
                  metadata: { kotapay_account_name_id, kotapay_effective_date }
    ↓
Daily check command runs:
    ↓
    Fetch returns report for date range covering all processing payments
    ↓
    ├─ EntryID found in returns → STATUS_FAILED (return code + reason)
    ├─ EntryID NOT in returns AND effective_date <= today → STATUS_COMPLETED
    └─ EntryID NOT in returns AND effective_date > today → still PROCESSING
    ↓
    Also check completed payments (last 60 days) against returns:
    ↓
    ├─ EntryID found in returns → STATUS_RETURNED (post-settlement return)
    └─ EntryID NOT in returns → no action
```

---

## Phase 1: Store `AccountNameId` at Payment Creation

Generate a unique `AccountNameId` per payment (format: `TP-` + 8-char hex = 11 chars, max 22 allowed) and pass it through to Kotapay.

### Files Changed

| File | Method | Change |
|------|--------|--------|
| `app/Services/PaymentOrchestrator.php` | `chargeNewAch()` | Generate AccountNameId, pass in options |
| `app/Services/PaymentOrchestrator.php` | `recordPayment()` | Store `kotapay_account_name_id` + `kotapay_effective_date` in metadata |
| `app/Services/PaymentService.php` | `chargeAchWithKotapay()` | Pass `account_name_id` through to vendor package |
| `app/Services/PaymentService.php` | `processRecurringAchCharge()` | Generate AccountNameId, pass through, return in result |
| `app/Jobs/ProcessScheduledSinglePayment.php` | `handle()` ACH branch | Generate AccountNameId, pass through, store in metadata |

### AccountNameId Format

- Prefix: `TP-` (TR-Pay)
- Body: 8 random hex characters
- Example: `TP-a3f8b2c1`
- Total length: 11 characters (well within Kotapay's 22-char limit)

---

## Phase 2: Add Report Methods to Vendor ReportService

Add methods to `vendor/foleybridgesolutions/kotapay-cashier/src/Services/ReportService.php`.

### New Methods

| Method | API Call | Purpose |
|--------|----------|---------|
| `getReturnsReport($startDate)` | `POST /v1/Reports/ret` | Fetch returned ACH transactions |
| `getCorrectionsReport($startDate)` | `POST /v1/Reports/cor` | Fetch NOC corrections |
| `getProcessedBatchesSummary($startDate, $endDate)` | `POST /v1/Reports/pbr` | Batch summaries |
| `getProcessedBatchDetail($batchUniqueId)` | `POST /v1/Reports/pbr` | Batch entry detail |
| `getFileAcknowledgementDetail($fileUniqueId)` | `POST /v1/Reports/far` | File entry detail |

### Returns Report Response Fields

```json
{
    "EntryID": "TP-a3f8b2c1",
    "Code": "R13",
    "Reason": "Invalid Routing Number",
    "EffectiveDate": "2026-02-10",
    "RoutingNbr": "122199983",
    "AccountNbr": "9234123443123",
    "DebitAmt": 10.50,
    "CreditAmt": 0,
    "EntryName": "JOHN SMITH",
    "FileDate": "2026-02-10",
    "Xcelerated": true
}
```

---

## Phase 3: Add `STATUS_RETURNED` to Payment Model

### File: `app/Models/Payment.php`

- Add `STATUS_RETURNED = 'returned'` constant
- Add `markAsReturned(string $returnCode, string $reason): void`
- Add `isReturned(): bool`
- Add `scopeReturned($query)`
- Add `scopeRecentlyCompleted($query, int $days)` — for post-settlement monitoring

---

## Phase 4: Rewrite `CheckAchPaymentStatus` Command

### File: `app/Console/Commands/CheckAchPaymentStatus.php`

Complete rewrite with three jobs:

**Job 1 — Settle PROCESSING payments:**
1. Query all `processing` + `kotapay` payments
2. Fetch returns report for their date range
3. Match by `EntryID` (primary) or fallback fields
4. If in returns → `STATUS_FAILED`
5. If NOT in returns AND effective_date <= today → `STATUS_COMPLETED`
6. If NOT in returns AND effective_date > today → leave as PROCESSING

**Job 2 — Monitor COMPLETED payments for post-settlement returns:**
1. Query `completed` + `kotapay` payments from last 60 days
2. Check against returns report
3. If found → `STATUS_RETURNED` (new)

**Job 3 — Check corrections (NOC):**
1. Fetch corrections report
2. Match to payments, log details

### Matching Logic

**Primary**: `payment.metadata.kotapay_account_name_id === reportRow.EntryID`

**Fallback** (legacy payments or blank EntryID): Match on ALL of:
- `kotapay_effective_date === EffectiveDate`
- `total_amount === DebitAmt`
- Routing/account info if available

### Command Signature

```
payments:check-ach-status
    {--dry-run : Show changes without applying}
    {--id= : Check specific payment}
    {--days=60 : Post-settlement monitoring window}
```

---

## Phase 5: Handle 11 Existing Legacy Payments

Built into Phase 4's fallback matching logic:
- Legacy payments with no `kotapay_account_name_id` use fallback matching against returns
- If not in returns and effective date has long passed → mark settled
- If in returns → mark failed with return code

---

## Phase 6: Scheduling

### File: `routes/console.php`

Current: Single run at 10 PM.
Updated: Add morning run at 6 AM for early reconciliation.

---

## Execution Order

1. Phase 2 — Vendor report methods (verifiable immediately)
2. Phase 3 — Payment model changes (additive, no risk)
3. Phase 1 — AccountNameId generation (new payments get IDs)
4. Phase 4 — Command rewrite (core logic)
5. Phase 5 — Legacy resolution (automatic via Phase 4)
6. Phase 6 — Scheduling

## Risk Mitigation

| Risk | Mitigation |
|------|-----------|
| Payment marked settled then returns later | Post-settlement monitoring + `STATUS_RETURNED` |
| Returns report empty/malformed | Log raw response; don't settle until data available |
| `AccountNameId` missing from reports (~1%) | Fallback matching by amount + date + routing |
| PracticeCS write then return | Log for manual reversal; auto-reversal is future enhancement |
| API rate limiting | Single batch fetch per run, not per-payment polling |

## Vendor Bug Fix (While We're There)

**File**: `vendor/.../Services/PaymentService.php` line 108
- Change `$response['data']['transactionId']` to `$response['data']['TransactionId'] ?? $response['data']['transactionId']`
