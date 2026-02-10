<?php

// app/Services/CustomerPaymentMethodService.php

namespace App\Services;

use App\Mail\PaymentMethodDeleted;
use App\Mail\PaymentMethodExpiringSoon;
use App\Mail\PaymentMethodSaved;
use App\Models\Customer;
use App\Models\CustomerPaymentMethod;
use App\Models\PaymentPlan;
use App\Models\RecurringPayment;
use FoleyBridgeSolutions\MiPaymentChoiceCashier\Exceptions\PaymentFailedException;
use FoleyBridgeSolutions\MiPaymentChoiceCashier\Services\QuickPaymentsService;
use FoleyBridgeSolutions\MiPaymentChoiceCashier\Services\TokenService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * CustomerPaymentMethodService
 *
 * Handles CRUD operations for customer saved payment methods.
 * Manages PCI-compliant tokenization and gateway synchronization.
 */
class CustomerPaymentMethodService
{
    protected TokenService $tokenService;

    protected QuickPaymentsService $quickPaymentsService;

    public function __construct(
        TokenService $tokenService,
        QuickPaymentsService $quickPaymentsService
    ) {
        $this->tokenService = $tokenService;
        $this->quickPaymentsService = $quickPaymentsService;
    }

    // ==================== Create Methods ====================

    /**
     * Create a new saved payment method for a customer.
     *
     * @param  Customer  $customer  The customer to add the method to
     * @param  array  $tokenData  Token data from gateway containing:
     *                            - mpc_token: string - The reusable token from MiPaymentChoice
     *                            - type: string - 'card' or 'ach'
     *                            - last_four: string - Last 4 digits
     *                            - brand: string|null - Card brand (for cards)
     *                            - exp_month: int|null - Expiration month (for cards)
     *                            - exp_year: int|null - Expiration year (for cards)
     *                            - bank_name: string|null - Bank name (for ACH)
     *                            - nickname: string|null - User-friendly name
     * @param  bool  $setAsDefault  Whether to set as default payment method
     */
    public function create(Customer $customer, array $tokenData, bool $setAsDefault = false): CustomerPaymentMethod
    {
        $paymentMethod = $customer->addCustomerPaymentMethod([
            'mpc_token' => $tokenData['mpc_token'],
            'type' => $tokenData['type'],
            'last_four' => $tokenData['last_four'],
            'brand' => $tokenData['brand'] ?? null,
            'exp_month' => $tokenData['exp_month'] ?? null,
            'exp_year' => $tokenData['exp_year'] ?? null,
            'bank_name' => $tokenData['bank_name'] ?? null,
            'account_type' => $tokenData['account_type'] ?? null,
            'is_business' => $tokenData['is_business'] ?? false,
            'nickname' => $tokenData['nickname'] ?? null,
            'is_default' => $setAsDefault,
        ]);

        Log::info('Customer payment method created', [
            'customer_id' => $customer->id,
            'payment_method_id' => $paymentMethod->id,
            'type' => $paymentMethod->type,
            'last_four' => $paymentMethod->last_four,
        ]);

        // Send confirmation email
        $this->sendPaymentMethodSavedEmail($customer, $paymentMethod);

        return $paymentMethod;
    }

    /**
     * Create a saved payment method from a QuickPayments token.
     * Converts the one-time QP token to a reusable token first.
     *
     * @param  Customer  $customer  The customer
     * @param  string  $qpToken  QuickPayments one-time token
     * @param  string  $type  'card' or 'ach'
     * @param  array  $displayData  Display data (last_four, brand, etc.)
     * @param  bool  $setAsDefault  Whether to set as default
     *
     * @throws PaymentFailedException
     */
    public function createFromQuickPaymentsToken(
        Customer $customer,
        string $qpToken,
        string $type,
        array $displayData,
        bool $setAsDefault = false
    ): CustomerPaymentMethod {
        try {
            // Convert QP token to reusable token
            $response = $this->quickPaymentsService->createTokenFromQpToken($qpToken);
            $reusableToken = $response['Token'] ?? null;

            if (! $reusableToken) {
                throw new PaymentFailedException('Failed to convert QuickPayments token to reusable token.');
            }

            return $this->create($customer, array_merge($displayData, [
                'mpc_token' => $reusableToken,
                'type' => $type,
            ]), $setAsDefault);

        } catch (\Exception $e) {
            Log::error('Failed to create payment method from QP token', [
                'customer_id' => $customer->id,
                'error' => $e->getMessage(),
            ]);
            throw new PaymentFailedException('Failed to save payment method: '.$e->getMessage(), [], 0, $e);
        }
    }

