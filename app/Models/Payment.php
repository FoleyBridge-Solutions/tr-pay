<?php

// app/Models/Payment.php

namespace App\Models;

use App\Models\Ach\AchEntry;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

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
     * Increment the attempt count (for retries).
     */
    public function incrementAttempt(): void
    {
        $this->attempt_count++;
        $this->save();
    }
}
