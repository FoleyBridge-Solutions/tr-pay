<?php

// app/Models/CustomerPaymentMethod.php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * CustomerPaymentMethod Model
 *
 * Represents a saved payment method (card or ACH) for a customer.
 * Card data is stored securely by MiPaymentChoice gateway (tokenized) for PCI compliance.
 * ACH bank details are stored encrypted (AES-256-CBC via Laravel encrypt()) for
 * NACHA-compliant security, since Kotapay does not support ACH tokenization.
 *
 * @property int $id
 * @property int $customer_id
 * @property string $mpc_token MiPaymentChoice reusable token
 * @property string $type 'card' or 'ach'
 * @property string $last_four Last 4 digits for display
 * @property string|null $brand Card brand (Visa, Mastercard, etc.)
 * @property int|null $exp_month Card expiration month (1-12)
 * @property int|null $exp_year Card expiration year (e.g., 2027)
 * @property string|null $bank_name Bank name for ACH
 * @property string|null $account_type Account type for ACH ('checking' or 'savings')
 * @property bool $is_business Whether this is a business account (for CCD vs PPD batching)
 * @property string|null $encrypted_bank_details Encrypted routing/account numbers for ACH methods
 * @property string|null $nickname User-friendly name
 * @property bool $is_default Whether this is the default payment method
 * @property Carbon|null $expiration_notified_at When expiration notice was sent
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read Customer $customer
 * @property-read string $display_name
 * @property-read string|null $expiration_display
 */
class CustomerPaymentMethod extends Model
{
    use HasFactory;

    // Type constants
    public const TYPE_CARD = 'card';

    public const TYPE_ACH = 'ach';

    // Common card brands
    public const BRAND_VISA = 'Visa';

    public const BRAND_MASTERCARD = 'Mastercard';

    public const BRAND_AMEX = 'American Express';