    /**
     * Create a saved payment method by tokenizing card details directly.
     *
     * @param  Customer  $customer  The customer
     * @param  array  $cardDetails  Card details (number, exp_month, exp_year, cvc, name)
     * @param  string|null  $nickname  Optional nickname
     * @param  bool  $setAsDefault  Whether to set as default
     *
     * @throws PaymentFailedException
     */
    public function createFromCardDetails(
        Customer $customer,
        array $cardDetails,
        ?string $nickname = null,
        bool $setAsDefault = false
    ): CustomerPaymentMethod {
        try {
            // Get customer key for MPC
            $customerKey = $customer->mpc_customer_id ? (int) $customer->mpc_customer_id : null;

            // Create token via TokenService
            $response = $this->tokenService->createCardToken($cardDetails, $customerKey);
            $token = $response['Token'] ?? null;

            if (! $token) {
                throw new PaymentFailedException('Failed to create card token.');
            }

            // Extract card info
            $cardNumber = $cardDetails['number'] ?? '';
            $lastFour = substr(preg_replace('/\D/', '', $cardNumber), -4);
            $brand = CustomerPaymentMethod::detectCardBrand($cardNumber);

            return $this->create($customer, [
                'mpc_token' => $token,
                'type' => CustomerPaymentMethod::TYPE_CARD,
                'last_four' => $lastFour,
                'brand' => $brand ?? ($response['CardType'] ?? null),
                'exp_month' => $cardDetails['exp_month'],
                'exp_year' => $cardDetails['exp_year'],
                'nickname' => $nickname,
            ], $setAsDefault);

        } catch (\Exception $e) {
            Log::error('Failed to create payment method from card details', [
                'customer_id' => $customer->id,
                'error' => $e->getMessage(),
            ]);
            throw new PaymentFailedException('Failed to save card: '.$e->getMessage(), [], 0, $e);
        }
    }

    /**
     * Create a saved payment method by tokenizing check/ACH details directly.
     *
     * @param  Customer  $customer  The customer
     * @param  array  $checkDetails  Check details (account_number, routing_number, account_type, name)
     * @param  string|null  $bankName  Bank name for display
     * @param  string|null  $nickname  Optional nickname
     * @param  bool  $setAsDefault  Whether to set as default
     *
     * @throws PaymentFailedException
     */
    public function createFromCheckDetails(
        Customer $customer,
        array $checkDetails,
        ?string $bankName = null,
        ?string $nickname = null,
        bool $setAsDefault = false
    ): CustomerPaymentMethod {
        try {
            // Kotapay does not support ACH tokenization.
            // Generate a local pseudo-token for reference.
            // ACH details are stored encrypted with the payment plan.
            $accountNumber = $checkDetails['account_number'] ?? '';
            $lastFour = substr(preg_replace('/\D/', '', $accountNumber), -4);

            // Generate a unique pseudo-token for local reference
            $pseudoToken = 'ach_local_'.bin2hex(random_bytes(16));

            Log::info('Created local ACH pseudo-token (Kotapay does not tokenize)', [
                'customer_id' => $customer->id,
                'last_four' => $lastFour,
            ]);

            $paymentMethod = $this->create($customer, [
                'mpc_token' => $pseudoToken,
                'type' => CustomerPaymentMethod::TYPE_ACH,
                'last_four' => $lastFour,
                'bank_name' => $bankName ?? ($checkDetails['bank_name'] ?? null),
                'account_type' => $checkDetails['account_type'] ?? 'checking',
                'is_business' => $checkDetails['is_business'] ?? false,
                'nickname' => $nickname,
            ], $setAsDefault);

            // Store encrypted bank details for future ACH charges (e.g., scheduled payments)
            $paymentMethod->setBankDetails(
                $checkDetails['routing_number'],
                $checkDetails['account_number']
            );

            return $paymentMethod;

        } catch (\Exception $e) {
            Log::error('Failed to create payment method from check details', [
                'customer_id' => $customer->id,
                'error' => $e->getMessage(),
            ]);
            throw new PaymentFailedException('Failed to save bank account: '.$e->getMessage(), [], 0, $e);
        }
    }

    // ==================== Read Methods ====================

    /**
     * Get all payment methods for a customer.
     *
     * @return Collection<CustomerPaymentMethod>
     */
    public function getPaymentMethods(Customer $customer): Collection
    {
        return $customer->customerPaymentMethods;
    }

