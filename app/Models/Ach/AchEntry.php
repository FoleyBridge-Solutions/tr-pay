<?php

namespace App\Models\Ach;

use App\Models\Customer;
use App\Models\Payment;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Crypt;

/**
 * AchEntry Model
 *
 * Represents a single ACH transaction entry within a batch.
 *
 * @property int $id
 * @property int $ach_batch_id
 * @property int|null $payment_id
 * @property int|null $customer_id
 * @property string $transaction_code
 * @property string $receiving_dfi_id
 * @property string $check_digit
 * @property string $dfi_account_number_encrypted
 * @property int $amount
 * @property string|null $individual_id
 * @property string $individual_name
 * @property string|null $discretionary_data
 * @property string $trace_number
 * @property bool $has_addenda
 * @property string|null $addenda_info
 * @property string|null $client_id
 * @property string|null $routing_number_last_four
 * @property string|null $account_number_last_four
 * @property string $account_type
 * @property string $status
 * @property string|null $return_code
 * @property string|null $return_reason
 * @property string|null $noc_code
 * @property string|null $noc_data
 */
class AchEntry extends Model
{
    // Transaction codes
    public const TRANS_DEBIT_CHECKING = '27';

    public const TRANS_DEBIT_SAVINGS = '37';

    public const TRANS_CREDIT_CHECKING = '22';

    public const TRANS_CREDIT_SAVINGS = '32';

    // Status constants
    public const STATUS_PENDING = 'pending';

    public const STATUS_SUBMITTED = 'submitted';

    public const STATUS_ACCEPTED = 'accepted';

    public const STATUS_SETTLED = 'settled';

    public const STATUS_RETURNED = 'returned';

    public const STATUS_CORRECTED = 'corrected';

    public const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'ach_batch_id',
        'payment_id',
        'customer_id',
        'transaction_code',
        'receiving_dfi_id',
        'check_digit',
        'dfi_account_number_encrypted',
        'amount',
        'individual_id',
        'individual_name',
        'discretionary_data',
        'trace_number',
        'has_addenda',
        'addenda_info',
        'client_id',
        'routing_number_last_four',
        'account_number_last_four',
        'account_type',
        'is_business',
        'status',
        'return_code',
        'return_reason',
        'noc_code',
        'noc_data',
        'submitted_at',
        'settled_at',
        'returned_at',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'has_addenda' => 'boolean',
            'is_business' => 'boolean',
            'submitted_at' => 'datetime',
            'settled_at' => 'datetime',
            'returned_at' => 'datetime',
        ];
    }

    // ==================== Relationships ====================

    public function batch(): BelongsTo
    {
        return $this->belongsTo(AchBatch::class, 'ach_batch_id');
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function returns(): HasMany
    {
        return $this->hasMany(AchReturn::class, 'ach_entry_id');
    }

    // ==================== Accessors & Mutators ====================

    /**
     * Get the decrypted account number.
     */
    public function getAccountNumberAttribute(): ?string
    {
        if (empty($this->dfi_account_number_encrypted)) {
            return null;
        }

        try {
            return Crypt::decryptString($this->dfi_account_number_encrypted);
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Set the account number (encrypts automatically).
     */
    public function setAccountNumberAttribute(string $value): void
    {
        $this->attributes['dfi_account_number_encrypted'] = Crypt::encryptString($value);
        $this->attributes['account_number_last_four'] = substr($value, -4);
    }

    /**
     * Get the full routing number (receiving_dfi_id + check_digit).
     */
    public function getFullRoutingNumberAttribute(): string
    {
        return $this->receiving_dfi_id.$this->check_digit;
    }

    /**
     * Get amount in dollars.
     */
    public function getAmountDollarsAttribute(): float
    {
        return $this->amount / 100;
    }

    // ==================== Helper Methods ====================

    /**
     * Check if this is a debit entry.
     */
    public function isDebit(): bool
    {
        return in_array($this->transaction_code, [
            self::TRANS_DEBIT_CHECKING,
            self::TRANS_DEBIT_SAVINGS,
        ]);
    }

    /**
     * Check if this is a credit entry.
     */
    public function isCredit(): bool
    {
        return in_array($this->transaction_code, [
            self::TRANS_CREDIT_CHECKING,
            self::TRANS_CREDIT_SAVINGS,
        ]);
    }

    /**
     * Calculate the check digit for a routing number.
     */
    public static function calculateCheckDigit(string $routingNumber): string
    {
        $routing = str_pad(preg_replace('/\D/', '', $routingNumber), 9, '0', STR_PAD_LEFT);

        // ABA check digit algorithm
        $sum = (3 * ($routing[0] + $routing[3] + $routing[6])) +
               (7 * ($routing[1] + $routing[4] + $routing[7])) +
               ($routing[2] + $routing[5] + $routing[8]);

        return (string) ((10 - ($sum % 10)) % 10);
    }

    /**
     * Parse a full routing number into DFI ID and check digit.
     */
    public static function parseRoutingNumber(string $routingNumber): array
    {
        $routing = str_pad(preg_replace('/\D/', '', $routingNumber), 9, '0', STR_PAD_LEFT);

        return [
            'receiving_dfi_id' => substr($routing, 0, 8),
            'check_digit' => substr($routing, 8, 1),
        ];
    }

    /**
     * Get transaction code for debit based on account type.
     */
    public static function getDebitTransactionCode(string $accountType): string
    {
        return strtolower($accountType) === 'savings'
            ? self::TRANS_DEBIT_SAVINGS
            : self::TRANS_DEBIT_CHECKING;
    }

    /**
     * Mark entry as returned.
     */
    public function markAsReturned(string $returnCode, string $reason): void
    {
        $this->update([
            'status' => self::STATUS_RETURNED,
            'return_code' => $returnCode,
            'return_reason' => $reason,
            'returned_at' => now(),
        ]);
    }

    // ==================== Scopes ====================

    public function scopePending($query)
    {
        return $query->where('status', self::STATUS_PENDING);
    }

    public function scopeSubmitted($query)
    {
        return $query->where('status', self::STATUS_SUBMITTED);
    }

    public function scopeReturned($query)
    {
        return $query->where('status', self::STATUS_RETURNED);
    }

    public function scopeDebits($query)
    {
        return $query->whereIn('transaction_code', [
            self::TRANS_DEBIT_CHECKING,
            self::TRANS_DEBIT_SAVINGS,
        ]);
    }
}
