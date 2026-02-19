<?php

// app/Models/Payment.php

namespace App\Models;

use App\Mail\PaymentReceipt;
use App\Models\Ach\AchEntry;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Payment Model
 *
 * Represents a payment transaction processed through this application.
 * Can be a one-time payment or part of a payment plan.
 *
 * @property int $id
 * @property int $customer_id
 * @property string $client_id
 * @property int|null $payment_plan_id
 * @property string $transaction_id
 * @property float $amount
 * @property float $fee
 * @property float $total_amount
 * @property string $payment_method
 * @property string|null $payment_method_last_four
 * @property string $status
 * @property string|null $failure_reason
 * @property int $attempt_count
 * @property \Carbon\Carbon|null $scheduled_date
 * @property int|null $payment_number
 * @property bool $is_automated
 * @property string|null $description
 * @property array|null $metadata
 * @property string|null $payment_vendor
 * @property string|null $vendor_transaction_id
 * @property \Carbon\Carbon|null $scheduled_at
 * @property \Carbon\Carbon|null $processed_at
 * @property \Carbon\Carbon|null $failed_at
 */
class Payment extends Model
{
    // Status constants
    public const STATUS_PENDING = 'pending';

    public const STATUS_PROCESSING = 'processing';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    public const STATUS_REFUNDED = 'refunded';

    public const STATUS_RETURNED = 'returned';

    public const STATUS_VOIDED = 'voided';

    public const STATUS_SKIPPED = 'skipped';

    // Source constants (match metadata['source'] values set across all payment flows)
    public const SOURCE_PUBLIC = 'tr-pay';

    public const SOURCE_ADMIN = 'tr-pay-admin';

    public const SOURCE_EMAIL = 'tr-pay-email';

    public const SOURCE_ADMIN_SCHEDULED = 'admin-scheduled';

    public const SOURCE_SCHEDULED = 'tr-pay-scheduled';

    public const SOURCE_RECURRING = 'tr-pay-recurring';

    /**
     * Human-readable labels for each source value.
     *
     * @var array<string, string>
     */
    public const SOURCE_LABELS = [
        self::SOURCE_PUBLIC => 'Public Portal',
        self::SOURCE_ADMIN => 'Admin',
        self::SOURCE_EMAIL => 'Email Request',
        self::SOURCE_ADMIN_SCHEDULED => 'Scheduled',
        self::SOURCE_SCHEDULED => 'Scheduled',
        self::SOURCE_RECURRING => 'Recurring',
    ];