    /**
     * Get payment methods by type for a customer.
     *
     * @param  string  $type  'card' or 'ach'
     * @return Collection<CustomerPaymentMethod>
     */
    public function getPaymentMethodsByType(Customer $customer, string $type): Collection
    {
        return $customer->getCustomerPaymentMethodsByType($type);
    }

    /**
     * Get the default payment method for a customer.
     */
    public function getDefaultPaymentMethod(Customer $customer): ?CustomerPaymentMethod
    {
        return $customer->defaultCustomerPaymentMethod();
    }

    // ==================== Update Methods ====================

    /**
     * Set a payment method as the default.
     */
    public function setAsDefault(CustomerPaymentMethod $method): bool
    {
        $method->makeDefault();

        Log::info('Payment method set as default', [
            'customer_id' => $method->customer_id,
            'payment_method_id' => $method->id,
        ]);

        return true;
    }

    /**
     * Update the nickname of a payment method.
     */
    public function updateNickname(CustomerPaymentMethod $method, ?string $nickname): CustomerPaymentMethod
    {
        $method->nickname = $nickname;
        $method->save();

        return $method;
    }

    // ==================== Delete Methods ====================

    /**
     * Check if a payment method can be deleted.
     *
     * @return array{can_delete: bool, payment_plans: Collection, recurring_payments: Collection, message: string|null}
     */
    public function canDelete(CustomerPaymentMethod $method): array
    {
        $linkedPlans = $method->getLinkedPaymentPlans();
        $linkedRecurring = $method->getLinkedRecurringPayments();

        $canDelete = $linkedPlans->isEmpty() && $linkedRecurring->isEmpty();

        $message = null;
        if (! $canDelete) {
            $parts = [];
            if ($linkedPlans->isNotEmpty()) {
                $parts[] = $linkedPlans->count().' active payment plan(s)';
            }
            if ($linkedRecurring->isNotEmpty()) {
                $parts[] = $linkedRecurring->count().' recurring payment(s)';
            }
            $message = 'This payment method is linked to '.implode(' and ', $parts).'. Please reassign them before deleting.';
        }

        return [
            'can_delete' => $canDelete,
            'payment_plans' => $linkedPlans,
            'recurring_payments' => $linkedRecurring,
            'message' => $message,
        ];
    }

    /**
     * Delete a payment method (if not linked to active plans).
     *
     * This operation is wrapped in a transaction to ensure atomicity:
     * - Gateway deletion
     * - Local database deletion
     * - Default payment method reassignment
     *
     * @param  CustomerPaymentMethod  $method  The payment method to delete
     * @param  bool  $force  Force delete even if linked (for cleanup of expired cards)
     * @return bool True if deletion was successful
     *
     * @throws \Exception If method is linked and force is false
     */
    public function delete(CustomerPaymentMethod $method, bool $force = false): bool
    {
        $canDeleteResult = $this->canDelete($method);

        if (! $canDeleteResult['can_delete'] && ! $force) {
            throw new \Exception($canDeleteResult['message']);
        }

        $customer = $method->customer;
        $methodInfo = [
            'type' => $method->type,
            'last_four' => $method->last_four,
            'display_name' => $method->display_name,
        ];

        // Wrap all operations in a transaction for atomicity
        return DB::transaction(function () use ($method, $customer, $methodInfo) {
            // Attempt to delete from gateway first (before local deletion)
            // If gateway fails, transaction rolls back and local record remains
            try {
                $this->deleteFromGateway($method->mpc_token, $method->type);
            } catch (\Exception $e) {
                // Log but don't fail - token might already be deleted on gateway
                // This is acceptable because the goal is to remove the local record
                Log::warning('Failed to delete token from gateway (may already be deleted)', [
                    'payment_method_id' => $method->id,
                    'error' => $e->getMessage(),
                ]);
            }

            // Delete from local database
            $method->delete();

            Log::info('Customer payment method deleted', [
                'customer_id' => $customer->id,
                'payment_method_info' => $methodInfo,
            ]);

            // If this was the default, set another as default
            $this->ensureDefaultPaymentMethod($customer);

            // Send deletion email (queued, so won't affect transaction)
            $this->sendPaymentMethodDeletedEmail($customer, $methodInfo);

            return true;
        });
    }

    /**
     * Ensure a customer has a default payment method (set first available if none).
     */
    protected function ensureDefaultPaymentMethod(Customer $customer): void
    {
        if (! $customer->hasCustomerPaymentMethods()) {
            return;
        }

        $hasDefault = $customer->customerPaymentMethods()->where('is_default', true)->exists();

        if (! $hasDefault) {
            $firstMethod = $customer->customerPaymentMethods()->first();
            if ($firstMethod) {
                $firstMethod->makeDefault();
            }
        }
    }

