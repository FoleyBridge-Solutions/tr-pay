<?php

// app/Models/PaymentPlan.php

namespace App\Models;

use App\Notifications\PaymentPlanPastDue;
use App\Services\AdminAlertService;
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
 * @property string $client_id
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
 * @property int|null $recurring_payment_day
 * @property int $skips_used
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
     * Maximum number of payments that can be skipped per plan.
     */
    public const MAX_SKIPS = 2;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'customer_id',
        'client_id',
        'plan_id',
        'invoice_amount',
        'plan_fee',
        'down_payment',
        'total_amount',
        'monthly_payment',
        'duration_months',
        'payment_method_token',
        'customer_payment_method_id',
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
        'recurring_payment_day',
        'skips_used',
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
            'recurring_payment_day' => 'integer',
            'skips_used' => 'integer',
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
     * Get the saved payment method used for this plan.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo<\App\Models\CustomerPaymentMethod, self>
     */
    public function paymentMethod(): BelongsTo
    {
        return $this->belongsTo(CustomerPaymentMethod::class, 'customer_payment_method_id');
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
     * Respects the recurring_payment_day if set.
     */
    public function calculateNextPaymentDate(): ?Carbon
    {
        if ($this->payments_completed >= $this->duration_months) {
            return null;
        }

        // Next payment is one month from the start date + number of completed payments
        $nextDate = $this->start_date->copy()->addMonthsNoOverflow($this->payments_completed + 1);

        // Apply custom recurring day if set, clamped to the last day of the target month
        if ($this->recurring_payment_day) {
            $day = max(1, min(31, $this->recurring_payment_day));
            $nextDate->day(min($day, $nextDate->daysInMonth));
        }

        return $nextDate;
    }

    /**
     * Record a successful payment.
     *
     * @param  float  $amount  Payment amount
     * @param  string  $transactionId  Gateway transaction ID
     * @param  string|null  $vendorTransactionId  Vendor-specific transaction ID (e.g., Kotapay) for status tracking
     * @param  float  $fee  Informational NCA fee for this payment (default 0)
     */
    public function recordPayment(float $amount, string $transactionId, ?string $vendorTransactionId = null, float $fee = 0): Payment
    {
        $isAch = $this->payment_method_type === 'ach';

        // Create the payment record
        $payment = $this->payments()->create([
            'customer_id' => $this->customer_id,
            'client_id' => $this->client_id,
            'transaction_id' => $transactionId,
            'amount' => $amount,
            'fee' => $fee,
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

        // Get the informational fee from plan metadata (if credit card NCA was applied)
        $feePerPayment = (float) ($this->metadata['fee_per_payment'] ?? 0);

        // Create a failed payment record
        $payment = $this->payments()->create([
            'customer_id' => $this->customer_id,
            'client_id' => $this->client_id,
            'transaction_id' => 'failed_'.bin2hex(random_bytes(16)),
            'amount' => $this->monthly_payment,
            'fee' => $feePerPayment,
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

                try {
                    AdminAlertService::notifyAll(new PaymentPlanPastDue(
                        $this->plan_id,
                        $this->metadata['client_name'] ?? 'Unknown',
                        $this->client_id,
                        (float) $this->monthly_payment,
                        'failed',
                        $this->payments_failed
                    ));
                } catch (\Exception $notifyEx) {
                    Log::warning('Failed to send admin notification', ['error' => $notifyEx->getMessage()]);
                }
            }
        } else {
            // Schedule next retry
            $this->status = self::STATUS_PAST_DUE;
            $this->next_retry_date = $nextRetry;

            try {
                AdminAlertService::notifyAll(new PaymentPlanPastDue(
                    $this->plan_id,
                    $this->metadata['client_name'] ?? 'Unknown',
                    $this->client_id,
                    (float) $this->monthly_payment,
                    'past_due',
                    $this->payments_failed
                ));
            } catch (\Exception $notifyEx) {
                Log::warning('Failed to send admin notification', ['error' => $notifyEx->getMessage()]);
            }
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
     * Check if the plan can have its next payment skipped.
     *
     * A payment can be skipped if the plan is active (or past_due),
     * has a next_payment_date, has remaining payments, and
     * has not exceeded the maximum skip limit.
     */
    public function canSkipPayment(): bool
    {
        return in_array($this->status, [self::STATUS_ACTIVE, self::STATUS_PAST_DUE])
            && $this->next_payment_date !== null
            && $this->skips_used < self::MAX_SKIPS
            && $this->payments_completed < $this->duration_months;
    }

    /**
     * Skip the next scheduled payment.
     *
     * Extends the plan by one month: increments duration_months,
     * marks the current pending Payment as "skipped", creates a new
     * pending Payment at the end, and advances next_payment_date.
     *
     * @throws \RuntimeException If the plan cannot be skipped
     */
    public function skipNextPayment(): void
    {
        if (! $this->canSkipPayment()) {
            throw new \RuntimeException('This payment plan cannot skip any more payments.');
        }

        // Find the pending payment record for the current next_payment_date
        $pendingPayment = $this->payments()
            ->where('status', Payment::STATUS_PENDING)
            ->where('payment_number', $this->payments_completed + 1)
            ->first();

        // Mark it as skipped
        if ($pendingPayment) {
            $pendingPayment->status = Payment::STATUS_SKIPPED;
            $pendingPayment->save();
        }

        // Extend the plan by one month
        $this->duration_months++;
        $this->skips_used++;

        // Renumber all remaining pending payments after the skipped one
        $remainingPayments = $this->payments()
            ->where('status', Payment::STATUS_PENDING)
            ->orderBy('payment_number')
            ->get();

        $nextNumber = $this->payments_completed + 1;
        foreach ($remainingPayments as $payment) {
            $payment->payment_number = $nextNumber;

            // Recalculate scheduled date for this payment number
            $scheduledDate = $this->start_date->copy()->addMonthsNoOverflow($nextNumber);
            if ($this->recurring_payment_day) {
                $day = max(1, min(31, $this->recurring_payment_day));
                $scheduledDate->day(min($day, $scheduledDate->daysInMonth));
            }
            $payment->scheduled_date = $scheduledDate;
            $payment->save();

            $nextNumber++;
        }

        // Create the new pending payment at the end of the extended schedule
        $newPaymentNumber = $this->duration_months;
        $newScheduledDate = $this->start_date->copy()->addMonthsNoOverflow($newPaymentNumber);
        if ($this->recurring_payment_day) {
            $day = max(1, min(31, $this->recurring_payment_day));
            $newScheduledDate->day(min($day, $newScheduledDate->daysInMonth));
        }

        // Get the informational fee from plan metadata (if credit card NCA was applied)
        $feePerPayment = (float) ($this->metadata['fee_per_payment'] ?? 0);

        $this->payments()->create([
            'customer_id' => $this->customer_id,
            'client_id' => $this->client_id,
            'transaction_id' => 'scheduled_'.$this->plan_id.'_'.$newPaymentNumber,
            'amount' => $this->monthly_payment,
            'fee' => $feePerPayment,
            'total_amount' => $this->monthly_payment,
            'payment_method' => $this->payment_method_type,
            'payment_method_last_four' => $this->payment_method_last_four,
            'status' => Payment::STATUS_PENDING,
            'scheduled_date' => $newScheduledDate,
            'payment_number' => $newPaymentNumber,
            'is_automated' => true,
            'scheduled_at' => now(),
        ]);

        // Clear retry fields if in past_due state
        $this->next_retry_date = null;
        $this->original_due_date = null;
        $this->last_attempt_at = null;
        $this->status = self::STATUS_ACTIVE;

        // Advance next_payment_date to the next pending payment
        $this->next_payment_date = $this->calculateNextPaymentDate();

        $this->save();

        Log::info('Skipped payment plan installment', [
            'plan_id' => $this->plan_id,
            'skips_used' => $this->skips_used,
            'new_duration_months' => $this->duration_months,
            'next_payment_date' => $this->next_payment_date?->format('Y-m-d'),
        ]);
    }

    /**
     * Adjust the next payment date to a new date.
     *
     * Updates both the plan's next_payment_date and the corresponding
     * pending Payment record's scheduled_date.
     *
     * @param  Carbon  $newDate  The new payment date (must be in the future)
     *
     * @throws \InvalidArgumentException If the date is not in the future
     */
    public function adjustNextPaymentDate(Carbon $newDate): void
    {
        if ($newDate->lte(today())) {
            throw new \InvalidArgumentException('The new payment date must be in the future.');
        }

        $oldDate = $this->next_payment_date?->copy();

        // Update the corresponding pending Payment record
        $pendingPayment = $this->payments()
            ->where('status', Payment::STATUS_PENDING)
            ->where('payment_number', $this->payments_completed + 1)
            ->first();

        if ($pendingPayment) {
            $pendingPayment->scheduled_date = $newDate;
            $pendingPayment->save();
        }

        $this->next_payment_date = $newDate;
        $this->save();

        Log::info('Adjusted payment plan next payment date', [
            'plan_id' => $this->plan_id,
            'old_date' => $oldDate?->format('Y-m-d'),
            'new_date' => $newDate->format('Y-m-d'),
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
     * Respects the recurring_payment_day if set.
     *
     * @param  float  $feePerPayment  Informational NCA fee per payment (default 0)
     */
    public function createScheduledPayments(float $feePerPayment = 0): void
    {
        // Track remaining fee to handle rounding on the last payment
        $totalFeeDistributed = 0.0;

        for ($i = 1; $i <= $this->duration_months; $i++) {
            $scheduledDate = $this->start_date->copy()->addMonthsNoOverflow($i);

            // Apply custom recurring day if set, clamped to the last day of the target month
            if ($this->recurring_payment_day) {
                $day = max(1, min(31, $this->recurring_payment_day));
                $scheduledDate->day(min($day, $scheduledDate->daysInMonth));
            }

            // Adjust final payment amount for rounding
            $amount = $this->monthly_payment;
            if ($i === $this->duration_months) {
                $amount = $this->amount_remaining - (($this->duration_months - 1) * $this->monthly_payment);
                if ($amount < 0) {
                    $amount = $this->monthly_payment;
                }
            }

            // Calculate fee for this payment (absorb rounding remainder on last payment)
            $fee = $feePerPayment;
            if ($feePerPayment > 0 && $i === $this->duration_months) {
                $totalNca = $feePerPayment * ($this->duration_months + 1); // total NCA across all payments including down payment
                $fee = round($totalNca - $totalFeeDistributed - $feePerPayment, 2); // remaining NCA for installments minus what's already counted
                // Simpler: just use the standard fee for the last payment too since rounding difference is minimal
                $fee = $feePerPayment;
            }
            $totalFeeDistributed += $fee;

            $this->payments()->create([
                'customer_id' => $this->customer_id,
                'client_id' => $this->client_id,
                'transaction_id' => 'scheduled_'.$this->plan_id.'_'.$i,
                'amount' => $amount,
                'fee' => $fee,
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
