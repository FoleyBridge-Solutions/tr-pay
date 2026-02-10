<?php

// app/Models/PaymentPlan.php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Log;

/**
 * PaymentPlan Model
 *
 * Represents a payment plan for recurring payments.
 *
 * Supports 3 plan durations:
 * - 3 months: $150 fee
 * - 6 months: $300 fee
 * - 9 months: $450 fee
 *
 * All plans require a 30% down payment of the total amount.
 *
 * @property int $id
 * @property int $customer_id
 * @property int $client_key
 * @property string $plan_id
 * @property float $invoice_amount
 * @property float $plan_fee
 * @property float $down_payment
 * @property float $total_amount
 * @property float $monthly_payment
 * @property int $duration_months
 * @property string $payment_method_token
 * @property string $payment_method_type
 * @property string|null $payment_method_last_four
 * @property string $status
 * @property int $payments_completed
 * @property int $payments_failed
 * @property float $amount_paid
 * @property float $amount_remaining
 * @property Carbon $start_date
 * @property Carbon|null $next_payment_date
 * @property Carbon|null $completed_at
 * @property Carbon|null $cancelled_at
 * @property string|null $cancellation_reason
 * @property array|null $invoice_references
 * @property array|null $metadata
 */
class PaymentPlan extends Model
{
    // Status constants
    public const STATUS_ACTIVE = 'active';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUS_PAST_DUE = 'past_due';