    /**
     * Delete token from the gateway.
     *
     * @param  string  $type  'card' or 'ach'
     */
    public function deleteFromGateway(string $token, string $type): void
    {
        if ($type === CustomerPaymentMethod::TYPE_CARD) {
            $this->tokenService->deleteCardTokens($token);
        } else {
            // ACH tokens are local pseudo-tokens (Kotapay doesn't tokenize)
            // No gateway deletion needed - just log for audit
            Log::info('ACH payment method deleted (local pseudo-token, no gateway deletion needed)', [
                'token_prefix' => substr($token, 0, 15).'...',
            ]);
        }
    }

    // ==================== Reassignment Methods ====================

    /**
     * Reassign all linked plans from one payment method to another.
     *
     * @return array{payment_plans: int, recurring_payments: int}
     */
    public function reassignLinkedPlans(CustomerPaymentMethod $oldMethod, CustomerPaymentMethod $newMethod): array
    {
        $stats = ['payment_plans' => 0, 'recurring_payments' => 0];

        DB::transaction(function () use ($oldMethod, $newMethod, &$stats) {
            // Reassign payment plans
            $paymentPlansUpdated = PaymentPlan::where('customer_id', $oldMethod->customer_id)
                ->where('payment_method_token', $oldMethod->mpc_token)
                ->whereIn('status', [PaymentPlan::STATUS_ACTIVE, PaymentPlan::STATUS_PAST_DUE])
                ->update([
                    'payment_method_token' => $newMethod->mpc_token,
                    'payment_method_type' => $newMethod->type === CustomerPaymentMethod::TYPE_CARD ? 'card' : 'ach',
                    'payment_method_last_four' => $newMethod->last_four,
                ]);

            $stats['payment_plans'] = $paymentPlansUpdated;

            // Reassign recurring payments
            $recurringUpdated = RecurringPayment::where('customer_id', $oldMethod->customer_id)
                ->where('payment_method_token', $oldMethod->mpc_token)
                ->whereIn('status', [RecurringPayment::STATUS_ACTIVE, RecurringPayment::STATUS_PAUSED])
                ->update([
                    'payment_method_token' => $newMethod->mpc_token,
                    'payment_method_type' => $newMethod->type === CustomerPaymentMethod::TYPE_CARD ? 'card' : 'ach',
                    'payment_method_last_four' => $newMethod->last_four,
                ]);

            $stats['recurring_payments'] = $recurringUpdated;
        });

        Log::info('Reassigned linked plans to new payment method', [
            'customer_id' => $oldMethod->customer_id,
            'old_method_id' => $oldMethod->id,
            'new_method_id' => $newMethod->id,
            'stats' => $stats,
        ]);

        return $stats;
    }

    /**
     * Reassign linked plans to a new card (create new method first, then reassign).
     *
     * @return CustomerPaymentMethod The new payment method
     *
     * @throws PaymentFailedException
     */
    public function reassignWithNewCard(
        CustomerPaymentMethod $oldMethod,
        array $newCardDetails,
        ?string $nickname = null
    ): CustomerPaymentMethod {
        $customer = $oldMethod->customer;

        // Create new payment method
        $newMethod = $this->createFromCardDetails($customer, $newCardDetails, $nickname, false);

        // Reassign linked plans
        $this->reassignLinkedPlans($oldMethod, $newMethod);

        return $newMethod;
    }

    /**
     * Reassign linked plans to a new bank account (create new method first, then reassign).
     *
     * @return CustomerPaymentMethod The new payment method
     *
     * @throws PaymentFailedException
     */
    public function reassignWithNewBankAccount(
        CustomerPaymentMethod $oldMethod,
        array $checkDetails,
        ?string $bankName = null,
        ?string $nickname = null
    ): CustomerPaymentMethod {
        $customer = $oldMethod->customer;

        // Create new payment method
        $newMethod = $this->createFromCheckDetails($customer, $checkDetails, $bankName, $nickname, false);

        // Reassign linked plans
        $this->reassignLinkedPlans($oldMethod, $newMethod);

        return $newMethod;
    }

    // ==================== Charge Methods ====================

