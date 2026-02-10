<?php

namespace App\Models\Ach;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * AchFile Model
 *
 * Represents a generated NACHA file submitted to Kotapay.
 *
 * @property int $id
 * @property string $filename
 * @property string $file_id_modifier
 * @property string $immediate_destination
 * @property string $immediate_origin
 * @property string $immediate_destination_name
 * @property string $immediate_origin_name
 * @property \Carbon\Carbon $file_creation_date
 * @property string|null $file_creation_time
 * @property int $batch_count
 * @property int $block_count
 * @property int $entry_addenda_count
 * @property string|null $entry_hash
 * @property int $total_debit_amount
 * @property int $total_credit_amount
 * @property string|null $file_contents
 * @property string|null $file_hash
 * @property string $status
 */
class AchFile extends Model
{
    // Status constants
    public const STATUS_PENDING = 'pending';

    public const STATUS_GENERATED = 'generated';

    public const STATUS_SUBMITTED = 'submitted';

    public const STATUS_ACCEPTED = 'accepted';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_PROCESSING = 'processing';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'filename',
        'file_id_modifier',
        'immediate_destination',
        'immediate_origin',
        'immediate_destination_name',
        'immediate_origin_name',
        'file_creation_date',
        'file_creation_time',
        'batch_count',
        'block_count',
        'entry_addenda_count',
        'entry_hash',
        'total_debit_amount',
        'total_credit_amount',
        'file_contents',
        'file_hash',
        'status',
        'rejection_reason',
        'kotapay_reference',
        'kotapay_filename',
        'generated_at',
        'submitted_at',
        'accepted_at',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'file_creation_date' => 'date',
            'batch_count' => 'integer',
            'block_count' => 'integer',
            'entry_addenda_count' => 'integer',
            'total_debit_amount' => 'integer',
            'total_credit_amount' => 'integer',
            'generated_at' => 'datetime',
            'submitted_at' => 'datetime',
            'accepted_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    // ==================== Relationships ====================

    public function batches(): HasMany
    {
        return $this->hasMany(AchBatch::class, 'ach_file_id');
    }

    public function returns(): HasMany
    {
        return $this->hasMany(AchReturn::class, 'ach_file_id');
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
     * Get total entry count across all batches.
     */
    public function getTotalEntryCountAttribute(): int
    {
        return $this->batches()->withCount('entries')->get()->sum('entries_count');
    }

    // ==================== Helper Methods ====================

    /**
     * Generate a unique filename for a new ACH file.
     */
    public static function generateFilename(string $companyId, string $modifier = 'A'): string
    {
        $date = now()->format('Ymd');
        $time = now()->format('His');

        return "ACH_{$companyId}_{$date}_{$time}_{$modifier}.txt";
    }

    /**
     * Get the next available file ID modifier for today.
     */
    public static function getNextModifier(): string
    {
        $today = now()->toDateString();

        $lastFile = self::whereDate('file_creation_date', $today)
            ->orderBy('file_id_modifier', 'desc')
            ->first();

        if (! $lastFile) {
            return 'A';
        }

        $lastModifier = $lastFile->file_id_modifier;

        // Increment: A -> B -> ... -> Z -> 0 -> 1 -> ... -> 9
        if ($lastModifier === 'Z') {
            return '0';
        } elseif ($lastModifier === '9') {
            throw new \RuntimeException('Maximum files per day reached (36)');
        } elseif (ctype_alpha($lastModifier)) {
            return chr(ord($lastModifier) + 1);
        } else {
            return (string) ((int) $lastModifier + 1);
        }
    }

    /**
     * Mark file as generated.
     */
    public function markAsGenerated(string $contents): void
    {
        $this->update([
            'file_contents' => $contents,
            'file_hash' => hash('sha256', $contents),
            'status' => self::STATUS_GENERATED,
            'generated_at' => now(),
        ]);
    }

    /**
     * Mark file as submitted.
     */
    public function markAsSubmitted(?string $kotapayReference = null): void
    {
        $this->update([
            'status' => self::STATUS_SUBMITTED,
            'kotapay_reference' => $kotapayReference,
            'submitted_at' => now(),
        ]);
    }

    /**
     * Mark file as accepted.
     */
    public function markAsAccepted(): void
    {
        $this->update([
            'status' => self::STATUS_ACCEPTED,
            'accepted_at' => now(),
        ]);
    }

    /**
     * Mark file as rejected.
     */
    public function markAsRejected(string $reason): void
    {
        $this->update([
            'status' => self::STATUS_REJECTED,
            'rejection_reason' => $reason,
        ]);
    }

    // ==================== Scopes ====================

    public function scopeGenerated($query)
    {
        return $query->where('status', self::STATUS_GENERATED);
    }

    public function scopeReadyToSubmit($query)
    {
        return $query->where('status', self::STATUS_GENERATED);
    }

    public function scopeSubmitted($query)
    {
        return $query->where('status', self::STATUS_SUBMITTED);
    }

    public function scopePending($query)
    {
        return $query->whereIn('status', [
            self::STATUS_SUBMITTED,
            self::STATUS_ACCEPTED,
            self::STATUS_PROCESSING,
        ]);
    }
}