    public const STATUS_FAILED = 'failed';

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'customer_id',
        'client_key',
        'plan_id',
        'invoice_amount',
        'plan_fee',
        'down_payment',
        'total_amount',
        'monthly_payment',
        'duration_months',
        'payment_method_token',
        'payment_method_type',
        'payment_method_last_four',
        'status',
        'payments_completed',
        'payments_failed',
        'amount_paid',
        'amount_remaining',
        'start_date',
        'next_payment_date',
        'next_retry_date',
        'last_attempt_at',
        'original_due_date',
        'completed_at',
        'cancelled_at',
        'cancellation_reason',
        'invoice_references',
        'metadata',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'invoice_amount' => 'decimal:2',
            'plan_fee' => 'decimal:2',
            'down_payment' => 'decimal:2',
            'total_amount' => 'decimal:2',
            'monthly_payment' => 'decimal:2',
            'duration_months' => 'integer',
            'payments_completed' => 'integer',
            'payments_failed' => 'integer',
            'amount_paid' => 'decimal:2',
            'amount_remaining' => 'decimal:2',
            'start_date' => 'date',
            'next_payment_date' => 'date',
            'next_retry_date' => 'date',
            'last_attempt_at' => 'datetime',
            'original_due_date' => 'date',
            'completed_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'invoice_references' => 'array',
            'metadata' => 'array',
        ];
    }

    /**
     * Get the customer for this payment plan.
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * Get the payments for this plan.
     */
    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    /**
     * Get completed payments for this plan.
     */
    public function completedPayments(): HasMany
    {
        return $this->payments()->where('status', 'completed');
    }

    /**
     * Get scheduled (pending) payments for this plan.
     */
    public function scheduledPayments(): HasMany
    {
        return $this->payments()->where('status', 'pending')->whereNotNull('scheduled_date');
    }

    /**
     * Scope: Active plans.
     */
    public function scopeActive($query)
    {
        return $query->where('status', self::STATUS_ACTIVE);
    }

    /**
     * Scope: Plans due for payment today or earlier.
     */
    public function scopeDueForPayment($query, ?Carbon $date = null)
    {
        $date = $date ?? now();

        return $query->where('status', self::STATUS_ACTIVE)
            ->whereNotNull('next_payment_date')
            ->where('next_payment_date', '<=', $date->toDateString());
    }

    /**
     * Scope: Plans that are past due (missed payments).
     */
    public function scopePastDue($query)
    {
        return $query->whereIn('status', [self::STATUS_ACTIVE, self::STATUS_PAST_DUE])
            ->whereNotNull('next_payment_date')
            ->where('next_payment_date', '<', now()->toDateString());
    }

    /**
     * Check if the plan is active.
     */
    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    /**
     * Check if the plan is completed.
     */
    public function isCompleted(): bool
    {
        return $this->status === self::STATUS_COMPLETED;
    }

    /**
     * Check if the plan has a payment due.
     */
    public function hasDuePayment(): bool
    {
        return $this->isActive()
            && $this->next_payment_date
            && $this->next_payment_date->lte(now());
    }

    /**
     * Get the number of remaining payments.
     */
    public function getRemainingPaymentsAttribute(): int
    {
        return $this->duration_months - $this->payments_completed;
    }

    /**
     * Calculate and set the next payment date.
     */
    public function calculateNextPaymentDate(): ?Carbon
    {
        if ($this->payments_completed >= $this->duration_months) {
            return null;
        }

        // Next payment is one month from the start date + number of completed payments
        return $this->start_date->copy()->addMonths($this->payments_completed + 1);
    }

    /**
     * Record a successful payment.
     *
     * @param  float  $amount  Payment amount
     * @param  string  $transactionId  Gateway transaction ID
     * @param  string|null  $vendorTransactionId  Vendor-specific transaction ID (e.g., Kotapay) for status tracking
     */
    public function recordPayment(float $amount, string $transactionId, ?string $vendorTransactionId = null): Payment
    {
        $isAch = $this->payment_method_type === 'ach';

        // Create the payment record
        $payment = $this->payments()->create([
            'customer_id' => $this->customer_id,
            'client_key' => $this->client_key,
            'transaction_id' => $transactionId,
            'amount' => $amount,
            'fee' => 0,
            'total_amount' => $amount,
            'payment_method' => $this->payment_method_type,
            'payment_method_last_four' => $this->payment_method_last_four,
            'status' => $isAch ? Payment::STATUS_PROCESSING : Payment::STATUS_COMPLETED,
            'scheduled_date' => $this->next_payment_date,
            'payment_number' => $this->payments_completed + 1,
            'is_automated' => true,
            'processed_at' => $isAch ? null : now(),
            'payment_vendor' => $isAch ? 'kotapay' : null,
            'vendor_transaction_id' => $isAch ? ($vendorTransactionId ?? $transactionId) : null,
        ]);

        // Update plan totals
        $this->payments_completed++;
        $this->amount_paid += $amount;
        $this->amount_remaining -= $amount;

        // Clear retry fields (payment succeeded)
        $this->next_retry_date = null;
        $this->original_due_date = null;
        $this->last_attempt_at = null;
        $this->payments_failed = 0; // Reset failure count on success
        $this->status = self::STATUS_ACTIVE; // Back to active if it was past_due

        // Calculate next payment date or mark as completed
        if ($this->payments_completed >= $this->duration_months) {
            $this->status = self::STATUS_COMPLETED;
            $this->completed_at = now();
            $this->next_payment_date = null;
        } else {
            $this->next_payment_date = $this->calculateNextPaymentDate();
        }

        $this->save();

        return $payment;
    }

    /**
     * Record a failed payment attempt with incremental retry backoff.
     */
    public function recordFailedPayment(string $reason): Payment
    {
        // Set original due date if not already set (first failure)
        if (! $this->original_due_date) {
            $this->original_due_date = $this->next_payment_date ?: $this->next_retry_date;
        }

        // Create a failed payment record
        $payment = $this->payments()->create([
            'customer_id' => $this->customer_id,
            'client_key' => $this->client_key,
            'transaction_id' => 'failed_'.bin2hex(random_bytes(16)),
            'amount' => $this->monthly_payment,
            'fee' => 0,
            'total_amount' => $this->monthly_payment,
            'payment_method' => $this->payment_method_type,
            'payment_method_last_four' => $this->payment_method_last_four,
            'status' => 'failed',
            'failure_reason' => $reason,
            'scheduled_date' => $this->original_due_date,
            'payment_number' => $this->payments_completed + 1,
            'is_automated' => true,
            'failed_at' => now(),
        ]);

        // Update attempt tracking
        $this->payments_failed++;
        $this->last_attempt_at = now();

        // Calculate next retry date
        $nextRetry = $this->calculateNextRetryDate();

        if ($nextRetry === null) {
            // Can't retry anymore - either next payment is due or 60 days passed
            if ($this->shouldAdvanceToNextPayment()) {
                $this->advanceToNextPayment();
            } else {
                // No more payments left, mark as failed
                $this->status = self::STATUS_FAILED;
                $this->next_retry_date = null;
            }
        } else {
            // Schedule next retry
            $this->status = self::STATUS_PAST_DUE;
            $this->next_retry_date = $nextRetry;
        }

        $this->save();

        return $payment;
    }

    /**
     * Calculate the next retry date using incremental backoff.
     * Returns null if we should stop retrying.
     */
    public function calculateNextRetryDate(): ?Carbon
    {
        if (! $this->original_due_date) {
            return null;
        }

        $originalDue = Carbon::parse($this->original_due_date);

        // Calculate days to add: sum of 1 + 2 + 3 + ... + payments_failed
        // Formula: n * (n + 1) / 2
        $daysToAdd = ($this->payments_failed * ($this->payments_failed + 1)) / 2;
        $nextRetryDate = $originalDue->copy()->addDays($daysToAdd);

        // Check if next retry would be past the next scheduled payment
        if ($this->next_payment_date && $nextRetryDate->gte($this->next_payment_date)) {
            return null; // Time to move on to next payment
        }

        // Check if 60+ days have passed since original due date
        if ($nextRetryDate->diffInDays($originalDue) >= 60) {
            return null; // Give up after 60 days
        }

        return $nextRetryDate;
    }

    /**
     * Check if we should advance to the next scheduled payment.
     */
    protected function shouldAdvanceToNextPayment(): bool
    {
        return $this->next_payment_date !== null
            && $this->payments_completed < $this->duration_months;
    }

    /**
     * Advance to the next scheduled payment, abandoning the current retry.
     */
    public function advanceToNextPayment(): void
    {
        // Clear retry fields
        $this->next_retry_date = null;
        $this->original_due_date = null;
        $this->last_attempt_at = null;

        // Reset status back to active (we're moving on)
        $this->status = self::STATUS_ACTIVE;

        // The next_payment_date is already set to the next month
        // The missed payment amount stays in amount_remaining

        Log::info('Advanced to next payment, abandoning retry', [
            'plan_id' => $this->plan_id,
            'payments_completed' => $this->payments_completed,
            'payments_failed' => $this->payments_failed,
            'next_payment_date' => $this->next_payment_date->format('Y-m-d'),
        ]);
    }

    /**
     * Cancel the payment plan.
     */
    public function cancel(?string $reason = null): void
    {
        $this->status = self::STATUS_CANCELLED;
        $this->cancelled_at = now();
        $this->cancellation_reason = $reason;
        $this->next_payment_date = null;
        $this->save();
    }

    /**
     * Generate a unique plan ID.
     */
    public static function generatePlanId(): string
    {
        return 'plan_'.bin2hex(random_bytes(12));
    }

    /**
     * Create scheduled payment records for the plan.
     */
    public function createScheduledPayments(): void
    {
        for ($i = 1; $i <= $this->duration_months; $i++) {
            $scheduledDate = $this->start_date->copy()->addMonths($i);

            // Adjust final payment amount for rounding
            $amount = $this->monthly_payment;
            if ($i === $this->duration_months) {
                $amount = $this->amount_remaining - (($this->duration_months - 1) * $this->monthly_payment);
                if ($amount < 0) {
                    $amount = $this->monthly_payment;
                }
            }

            $this->payments()->create([
                'customer_id' => $this->customer_id,
                'client_key' => $this->client_key,
                'transaction_id' => 'scheduled_'.$this->plan_id.'_'.$i,
                'amount' => $amount,
                'fee' => 0,
                'total_amount' => $amount,
                'payment_method' => $this->payment_method_type,
                'payment_method_last_four' => $this->payment_method_last_four,
                'status' => 'pending',
                'scheduled_date' => $scheduledDate,
                'payment_number' => $i,
                'is_automated' => true,
                'scheduled_at' => now(),
            ]);
        }
    }
}
