<?php

namespace App\Models\Ach;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * AchReturn Model
 *
 * Represents an ACH return or NOC (Notification of Change).
 *
 * @property int $id
 * @property int|null $ach_entry_id
 * @property int|null $ach_file_id
 * @property string $trace_number
 * @property string|null $original_trace_number
 * @property \Carbon\Carbon $return_date
 * @property string $return_type
 * @property string $return_code
 * @property string|null $return_reason_code
 * @property string|null $return_description
 * @property string|null $original_receiving_dfi
 * @property int|null $original_amount
 * @property string|null $individual_name
 * @property string|null $corrected_data
 * @property string|null $addenda_information
 * @property string $status
 */
class AchReturn extends Model
{
    // Return types
    public const TYPE_RETURN = 'return';

    public const TYPE_NOC = 'noc';

    public const TYPE_DISHONORED = 'dishonored';

    public const TYPE_CONTESTED = 'contested';

    // Status constants
    public const STATUS_RECEIVED = 'received';

    public const STATUS_PROCESSING = 'processing';

    public const STATUS_APPLIED = 'applied';

    public const STATUS_REVIEWED = 'reviewed';

    public const STATUS_RESOLVED = 'resolved';

    // Common return codes
    public const RETURN_CODES = [
        'R01' => 'Insufficient Funds',
        'R02' => 'Account Closed',
        'R03' => 'No Account/Unable to Locate Account',
        'R04' => 'Invalid Account Number',
        'R05' => 'Improper Debit to Consumer Account',
        'R06' => 'Returned per ODFI Request',
        'R07' => 'Authorization Revoked by Customer',
        'R08' => 'Payment Stopped',
        'R09' => 'Uncollected Funds',
        'R10' => 'Customer Advises Originator is Not Known or Not Authorized',
        'R11' => 'Check Truncation Entry Return',
        'R12' => 'Branch Sold to Another DFI',
        'R13' => 'RDFI Not Qualified to Participate',
        'R14' => 'Representative Payee Deceased or Unable to Continue',
        'R15' => 'Beneficiary or Account Holder Deceased',
        'R16' => 'Account Frozen',
        'R17' => 'File Record Edit Criteria',
        'R20' => 'Non-Transaction Account',
        'R21' => 'Invalid Company Identification',
        'R22' => 'Invalid Individual ID Number',
        'R23' => 'Credit Entry Refused by Receiver',
        'R24' => 'Duplicate Entry',
        'R29' => 'Corporate Customer Advises Not Authorized',
        'R31' => 'Permissible Return Entry (CCD and CTX only)',
        'R33' => 'Return of XCK Entry',
    ];

    // NOC (Notification of Change) codes
    public const NOC_CODES = [
        'C01' => 'Incorrect Account Number',
        'C02' => 'Incorrect Routing Number',
        'C03' => 'Incorrect Routing Number and Account Number',
        'C04' => 'Incorrect Individual Name/Receiving Company Name',
        'C05' => 'Incorrect Transaction Code',
        'C06' => 'Incorrect Account Number and Transaction Code',
        'C07' => 'Incorrect Routing Number, Account Number, and Transaction Code',
        'C09' => 'Incorrect Individual Identification Number',
        'C13' => 'Addenda Format Error',
    ];

    protected $fillable = [
        'ach_entry_id',
        'ach_file_id',
        'trace_number',
        'original_trace_number',
        'return_date',
        'return_type',
        'return_code',
        'return_reason_code',
        'return_description',
        'original_receiving_dfi',
        'original_amount',
        'individual_name',
        'corrected_data',
        'addenda_information',
        'status',
        'notes',
        'reviewed_by',
        'reviewed_at',
        'raw_record',
        'kotapay_reference',
    ];

    protected function casts(): array
    {
        return [
            'return_date' => 'date',
            'original_amount' => 'integer',
            'reviewed_at' => 'datetime',
        ];
    }

    // ==================== Relationships ====================

    public function entry(): BelongsTo
    {
        return $this->belongsTo(AchEntry::class, 'ach_entry_id');
    }

    public function file(): BelongsTo
    {
        return $this->belongsTo(AchFile::class, 'ach_file_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    // ==================== Accessors ====================

    /**
     * Get original amount in dollars.
     */
    public function getOriginalAmountDollarsAttribute(): ?float
    {
        return $this->original_amount ? $this->original_amount / 100 : null;
    }

    /**
     * Get human-readable return code description.
     */
    public function getReturnCodeDescriptionAttribute(): string
    {
        if ($this->isNoc()) {
            return self::NOC_CODES[$this->return_code] ?? 'Unknown NOC Code';
        }

        return self::RETURN_CODES[$this->return_code] ?? 'Unknown Return Code';
    }

    // ==================== Helper Methods ====================

    /**
     * Check if this is a NOC (Notification of Change).
     */
    public function isNoc(): bool
    {
        return $this->return_type === self::TYPE_NOC
            || str_starts_with($this->return_code, 'C');
    }

    /**
     * Check if this is a hard return (account issues, won't retry).
     */
    public function isHardReturn(): bool
    {
        $hardCodes = ['R02', 'R03', 'R04', 'R10', 'R15', 'R16', 'R20', 'R29'];

        return in_array($this->return_code, $hardCodes);
    }

    /**
     * Check if this return can be retried.
     */
    public function canRetry(): bool
    {
        $retriableCodes = ['R01', 'R09']; // Insufficient funds, uncollected funds

        return in_array($this->return_code, $retriableCodes);
    }

    /**
     * Apply return to the original entry.
     */
    public function applyToEntry(): void
    {
        if (! $this->entry) {
            return;
        }

        if ($this->isNoc()) {
            $this->entry->update([
                'status' => AchEntry::STATUS_CORRECTED,
                'noc_code' => $this->return_code,
                'noc_data' => $this->corrected_data,
            ]);
        } else {
            $this->entry->markAsReturned(
                $this->return_code,
                $this->return_code_description
            );
        }

        $this->update(['status' => self::STATUS_APPLIED]);
    }

    /**
     * Mark as reviewed.
     */
    public function markAsReviewed(int $userId, ?string $notes = null): void
    {
        $this->update([
            'status' => self::STATUS_REVIEWED,
            'reviewed_by' => $userId,
            'reviewed_at' => now(),
            'notes' => $notes,
        ]);
    }

    // ==================== Scopes ====================

    public function scopeReturns($query)
    {
        return $query->where('return_type', self::TYPE_RETURN);
    }

    public function scopeNocs($query)
    {
        return $query->where('return_type', self::TYPE_NOC);
    }

    public function scopeUnprocessed($query)
    {
        return $query->where('status', self::STATUS_RECEIVED);
    }

    public function scopeNeedsReview($query)
    {
        return $query->whereIn('status', [
            self::STATUS_RECEIVED,
            self::STATUS_PROCESSING,
        ]);
    }
}
