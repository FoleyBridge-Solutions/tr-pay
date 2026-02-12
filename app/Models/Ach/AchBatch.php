<?php

namespace App\Models\Ach;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * AchBatch Model
 *
 * Represents a batch of ACH entries within a file.
 *
 * @property int $id
 * @property string $batch_number
 * @property string $company_name
 * @property string $company_id
 * @property string $sec_code
 * @property string $company_entry_description
 * @property string|null $company_descriptive_date
 * @property \Carbon\Carbon $effective_entry_date
 * @property int $entry_count
 * @property int $total_debit_amount
 * @property int $total_credit_amount
 * @property string|null $entry_hash
 * @property string $status
 */
class AchBatch extends Model
{
    // SEC codes (Standard Entry Class)
    public const SEC_WEB = 'WEB'; // Internet-initiated entries

    public const SEC_PPD = 'PPD'; // Prearranged Payment and Deposit

    public const SEC_CCD = 'CCD'; // Corporate Credit or Debit

    public const SEC_TEL = 'TEL'; // Telephone-initiated entries

    // Status constants
    public const STATUS_PENDING = 'pending';

    public const STATUS_READY = 'ready';

    public const STATUS_GENERATED = 'generated';

    public const STATUS_SUBMITTED = 'submitted';

    public const STATUS_ACCEPTED = 'accepted';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_SETTLED = 'settled';

    public const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'batch_number',
        'company_name',
        'company_id',
        'sec_code',
        'company_entry_description',
        'company_descriptive_date',
        'effective_entry_date',
        'entry_count',
        'total_debit_amount',
        'total_credit_amount',
        'entry_hash',
        'status',
        'rejection_reason',
        'kotapay_reference',
        'generated_at',
        'submitted_at',
        'settled_at',
    ];

    protected function casts(): array
    {
        return [
            'effective_entry_date' => 'date',
            'entry_count' => 'integer',
            'total_debit_amount' => 'integer',
            'total_credit_amount' => 'integer',
            'generated_at' => 'datetime',
            'submitted_at' => 'datetime',
            'settled_at' => 'datetime',
        ];
    }

    // ==================== Relationships ====================

    public function entries(): HasMany
    {
        return $this->hasMany(AchEntry::class, 'ach_batch_id');
    }

    // ==================== Accessors ====================

    /**
     * Get total debit amount in dollars.
     */
    public function getTotalDebitDollarsAttribute(): float
    {
        return $this->total_debit_amount / 100;
    }

    /**
     * Get total credit amount in dollars.
     */
    public function getTotalCreditDollarsAttribute(): float
    {
        return $this->total_credit_amount / 100;
    }

    /**
     * Get net amount in dollars (debits - credits).
     */
    public function getNetAmountDollarsAttribute(): float
    {
        return ($this->total_debit_amount - $this->total_credit_amount) / 100;
    }

    // ==================== Helper Methods ====================

    /**
     * Generate a unique 7-digit batch number.
     */
    public static function generateBatchNumber(): string
    {
        $lastBatch = self::orderBy('id', 'desc')->first();
        $nextNumber = $lastBatch ? ((int) $lastBatch->batch_number + 1) : 1;

        return str_pad($nextNumber % 10000000, 7, '0', STR_PAD_LEFT);
    }

    /**
     * Recalculate batch totals from entries.
     */
    public function recalculateTotals(): void
    {
        $entries = $this->entries;

        $this->entry_count = $entries->count();

        $this->total_debit_amount = $entries
            ->filter(fn ($e) => $e->isDebit())
            ->sum('amount');

        $this->total_credit_amount = $entries
            ->filter(fn ($e) => $e->isCredit())
            ->sum('amount');

        // Calculate entry hash (sum of all routing numbers, last 10 digits)
        $routingSum = $entries->sum(fn ($e) => (int) $e->receiving_dfi_id);
        $this->entry_hash = substr(str_pad($routingSum, 10, '0', STR_PAD_LEFT), -10);

        $this->save();
    }

    /**
     * Mark batch as ready for file generation.
     */
    public function markAsReady(): void
    {
        $this->recalculateTotals();
        $this->update(['status' => self::STATUS_READY]);
    }

    /**
     * Check if batch can accept new entries.
     */
    public function canAddEntries(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    /**
     * Check if batch is ready for file generation.
     */
    public function isReady(): bool
    {
        return $this->status === self::STATUS_READY && $this->entry_count > 0;
    }

    // ==================== Scopes ====================

    public function scopePending($query)
    {
        return $query->where('status', self::STATUS_PENDING);
    }

    public function scopeReady($query)
    {
        return $query->where('status', self::STATUS_READY);
    }

    public function scopeForDate($query, $date)
    {
        return $query->whereDate('effective_entry_date', $date);
    }

}