    public const BRAND_DISCOVER = 'Discover';

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'customer_id',
        'mpc_token',
        'type',
        'last_four',
        'brand',
        'exp_month',
        'exp_year',
        'bank_name',
        'account_type',
        'is_business',
        'encrypted_bank_details',
        'nickname',
        'is_default',
        'expiration_notified_at',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_default' => 'boolean',
            'is_business' => 'boolean',
            'exp_month' => 'integer',
            'exp_year' => 'integer',
            'expiration_notified_at' => 'datetime',
        ];
    }

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'mpc_token', // Never expose the token in API responses
        'encrypted_bank_details', // Never expose encrypted bank details
    ];

    // ==================== Relationships ====================

    /**
     * Get the customer that owns this payment method.
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    // ==================== Accessors ====================

    /**
     * Get a user-friendly display name for this payment method.
     *
     * @return string e.g., "Personal Card" or "Visa •••• 4242"
     */
    public function getDisplayNameAttribute(): string
    {
        if ($this->nickname) {
            return $this->nickname;
        }

        if ($this->type === self::TYPE_CARD) {
            $brand = $this->brand ?? 'Card';

            return "{$brand} •••• {$this->last_four}";
        }

        // ACH
        $bank = $this->bank_name ?? 'Bank';

        return "{$bank} •••• {$this->last_four}";
    }

    /**
     * Get the expiration display string for cards.
     *
     * @return string|null e.g., "12/25" or null for ACH
     */
    public function getExpirationDisplayAttribute(): ?string
    {
        if ($this->type !== self::TYPE_CARD || ! $this->exp_month || ! $this->exp_year) {
            return null;
        }

        $month = str_pad($this->exp_month, 2, '0', STR_PAD_LEFT);
        $year = substr($this->exp_year, -2);

        return "{$month}/{$year}";
    }

    /**
     * Get a full description of the payment method.
     *
     * @return string e.g., "Visa •••• 4242 (expires 12/25)"
     */
    public function getFullDescriptionAttribute(): string
    {
        $description = $this->display_name;

        if ($this->type === self::TYPE_CARD && $this->expiration_display) {
            $description .= " (expires {$this->expiration_display})";
        } elseif ($this->type === self::TYPE_ACH && $this->bank_name && ! $this->nickname) {
            // If no nickname and we have bank name, it's already in display_name
        }

        return $description;
    }

    // ==================== Query Scopes ====================

    /**
     * Scope: Only card payment methods.
     */
    public function scopeCards($query)
    {
        return $query->where('type', self::TYPE_CARD);
    }

    /**
     * Scope: Only ACH payment methods.
     */
    public function scopeAch($query)
    {
        return $query->where('type', self::TYPE_ACH);
    }

    /**
     * Scope: Default payment method.
     */
    public function scopeDefault($query)
    {
        return $query->where('is_default', true);
    }

    /**
     * Scope: Payment methods expiring within a given number of days.
     */
    public function scopeExpiringWithinDays($query, int $days)
    {
        $now = now();
        $futureDate = now()->addDays($days);

        return $query->where('type', self::TYPE_CARD)
            ->where(function ($q) use ($now, $futureDate) {
                // Same year: check month range
                $q->where(function ($q2) use ($now, $futureDate) {
                    $q2->where('exp_year', $now->year)
                        ->where('exp_month', '>=', $now->month)
                        ->where('exp_month', '<=', $futureDate->month);
                })
                // Or next year if we're crossing year boundary
                    ->orWhere(function ($q2) use ($now, $futureDate) {
                        if ($futureDate->year > $now->year) {
                            $q2->where('exp_year', $futureDate->year)
                                ->where('exp_month', '<=', $futureDate->month);
                        }
                    });
            });
    }

    /**
     * Scope: Expired payment methods (cards only).
     */
    public function scopeExpired($query)
    {
        $now = now();

        return $query->where('type', self::TYPE_CARD)
            ->where(function ($q) use ($now) {
                $q->where('exp_year', '<', $now->year)
                    ->orWhere(function ($q2) use ($now) {
                        $q2->where('exp_year', $now->year)
                            ->where('exp_month', '<', $now->month);
                    });
            });
    }

    /**
     * Scope: Payment methods that haven't been notified about expiration.
     */
    public function scopeNotNotifiedAboutExpiration($query)
    {
        return $query->whereNull('expiration_notified_at');
    }

    // ==================== Helper Methods ====================

    /**
     * Check if this payment method is a card.
     */
    public function isCard(): bool
    {
        return $this->type === self::TYPE_CARD;
    }

    /**
     * Check if this payment method is ACH.
     */
    public function isAch(): bool
    {
        return $this->type === self::TYPE_ACH;
    }

    /**
     * Check if this card is expired.
     */
    public function isExpired(): bool
    {
        if ($this->type !== self::TYPE_CARD || ! $this->exp_month || ! $this->exp_year) {
            return false;
        }

        $now = now();

        // Expired if year is past, or same year but month is past
        if ($this->exp_year < $now->year) {
            return true;
        }

        if ($this->exp_year === $now->year && $this->exp_month < $now->month) {
            return true;
        }

        return false;
    }

    /**
     * Check if this card expires within a given number of days.
     *
     * @param  int  $days  Number of days to check
     */
    public function expiresWithinDays(int $days): bool
    {
        if ($this->type !== self::TYPE_CARD || ! $this->exp_month || ! $this->exp_year) {
            return false;
        }

        // Create expiration date (end of the expiration month)
        $expirationDate = Carbon::createFromDate($this->exp_year, $this->exp_month, 1)
            ->endOfMonth();

        $futureDate = now()->addDays($days);

        return $expirationDate->lte($futureDate) && ! $this->isExpired();
    }

    /**
     * Check if this card is expiring soon (within 30 days).
     * Convenience method for views.
     */
    public function isExpiringSoon(): bool
    {
        return $this->expiresWithinDays(30);
    }

    /**
     * Mark this payment method as the default.
     *
     * Uses a database transaction to prevent race conditions.
     *
     * @return $this
     */
    public function makeDefault(): self
    {
        return DB::transaction(function () {
            // Unset all other payment methods as default for this customer
            self::where('customer_id', $this->customer_id)
                ->where('id', '!=', $this->id)
                ->update(['is_default' => false]);

            // Set this one as default
            $this->is_default = true;
            $this->save();

            return $this;
        });
    }

    /**
     * Check if this payment method is linked to any active payment plans.
     */
    public function isLinkedToActivePlans(): bool
    {
        return $this->getLinkedPaymentPlans()->isNotEmpty()
            || $this->getLinkedRecurringPayments()->isNotEmpty();
    }

    /**
     * Get active payment plans that use this payment method's token.
     *
     * @return Collection<PaymentPlan>
     */
    public function getLinkedPaymentPlans(): Collection
    {
        return PaymentPlan::where('customer_id', $this->customer_id)
            ->where('payment_method_token', $this->mpc_token)
            ->whereIn('status', [
                PaymentPlan::STATUS_ACTIVE,
                PaymentPlan::STATUS_PAST_DUE,
            ])
            ->get();
    }

    /**
     * Get active recurring payments that use this payment method's token.
     *
     * @return Collection<RecurringPayment>
     */
    public function getLinkedRecurringPayments(): Collection
    {
        return RecurringPayment::where('customer_id', $this->customer_id)
            ->where('payment_method_token', $this->mpc_token)
            ->whereIn('status', [
                RecurringPayment::STATUS_ACTIVE,
                RecurringPayment::STATUS_PAUSED,
            ])
            ->get();
    }

    /**
     * Get all linked active plans/recurring payments for display.
     *
     * @return array{payment_plans: Collection, recurring_payments: Collection, total_count: int}
     */
    public function getLinkedPlansInfo(): array
    {
        $paymentPlans = $this->getLinkedPaymentPlans();
        $recurringPayments = $this->getLinkedRecurringPayments();

        return [
            'payment_plans' => $paymentPlans,
            'recurring_payments' => $recurringPayments,
            'total_count' => $paymentPlans->count() + $recurringPayments->count(),
        ];
    }

    /**
     * Mark that an expiration notification was sent.
     */
    public function markExpirationNotified(): void
    {
        $this->expiration_notified_at = now();
        $this->save();
    }

    /**
     * Store encrypted bank account details for ACH methods.
     * Uses Laravel's encrypt() (AES-256-CBC) for NACHA-compliant encryption at rest.
     */
    public function setBankDetails(string $routingNumber, string $accountNumber): void
    {
        $this->encrypted_bank_details = encrypt(json_encode([
            'routing_number' => $routingNumber,
            'account_number' => $accountNumber,
        ]));
        $this->save();
    }

    /**
     * Retrieve decrypted bank account details for ACH methods.
     *
     * @return array{routing_number: string, account_number: string}|null
     */
    public function getBankDetails(): ?array
    {
        if (empty($this->encrypted_bank_details)) {
            return null;
        }

        try {
            return json_decode(decrypt($this->encrypted_bank_details), true);
        } catch (\Exception $e) {
            Log::error('Failed to decrypt bank details', [
                'payment_method_id' => $this->id,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Check if this ACH method has stored bank details for charging.
     */
    public function hasBankDetails(): bool
    {
        return ! empty($this->encrypted_bank_details);
    }

    /**
     * Get the icon name for this payment method type/brand.
     *
     * @return string Icon identifier for frontend
     */
    public function getIconAttribute(): string
    {
        if ($this->type === self::TYPE_ACH) {
            return 'bank';
        }

        return match ($this->brand) {
            self::BRAND_VISA => 'visa',
            self::BRAND_MASTERCARD => 'mastercard',
            self::BRAND_AMEX => 'amex',
            self::BRAND_DISCOVER => 'discover',
            default => 'credit-card',
        };
    }

    /**
     * Detect card brand from card number (first 4-6 digits).
     *
     * @param  string  $cardNumber  Full or partial card number
     * @return string|null Detected brand or null
     */
    public static function detectCardBrand(string $cardNumber): ?string
    {
        $number = preg_replace('/\D/', '', $cardNumber);

        if (empty($number)) {
            return null;
        }

        // Visa: starts with 4
        if (str_starts_with($number, '4')) {
            return self::BRAND_VISA;
        }

        // Mastercard: starts with 51-55 or 2221-2720
        $firstTwo = (int) substr($number, 0, 2);
        $firstFour = (int) substr($number, 0, 4);
        if (($firstTwo >= 51 && $firstTwo <= 55) || ($firstFour >= 2221 && $firstFour <= 2720)) {
            return self::BRAND_MASTERCARD;
        }

        // American Express: starts with 34 or 37
        if ($firstTwo === 34 || $firstTwo === 37) {
            return self::BRAND_AMEX;
        }

        // Discover: starts with 6011, 622126-622925, 644-649, 65
        if (str_starts_with($number, '6011') ||
            str_starts_with($number, '65') ||
            ($firstTwo >= 64 && $firstTwo <= 65)) {
            return self::BRAND_DISCOVER;
        }

        return null;
    }
}
