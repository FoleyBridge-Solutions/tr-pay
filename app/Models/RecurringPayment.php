<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Log;

/**
 * RecurringPayment Model
 *
 * Represents a recurring scheduled payment that processes on a regular interval.
 * Unlike PaymentPlan (invoice-based), these are standalone recurring charges.
 *
 * @property int $id
 * @property int|null $customer_id
 * @property string $client_id
 * @property string $client_name
 * @property string $frequency
 * @property float $amount
 * @property string $description
 * @property string $payment_method_type
 * @property string $payment_method_token
 * @property string|null $payment_method_last_four
 * @property string $status
 * @property Carbon $start_date
 * @property Carbon|null $end_date
 * @property int|null $max_occurrences
 * @property Carbon|null $next_payment_date
 * @property int $payments_completed
 * @property int $payments_failed
 * @property float $total_collected
 * @property Carbon|null $last_payment_at
 * @property string|null $import_batch_id
 * @property array|null $metadata
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class RecurringPayment extends Model
{
    // Frequency constants
    public const FREQUENCY_MONTHLY = 'monthly';

    public const FREQUENCY_QUARTERLY = 'quarterly';

    public const FREQUENCY_YEARLY = 'yearly';

    public const FREQUENCY_WEEKLY = 'weekly';

    public const FREQUENCY_BIWEEKLY = 'biweekly';

    // Status constants
    public const STATUS_ACTIVE = 'active';

    public const STATUS_PAUSED = 'paused';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_PENDING = 'pending'; // Awaiting payment method info

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'customer_id',
        'client_id',
        'client_name',
        'frequency',
        'amount',
        'description',
        'payment_method_type',
        'payment_method_token',
        'customer_payment_method_id',
        'payment_method_last_four',
        'status',
        'start_date',
        'end_date',
        'max_occurrences',
        'next_payment_date',
        'payments_completed',
        'payments_failed',
        'total_collected',
        'last_payment_at',
        'import_batch_id',
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
            'amount' => 'decimal:2',
            'total_collected' => 'decimal:2',
            'payments_completed' => 'integer',
            'payments_failed' => 'integer',
            'max_occurrences' => 'integer',
            'start_date' => 'date',
            'end_date' => 'date',
            'next_payment_date' => 'date',
            'last_payment_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    /**
     * Get the customer for this recurring payment.
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * Get the saved payment method used for this recurring payment.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo<\App\Models\CustomerPaymentMethod, self>
     */
    public function paymentMethod(): BelongsTo
    {
        return $this->belongsTo(CustomerPaymentMethod::class, 'customer_payment_method_id');
    }

    /**
     * Get the payment history for this recurring payment.
     */
    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class, 'recurring_payment_id');
    }

    /**
     * Scope: Active recurring payments.
     */
    public function scopeActive($query)
    {
        return $query->where('status', self::STATUS_ACTIVE);
    }

    /**
     * Scope: Recurring payments due on or before a date.
     */
    public function scopeDueOnOrBefore($query, $date = null)
    {
        $date = $date ?? now();

        return $query->where('status', self::STATUS_ACTIVE)
            ->whereNotNull('next_payment_date')
            ->where('next_payment_date', '<=', $date->toDateString());
    }

    /**
     * Check if the recurring payment is active.
     */
    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    /**
     * Check if payment is due.
     */
    public function isDue(): bool
    {
        return $this->isActive()
            && $this->next_payment_date
            && $this->next_payment_date->lte(now());
    }

    /**
     * Calculate the next payment date based on frequency.
     *
     * Returns null if max_occurrences or end_date has been reached.
     */
    public function calculateNextPaymentDate(?Carbon $fromDate = null): ?Carbon
    {
        $fromDate = $fromDate ?? ($this->next_payment_date ?? $this->start_date);

        // Check if we've reached max occurrences (check after current payment is recorded)
        if ($this->max_occurrences && $this->payments_completed >= $this->max_occurrences) {
            return null;
        }

        // Check if we've reached the end date
        if ($this->end_date && $fromDate->gte($this->end_date)) {
            return null;
        }

        $nextDate = match ($this->frequency) {
            self::FREQUENCY_WEEKLY => $fromDate->copy()->addWeek(),
            self::FREQUENCY_BIWEEKLY => $fromDate->copy()->addWeeks(2),
            self::FREQUENCY_MONTHLY => $fromDate->copy()->addMonth(),
            self::FREQUENCY_QUARTERLY => $fromDate->copy()->addMonths(3),
            self::FREQUENCY_YEARLY => $fromDate->copy()->addYear(),
            default => $fromDate->copy()->addMonth(),
        };

        // If next date exceeds end date, return null
        if ($this->end_date && $nextDate->gt($this->end_date)) {
            return null;
        }

        return $nextDate;
    }

    /**
     * Check if the recurring payment has reached its completion criteria.
     *
     * @return bool True if max_occurrences reached or end_date passed
     */
    public function hasReachedLimit(): bool
    {
        // Check max occurrences
        if ($this->max_occurrences && $this->payments_completed >= $this->max_occurrences) {
            return true;
        }

        // Check end date
        if ($this->end_date && now()->gt($this->end_date)) {
            return true;
        }

        return false;
    }

    /**
     * Get the remaining occurrences if max_occurrences is set.
     *
     * @return int|null Remaining occurrences, or null if unlimited
     */
    public function getRemainingOccurrencesAttribute(): ?int
    {
        if (! $this->max_occurrences) {
            return null;
        }

        return max(0, $this->max_occurrences - $this->payments_completed);
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

        $payment = $this->payments()->create([
            'customer_id' => $this->customer_id,
            'client_id' => $this->client_id,
            'transaction_id' => $transactionId,
            'amount' => $amount,
            'fee' => 0,
            'total_amount' => $amount,
            'payment_method' => $this->payment_method_type,
            'payment_method_last_four' => $this->payment_method_last_four,
            'status' => $isAch ? Payment::STATUS_PROCESSING : Payment::STATUS_COMPLETED,
            'is_automated' => true,
            'description' => $this->description,
            'processed_at' => $isAch ? null : now(),
            'payment_vendor' => $isAch ? 'kotapay' : null,
            'vendor_transaction_id' => $isAch ? ($vendorTransactionId ?? $transactionId) : null,
        ]);

        $this->payments_completed++;
        $this->total_collected += $amount;
        $this->last_payment_at = now();
        $this->payments_failed = 0; // Reset on success

        // Calculate next payment date
        $nextDate = $this->calculateNextPaymentDate();
        if ($nextDate) {
            $this->next_payment_date = $nextDate;
        } else {
            $this->status = self::STATUS_COMPLETED;
            $this->next_payment_date = null;
        }

        $this->save();

        return $payment;
    }

    /**
     * Record a failed payment.
     */
    public function recordFailedPayment(string $reason): Payment
    {
        $payment = $this->payments()->create([
            'customer_id' => $this->customer_id,
            'client_id' => $this->client_id,
            'transaction_id' => 'failed_recurring_'.bin2hex(random_bytes(16)),
            'amount' => $this->amount,
            'fee' => 0,
            'total_amount' => $this->amount,
            'payment_method' => $this->payment_method_type,
            'payment_method_last_four' => $this->payment_method_last_four,
            'status' => Payment::STATUS_FAILED,
            'failure_reason' => $reason,
            'is_automated' => true,
            'description' => $this->description,
            'failed_at' => now(),
        ]);

        $this->payments_failed++;
        $this->save();

        return $payment;
    }

    /**
     * Skip the next scheduled payment.
     *
     * Advances next_payment_date to the following interval without charging.
     * The skip counts toward max_occurrences (payments_completed is incremented).
     * If the limit is reached after skipping, the recurring payment is completed.
     *
     * @throws \RuntimeException If the payment cannot be skipped
     */
    public function skipNextPayment(): void
    {
        if (! $this->isActive() || ! $this->next_payment_date) {
            throw new \RuntimeException('This recurring payment cannot be skipped.');
        }

        $skippedDate = $this->next_payment_date->copy();

        // Count toward max_occurrences
        $this->payments_completed++;

        // Calculate next payment date
        $nextDate = $this->calculateNextPaymentDate();

        if ($nextDate) {
            $this->next_payment_date = $nextDate;
        } else {
            // Reached limit — mark as completed
            $this->status = self::STATUS_COMPLETED;
            $this->next_payment_date = null;
        }

        $this->save();

        Log::info('Skipped recurring payment', [
            'id' => $this->id,
            'client_name' => $this->client_name,
            'skipped_date' => $skippedDate->format('Y-m-d'),
            'next_payment_date' => $this->next_payment_date?->format('Y-m-d'),
            'payments_completed' => $this->payments_completed,
        ]);
    }

    /**
     * Adjust the next payment date to a new date.
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

        $this->next_payment_date = $newDate;
        $this->save();

        Log::info('Adjusted recurring payment next payment date', [
            'id' => $this->id,
            'client_name' => $this->client_name,
            'old_date' => $oldDate?->format('Y-m-d'),
            'new_date' => $newDate->format('Y-m-d'),
        ]);
    }

    /**
     * Pause the recurring payment.
     */
    public function pause(): void
    {
        $this->status = self::STATUS_PAUSED;
        $this->save();
    }

    /**
     * Resume the recurring payment.
     */
    public function resume(): void
    {
        $this->status = self::STATUS_ACTIVE;

        // If next payment date is in the past, set it to today
        if ($this->next_payment_date && $this->next_payment_date->lt(now())) {
            $this->next_payment_date = now();
        }

        $this->save();
    }

    /**
     * Cancel the recurring payment.
     */
    public function cancel(): void
    {
        $this->status = self::STATUS_CANCELLED;
        $this->next_payment_date = null;
        $this->save();
    }

    /**
     * Get frequency display label.
     */
    public function getFrequencyLabelAttribute(): string
    {
        return match ($this->frequency) {
            self::FREQUENCY_WEEKLY => 'Weekly',
            self::FREQUENCY_BIWEEKLY => 'Bi-weekly',
            self::FREQUENCY_MONTHLY => 'Monthly',
            self::FREQUENCY_QUARTERLY => 'Quarterly',
            self::FREQUENCY_YEARLY => 'Yearly',
            default => ucfirst($this->frequency),
        };
    }

    /**
     * Get available frequencies.
     */
    public static function getFrequencies(): array
    {
        return [
            self::FREQUENCY_WEEKLY => 'Weekly',
            self::FREQUENCY_BIWEEKLY => 'Bi-weekly',
            self::FREQUENCY_MONTHLY => 'Monthly',
            self::FREQUENCY_QUARTERLY => 'Quarterly',
            self::FREQUENCY_YEARLY => 'Yearly',
        ];
    }
}
