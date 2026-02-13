<?php

// app/Models/Customer.php

namespace App\Models;

use App\Models\Ach\AchEntry;
use FoleyBridgeSolutions\KotapayCashier\Traits\AchBillable;
use FoleyBridgeSolutions\MiPaymentChoiceCashier\Traits\CardBillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

/**
 * Customer Model
 *
 * Represents a customer who can make payments through MiPaymentChoice.
 * This model uses the Billable trait from mipaymentchoice-cashier package
 * to handle payment methods, subscriptions, and charges.
 *
 * ⚠️ IMPORTANT: This model uses the DEFAULT database connection (SQLite)
 * NOT the 'sqlsrv' connection (PracticeCS).
 *
 * @property int $id
 * @property string $name
 * @property string|null $email
 * @property string|null $client_id
 * @property string|null $client_key
 * @property string|null $mpc_customer_id
 * @property-read Collection<CustomerPaymentMethod> $customerPaymentMethods
 * @property-read Collection<PaymentPlan> $paymentPlans
 * @property-read Collection<RecurringPayment> $recurringPayments
 * @property-read Collection<Payment> $payments
 */
class Customer extends Model
{
    // For credit/debit cards via MiPaymentChoice
    use AchBillable;
    use CardBillable;     // For ACH payments via Kotapay
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'name',
        'email',
        'client_id',         // Reference to Client.client_id from PracticeCS SQL Server
        'client_key',        // Reference to Client.client_KEY from PracticeCS SQL Server
        'mpc_customer_id',   // MiPaymentChoice Customer ID (added by migration)
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    // ==================== Relationships ====================

    /**
     * Get the saved payment methods for this customer.
     *
     * Note: This is separate from the Billable trait's paymentMethods() which
     * is designed for admin users. This relationship is for customer-facing
     * saved payment methods.
     */
    public function customerPaymentMethods(): HasMany
    {
        return $this->hasMany(CustomerPaymentMethod::class)->orderBy('is_default', 'desc')->orderBy('created_at', 'desc');
    }

    /**
     * Get the payment plans for this customer.
     */
    public function paymentPlans(): HasMany
    {
        return $this->hasMany(PaymentPlan::class);
    }

    /**
     * Get the recurring payments for this customer.
     */
    public function recurringPayments(): HasMany
    {
        return $this->hasMany(RecurringPayment::class);
    }

    /**
     * Get all payments for this customer.
     */
    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    /**
     * Get the ACH entries for this customer.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany<\App\Models\Ach\AchEntry, self>
     */
    public function achEntries(): HasMany
    {
        return $this->hasMany(AchEntry::class);
    }

    // ==================== Customer Payment Method Helpers ====================

    /**
     * Get the default saved payment method for this customer.
     */
    public function defaultCustomerPaymentMethod(): ?CustomerPaymentMethod
    {
        return $this->customerPaymentMethods()->where('is_default', true)->first();
    }

    /**
     * Check if the customer has any saved payment methods.
     */
    public function hasCustomerPaymentMethods(): bool
    {
        return $this->customerPaymentMethods()->exists();
    }

    /**
     * Check if the customer has saved payment methods of a specific type.
     *
     * @param  string  $type  'card' or 'ach'
     */
    public function hasCustomerPaymentMethodsOfType(string $type): bool
    {
        return $this->customerPaymentMethods()->where('type', $type)->exists();
    }

    /**
     * Get saved payment methods of a specific type.
     *
     * @param  string  $type  'card' or 'ach'
     * @return Collection<CustomerPaymentMethod>
     */
    public function getCustomerPaymentMethodsByType(string $type): Collection
    {
        return $this->customerPaymentMethods()->where('type', $type)->get();
    }

    /**
     * Add a new saved payment method for this customer.
     *
     * Uses a database transaction to prevent race conditions when setting
     * the default payment method.
     *
     * @param  array  $data  Payment method data
     */
    public function addCustomerPaymentMethod(array $data): CustomerPaymentMethod
    {
        return DB::transaction(function () use ($data) {
            // If this is the first payment method or explicitly set as default
            $isDefault = $data['is_default'] ?? ! $this->hasCustomerPaymentMethods();

            // If setting as default, unset other defaults
            if ($isDefault) {
                $this->customerPaymentMethods()->update(['is_default' => false]);
            }

            return $this->customerPaymentMethods()->create(array_merge($data, [
                'is_default' => $isDefault,
            ]));
        });
    }

    /**
     * Find a saved payment method by ID for this customer.
     */
    public function findCustomerPaymentMethod(int $methodId): ?CustomerPaymentMethod
    {
        return $this->customerPaymentMethods()->find($methodId);
    }

    /**
     * Get active payment plans for this customer.
     *
     * @return Collection<PaymentPlan>
     */
    public function activePaymentPlans(): Collection
    {
        return $this->paymentPlans()
            ->whereIn('status', [PaymentPlan::STATUS_ACTIVE, PaymentPlan::STATUS_PAST_DUE])
            ->get();
    }

    /**
     * Get active recurring payments for this customer.
     *
     * @return Collection<RecurringPayment>
     */
    public function activeRecurringPayments(): Collection
    {
        return $this->recurringPayments()
            ->whereIn('status', [RecurringPayment::STATUS_ACTIVE, RecurringPayment::STATUS_PAUSED])
            ->get();
    }
}