    /**
     * Badge color for each source value.
     *
     * @var array<string, string>
     */
    public const SOURCE_BADGE_COLORS = [
        self::SOURCE_PUBLIC => 'blue',
        self::SOURCE_ADMIN => 'amber',
        self::SOURCE_EMAIL => 'purple',
        self::SOURCE_ADMIN_SCHEDULED => 'zinc',
        self::SOURCE_SCHEDULED => 'zinc',
        self::SOURCE_RECURRING => 'teal',
    ];

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'customer_id',
        'client_id',
        'payment_plan_id',
        'recurring_payment_id',
        'transaction_id',
        'amount',
        'fee',
        'total_amount',
        'payment_method',
        'payment_method_last_four',
        'status',
        'failure_reason',
        'attempt_count',
        'scheduled_date',
        'payment_number',
        'is_automated',
        'description',
        'metadata',
        'scheduled_at',
        'processed_at',
        'failed_at',
        'payment_vendor',
        'vendor_transaction_id',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'fee' => 'decimal:2',
            'total_amount' => 'decimal:2',
            'attempt_count' => 'integer',
            'payment_number' => 'integer',
            'is_automated' => 'boolean',
            'metadata' => 'array',
            'scheduled_date' => 'date',
            'scheduled_at' => 'datetime',
            'processed_at' => 'datetime',
            'failed_at' => 'datetime',
        ];
    }

    /**
     * Get the customer that made this payment.
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * Get the payment plan this payment belongs to.
     */
    public function paymentPlan(): BelongsTo
    {
        return $this->belongsTo(PaymentPlan::class);
    }

    /**
     * Get the recurring payment this payment belongs to.
     */
    public function recurringPayment(): BelongsTo
    {
        return $this->belongsTo(RecurringPayment::class);
    }

    /**
     * Get the ACH entry for this payment (if processed via Kotapay).
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasOne<\App\Models\Ach\AchEntry, self>
     */
    public function achEntry(): HasOne
    {
        return $this->hasOne(AchEntry::class);
    }

    /**
     * Scope: Pending payments.
     */
    public function scopePending($query)
    {
        return $query->where('status', self::STATUS_PENDING);
    }

    /**
     * Scope: Completed payments.
     */
    public function scopeCompleted($query)
    {
        return $query->where('status', self::STATUS_COMPLETED);
    }

    /**
     * Scope: Processing payments (ACH awaiting settlement).
     */
    public function scopeProcessing($query)
    {
        return $query->where('status', self::STATUS_PROCESSING);
    }

    /**
     * Scope: Failed payments.
     */
    public function scopeFailed($query)
    {
        return $query->where('status', self::STATUS_FAILED);
    }

    /**
     * Scope: Returned payments (post-settlement ACH returns).
     */
    public function scopeReturned($query)
    {
        return $query->where('status', self::STATUS_RETURNED);
    }

    /**
     * Scope: Refunded payments.
     */
    public function scopeRefunded($query)
    {
        return $query->where('status', self::STATUS_REFUNDED);
    }

    /**
     * Scope: Voided payments.
     */
    public function scopeVoided($query)
    {
        return $query->where('status', self::STATUS_VOIDED);
    }

    /**
     * Scope: Recently completed Kotapay ACH payments (for post-settlement return monitoring).
     *
     * @param  int  $days  Number of days back to include
     */
    public function scopeRecentlyCompletedAch($query, int $days = 60)
    {
        return $query->where('status', self::STATUS_COMPLETED)
            ->where('payment_vendor', 'kotapay')
            ->where('processed_at', '>=', now()->subDays($days));
    }

    /**
     * Scope: Scheduled payments due on or before a date.
     */
    public function scopeDueOnOrBefore($query, $date)
    {
        return $query->where('status', self::STATUS_PENDING)
            ->whereNotNull('scheduled_date')
            ->where('scheduled_date', '<=', $date);
    }

    /**
     * Scope: Automated (payment plan) payments.
     */
    public function scopeAutomated($query)
    {
        return $query->where('is_automated', true);
    }

    /**
     * Check if this is a payment plan payment.
     */
    public function isPaymentPlanPayment(): bool
    {
        return $this->payment_plan_id !== null;
    }

    /**
     * Check if this payment is pending.
     */
    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    /**
     * Check if this payment is completed.
     */
    public function isCompleted(): bool
    {
        return $this->status === self::STATUS_COMPLETED;
    }

    /**
     * Check if this payment is processing (ACH awaiting settlement).
     */
    public function isProcessing(): bool
    {
        return $this->status === self::STATUS_PROCESSING;
    }

    /**
     * Check if this payment failed.
     */
    public function isFailed(): bool
    {
        return $this->status === self::STATUS_FAILED;
    }

    /**
     * Check if this payment was returned after settlement.
     */
    public function isReturned(): bool
    {
        return $this->status === self::STATUS_RETURNED;
    }

    /**
     * Mark the payment as processing (ACH submitted, awaiting settlement).
     *
     * @param  string  $transactionId  Internal transaction ID
     * @param  string|null  $vendorTransactionId  Kotapay transaction ID for status lookups
     */
    public function markAsProcessing(string $transactionId, ?string $vendorTransactionId = null): void
    {
        $this->transaction_id = $transactionId;
        $this->status = self::STATUS_PROCESSING;
        $this->payment_vendor = 'kotapay';
        if ($vendorTransactionId) {
            $this->vendor_transaction_id = $vendorTransactionId;
        }
        $this->save();
    }

    /**
     * Mark the payment as completed.
     */
    public function markAsCompleted(string $transactionId): void
    {
        $this->transaction_id = $transactionId;
        $this->status = self::STATUS_COMPLETED;
        $this->processed_at = now();
        $this->save();
    }

    /**
     * Mark the payment as failed.
     */
    public function markAsFailed(string $reason): void
    {
        $this->status = self::STATUS_FAILED;
        $this->failure_reason = $reason;
        $this->failed_at = now();
        $this->attempt_count++;
        $this->save();
    }

    /**
     * Mark a previously completed payment as returned (post-settlement ACH return).
     *
     * @param  string  $returnCode  ACH return code (e.g., R01, R02, R13)
     * @param  string  $reason  Human-readable return reason
     */
    public function markAsReturned(string $returnCode, string $reason): void
    {
        $this->status = self::STATUS_RETURNED;
        $this->failure_reason = "ACH Return {$returnCode}: {$reason}";
        $this->failed_at = now();

        // Store return details in metadata for audit trail
        $metadata = $this->metadata ?? [];
        $metadata['ach_return_code'] = $returnCode;
        $metadata['ach_return_reason'] = $reason;
        $metadata['ach_returned_at'] = now()->toIso8601String();
        $metadata['ach_was_settled'] = true;
        $this->metadata = $metadata;

        $this->save();
    }

    /**
     * Check if this payment was refunded.
     */
    public function isRefunded(): bool
    {
        return $this->status === self::STATUS_REFUNDED;
    }

    /**
     * Check if this payment was voided.
     */
    public function isVoided(): bool
    {
        return $this->status === self::STATUS_VOIDED;
    }

    /**
     * Mark a completed card payment as refunded.
     *
     * @param  string  $reason  Human-readable refund reason
     * @param  string|null  $refundTransactionId  Gateway refund transaction ID
     */
    public function markAsRefunded(string $reason, ?string $refundTransactionId = null): void
    {
        $this->status = self::STATUS_REFUNDED;
        $this->failure_reason = $reason;

        $metadata = $this->metadata ?? [];
        $metadata['refunded_at'] = now()->toIso8601String();
        $metadata['refund_reason'] = $reason;
        if ($refundTransactionId) {
            $metadata['refund_transaction_id'] = $refundTransactionId;
        }
        $metadata['original_status'] = $this->getOriginal('status');
        $this->metadata = $metadata;

        $this->save();
    }

    /**
     * Mark a processing ACH payment as voided.
     *
     * @param  string  $reason  Human-readable void reason
     */
    public function markAsVoided(string $reason): void
    {
        $this->status = self::STATUS_VOIDED;
        $this->failure_reason = $reason;

        $metadata = $this->metadata ?? [];
        $metadata['voided_at'] = now()->toIso8601String();
        $metadata['void_reason'] = $reason;
        $metadata['original_status'] = $this->getOriginal('status');
        $this->metadata = $metadata;

        $this->save();
    }

    /**
     * Get the resolved source key, disambiguating plan installments from public portal.
     *
     * Payments with source 'tr-pay' that belong to a payment plan are reclassified
     * as 'plan-installment' for display purposes.
     */
    public function getResolvedSource(): string
    {
        $source = $this->metadata['source'] ?? null;

        // Disambiguate: 'tr-pay' is used for both public portal and plan installments
        if ($source === self::SOURCE_PUBLIC && $this->payment_plan_id !== null) {
            return 'plan-installment';
        }

        return $source ?? 'unknown';
    }

    /**
     * Get the human-readable source label for display.
     */
    public function getSourceLabelAttribute(): string
    {
        $resolved = $this->getResolvedSource();

        if ($resolved === 'plan-installment') {
            return 'Plan Installment';
        }

        return self::SOURCE_LABELS[$resolved] ?? 'Unknown';
    }

    /**
     * Get the badge color for this payment's source.
     */
    public function getSourceBadgeColorAttribute(): string
    {
        $resolved = $this->getResolvedSource();

        if ($resolved === 'plan-installment') {
            return 'indigo';
        }

        return self::SOURCE_BADGE_COLORS[$resolved] ?? 'zinc';
    }

    /**
     * Send a payment receipt email to the customer's email on file.
     *
     * Uses the Customer.email field (synced from PracticeCS). Skips silently
     * if no email is on file. Catches exceptions so email failures never
     * break the calling payment flow.
     *
     * @return bool Whether the email was sent successfully
     */
    public function sendReceipt(): bool
    {
        $customer = $this->customer ?? $this->customer()->first();
        $email = $customer?->email;

        if (! $email) {
            Log::info('Payment receipt not sent — no customer email on file', [
                'payment_id' => $this->id,
                'transaction_id' => $this->transaction_id,
            ]);

            return false;
        }

        try {
            $metadata = $this->metadata ?? [];

            $paymentData = [
                'amount' => (float) $this->amount,
                'fee' => (float) $this->fee,
                'paymentMethod' => $this->payment_method ?? 'unknown',
                'invoices' => collect($metadata['invoice_keys'] ?? [])->map(fn ($inv) => [
                    'invoice_number' => $inv,
                    'description' => null,
                    'amount' => null,
                ])->values()->toArray(),
            ];

            $clientInfo = [
                'client_name' => $metadata['client_name'] ?? $customer->name ?? 'Client',
                'client_id' => $metadata['client_id'] ?? $this->client_id ?? '',
            ];

            Mail::to($email)->send(new PaymentReceipt(
                $paymentData,
                $clientInfo,
                $this->transaction_id
            ));

            Log::info('Payment receipt email sent', [
                'payment_id' => $this->id,
                'transaction_id' => $this->transaction_id,
                'email' => $email,
            ]);

            return true;
        } catch (\Exception $e) {
            Log::error('Failed to send payment receipt email', [
                'payment_id' => $this->id,
                'transaction_id' => $this->transaction_id,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Increment the attempt count (for retries).
     */
    public function incrementAttempt(): void
    {
        $this->attempt_count++;
        $this->save();
    }
}
