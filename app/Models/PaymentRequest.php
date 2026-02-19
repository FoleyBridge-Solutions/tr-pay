<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * PaymentRequest Model
 *
 * Represents a payment request sent to a client via email.
 * Contains a unique token for a single-use, expiring payment link.
 *
 * @property int $id
 * @property string $token
 * @property int|null $client_key
 * @property string $client_id
 * @property string $client_name
 * @property string $email
 * @property float $amount
 * @property array|null $invoices
 * @property string|null $message
 * @property int $sent_by
 * @property \Carbon\Carbon $expires_at
 * @property \Carbon\Carbon|null $paid_at
 * @property int|null $payment_id
 * @property \Carbon\Carbon|null $revoked_at
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 */
class PaymentRequest extends Model
{
    // Status constants (computed, not stored)
    public const STATUS_PENDING = 'pending';

    public const STATUS_PAID = 'paid';

    public const STATUS_EXPIRED = 'expired';

    public const STATUS_REVOKED = 'revoked';

    /**
     * Default expiration period in days.
     */
    public const EXPIRATION_DAYS = 30;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'token',
        'client_key',
        'client_id',
        'client_name',
        'email',
        'amount',
        'invoices',
        'message',
        'sent_by',
        'expires_at',
        'paid_at',
        'payment_id',
        'revoked_at',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'amount' => 'float',
        'invoices' => 'array',
        'expires_at' => 'datetime',
        'paid_at' => 'datetime',
        'revoked_at' => 'datetime',
    ];

    /**
     * Boot the model and auto-generate a token on creation.
     */
    protected static function booted(): void
    {
        static::creating(function (PaymentRequest $paymentRequest) {
            if (empty($paymentRequest->token)) {
                $paymentRequest->token = Str::random(64);
            }

            if (empty($paymentRequest->expires_at)) {
                $paymentRequest->expires_at = now()->addDays(self::EXPIRATION_DAYS);
            }
        });
    }

    /**
     * Get the computed status of this payment request.
     */
    public function getStatusAttribute(): string
    {
        if ($this->paid_at) {
            return self::STATUS_PAID;
        }

        if ($this->revoked_at) {
            return self::STATUS_REVOKED;
        }

        if ($this->expires_at->isPast()) {
            return self::STATUS_EXPIRED;
        }

        return self::STATUS_PENDING;
    }

    /**
     * Check if this payment request can be used (is pending and not expired/revoked/paid).
     */
    public function isUsable(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    /**
     * Check if this payment request has been paid.
     */
    public function isPaid(): bool
    {
        return $this->status === self::STATUS_PAID;
    }

    /**
     * Check if this payment request has expired.
     */
    public function isExpired(): bool
    {
        return $this->status === self::STATUS_EXPIRED;
    }

    /**
     * Check if this payment request has been revoked.
     */
    public function isRevoked(): bool
    {
        return $this->status === self::STATUS_REVOKED;
    }

    /**
     * Mark this payment request as paid.
     *
     * @param  int  $paymentId  The resulting Payment record ID
     */
    public function markPaid(int $paymentId): void
    {
        $this->update([
            'paid_at' => now(),
            'payment_id' => $paymentId,
        ]);
    }

    /**
     * Revoke this payment request.
     */
    public function revoke(): void
    {
        $this->update([
            'revoked_at' => now(),
        ]);
    }

    /**
     * Get the public payment URL for this request.
     */
    public function getPaymentUrlAttribute(): string
    {
        return url('/pay/'.$this->token);
    }

    /**
     * The admin user who sent this request.
     */
    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sent_by');
    }

    /**
     * The resulting payment record (if paid).
     */
    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    /**
     * Scope: only pending (not paid, not revoked, not expired).
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopePending($query)
    {
        return $query->whereNull('paid_at')
            ->whereNull('revoked_at')
            ->where('expires_at', '>', now());
    }

    /**
     * Scope: only paid.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopePaid($query)
    {
        return $query->whereNotNull('paid_at');
    }

    /**
     * Find a payment request by its token.
     */
    public static function findByToken(string $token): ?self
    {
        return static::where('token', $token)->first();
    }
}