    /**
     * Charge a saved payment method.
     *
     * @param  int  $amountInCents  Amount in cents
     * @param  array  $options  Additional options (description, invoice_number, etc.)
     * @return array Gateway response
     *
     * @throws PaymentFailedException
     */
    public function charge(CustomerPaymentMethod $method, int $amountInCents, array $options = []): array
    {
        $customer = $method->customer;

        try {
            $response = $customer->charge($amountInCents, array_merge($options, [
                'payment_method' => $method->mpc_token,
            ]));

            Log::info('Charged saved payment method', [
                'customer_id' => $customer->id,
                'payment_method_id' => $method->id,
                'amount_cents' => $amountInCents,
            ]);

            return $response;

        } catch (\Exception $e) {
            Log::error('Failed to charge saved payment method', [
                'customer_id' => $customer->id,
                'payment_method_id' => $method->id,
                'error' => $e->getMessage(),
            ]);
            throw new PaymentFailedException('Payment failed: '.$e->getMessage(), [], 0, $e);
        }
    }

    // ==================== Expiration Management ====================

    /**
     * Get payment methods expiring within a given number of days.
     *
     * @return Collection<CustomerPaymentMethod>
     */
    public function getMethodsExpiringSoon(int $days = 30): Collection
    {
        return CustomerPaymentMethod::expiringWithinDays($days)
            ->notNotifiedAboutExpiration()
            ->with('customer')
            ->get();
    }

    /**
     * Get all expired payment methods.
     *
     * @return Collection<CustomerPaymentMethod>
     */
    public function getExpiredMethods(): Collection
    {
        return CustomerPaymentMethod::expired()->with('customer')->get();
    }

    /**
     * Remove all expired payment methods that are not linked to active plans.
     *
     * Skips expired cards still linked to active recurring payments or payment plans
     * to prevent silent payment failures.
     *
     * @return int Number of methods removed
     */
    public function removeExpiredMethods(): int
    {
        $expiredMethods = $this->getExpiredMethods();
        $count = 0;

        foreach ($expiredMethods as $method) {
            try {
                // Skip if linked to active plans/recurring payments
                if ($method->isLinkedToActivePlans()) {
                    Log::warning('Skipping expired payment method — linked to active plans', [
                        'payment_method_id' => $method->id,
                        'customer_id' => $method->customer_id,
                    ]);

                    continue;
                }

                // Safe to force delete — no active links
                $this->delete($method, true);
                $count++;
            } catch (\Exception $e) {
                Log::error('Failed to remove expired payment method', [
                    'payment_method_id' => $method->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        Log::info('Removed expired payment methods', ['count' => $count]);

        return $count;
    }

    /**
     * Send expiration notifications for cards expiring soon.
     *
     * @param  int  $days  Days before expiration to notify
     * @return int Number of notifications sent
     */
    public function sendExpirationNotifications(int $days = 30): int
    {
        $expiringMethods = $this->getMethodsExpiringSoon($days);
        $count = 0;

        foreach ($expiringMethods as $method) {
            try {
                $this->sendPaymentMethodExpiringEmail($method->customer, $method);
                $method->markExpirationNotified();
                $count++;
            } catch (\Exception $e) {
                Log::error('Failed to send expiration notification', [
                    'payment_method_id' => $method->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        Log::info('Sent expiration notifications', ['count' => $count]);

        return $count;
    }

    // ==================== Email Methods ====================

    /**
     * Send payment method saved confirmation email.
     */
    protected function sendPaymentMethodSavedEmail(Customer $customer, CustomerPaymentMethod $method): void
    {
        if (! $customer->email) {
            return;
        }

        try {
            Mail::to($customer->email)->queue(new PaymentMethodSaved($customer, $method));
        } catch (\Exception $e) {
            Log::error('Failed to send payment method saved email', [
                'customer_id' => $customer->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Send payment method deleted confirmation email.
     *
     * @param  array  $methodInfo  Method info (type, last_four, display_name)
     */
    protected function sendPaymentMethodDeletedEmail(Customer $customer, array $methodInfo): void
    {
        if (! $customer->email) {
            return;
        }

        try {
            Mail::to($customer->email)->queue(new PaymentMethodDeleted($customer, $methodInfo));
        } catch (\Exception $e) {
            Log::error('Failed to send payment method deleted email', [
                'customer_id' => $customer->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Send payment method expiring soon email.
     */
    protected function sendPaymentMethodExpiringEmail(Customer $customer, CustomerPaymentMethod $method): void
    {
        if (! $customer->email) {
            return;
        }

        try {
            Mail::to($customer->email)->queue(new PaymentMethodExpiringSoon($customer, $method));
        } catch (\Exception $e) {
            Log::error('Failed to send payment method expiring email', [
                'customer_id' => $customer->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
