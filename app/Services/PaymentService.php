<?php

// app/Services/PaymentService.php

namespace App\Services;

use App\Models\Customer;
use App\Models\Payment;
use App\Models\PaymentPlan;
use MiPaymentChoice\Cashier\Services\QuickPaymentsService;
use MiPaymentChoice\Cashier\Services\TokenService;
use MiPaymentChoice\Cashier\Exceptions\PaymentFailedException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;

/**
 * PaymentService
 *
 * Handles payment processing with MiPaymentChoice Gateway
 * 
 * ⚠️ NOTE: This service uses MiPaymentChoice Cashier package, NOT Stripe!
 */
class PaymentService
{
    protected QuickPaymentsService $quickPayments;
    protected TokenService $tokenService;

    public function __construct(
        QuickPaymentsService $quickPayments,
        TokenService $tokenService
    ) {
        $this->quickPayments = $quickPayments;
        $this->tokenService = $tokenService;
    }

    /**
     * Get or create a Customer for payment processing
     *
     * @param array $clientInfo
     * @return Customer
     */
    public function getOrCreateCustomer(array $clientInfo): Customer
    {
        // Try to find existing customer by client_key from SQL Server
        $customer = Customer::where('client_key', $clientInfo['client_KEY'])->first();

        if (!$customer) {
            // Create new customer in SQLite database
            $customer = Customer::create([
                'name' => $clientInfo['client_name'] ?? $clientInfo['description'] ?? 'Unknown',
                'email' => $clientInfo['email'] ?? null,
                'client_id' => $clientInfo['client_id'] ?? null,
                'client_key' => $clientInfo['client_KEY'],
            ]);

            Log::info('Created new customer', [
                'customer_id' => $customer->id,
                'client_key' => $clientInfo['client_KEY'],
            ]);
        }

        return $customer;
    }

    /**
     * Create a QuickPayments token for one-time payment (Credit Card)
     *
     * @param array $paymentData
     * @param array $clientInfo
     * @return array
     */
    public function createPaymentIntent(array $paymentData, array $clientInfo): array
    {
        try {
            Log::info('createPaymentIntent called', ['client_info' => $clientInfo, 'paymentData' => $paymentData]);
            
            $customer = $this->getOrCreateCustomer($clientInfo);
            
            Log::info('Customer retrieved/created', ['customer_id' => $customer->id]);
            
            $amount = $paymentData['amount'];
            $fee = $paymentData['fee'] ?? 0;
            $totalAmount = $amount + $fee;

            // For QuickPayments, we return intent data for frontend to collect card details
            // The actual token creation happens on the frontend with card data
            $result = [
                'success' => true,
                'customer_id' => $customer->id,
                'amount' => $totalAmount,
                'type' => 'quick_payments',
                'message' => 'Ready for payment. Collect card details on frontend.',
            ];
            
            Log::info('createPaymentIntent success', $result);
            
            return $result;

        } catch (\Exception $e) {
            Log::error('Failed to create payment intent', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'client_info' => $clientInfo,
                'amount' => $paymentData['amount'] ?? null,
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Create a QuickPayments token for one-time payment (ACH/Check)
     *
     * @param array $clientInfo
     * @return array
     */
    public function createSetupIntent(array $clientInfo): array
    {
        try {
            $customer = $this->getOrCreateCustomer($clientInfo);

            // For ACH/Check, return setup intent for frontend to collect bank details
            return [
                'success' => true,
                'customer_id' => $customer->id,
                'type' => 'ach_setup',
                'message' => 'Ready for ACH setup. Collect bank details on frontend.',
            ];

        } catch (\Exception $e) {
            Log::error('Failed to create setup intent', [
                'error' => $e->getMessage(),
                'client_info' => $clientInfo,
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Process a one-time payment using QuickPayments token
     *
     * @param Customer $customer
     * @param string $qpToken QuickPayments token from frontend
     * @param float $amount
     * @param array $options
     * @return array
     */
    public function chargeWithQuickPayments(Customer $customer, string $qpToken, float $amount, array $options = []): array
    {
        try {
            // Convert dollars to cents for MiPaymentChoice
            $amountInCents = (int)($amount * 100);

            $response = $customer->chargeWithQuickPayments($qpToken, $amountInCents, array_merge([
                'description' => $options['description'] ?? 'Payment',
                'currency' => 'USD',
            ], $options));

            Log::info('QuickPayments charge successful', [
                'customer_id' => $customer->id,
                'amount' => $amount,
                'response' => $response,
            ]);

            return [
                'success' => true,
                'transaction_id' => $response['PnRef'] ?? $response['TransactionId'] ?? uniqid('txn_'),
                'amount' => $amount,
                'status' => 'completed',
                'response' => $response,
            ];

        } catch (PaymentFailedException $e) {
            Log::error('QuickPayments charge failed', [
                'customer_id' => $customer->id,
                'amount' => $amount,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Setup a payment plan with saved payment method
     *
     * @param array $paymentData
     * @param array $clientInfo
     * @param string $token Card or Check token
     * @return array
     */
    public function setupPaymentPlan(array $paymentData, array $clientInfo, string $token): array
    {
        try {
            $customer = $this->getOrCreateCustomer($clientInfo);

            // Add the payment method to customer (save the token)
            $paymentMethod = $customer->addPaymentMethod($token, [
                'default' => true,
                'type' => $paymentData['payment_type'] ?? 'card',
            ]);

            Log::info('Payment plan setup successful', [
                'customer_id' => $customer->id,
                'payment_method_id' => $paymentMethod->id,
            ]);

            // Process down payment if any
            $result = [
                'success' => true,
                'customer_id' => $customer->id,
                'payment_method_id' => $paymentMethod->id,
            ];

            if (($paymentData['downPayment'] ?? 0) > 0) {
                $downPaymentResult = $this->processPayment(
                    $customer,
                    $paymentData['downPayment'],
                    $paymentMethod->mpc_token,
                    "Down payment for payment plan - {$clientInfo['client_name']}",
                    [
                        'payment_type' => 'down_payment',
                        'plan_id' => $paymentData['planId'] ?? null,
                    ]
                );

                if (!$downPaymentResult['success']) {
                    return $downPaymentResult;
                }

                $result['down_payment'] = $downPaymentResult;
            }

            return $result;

        } catch (\Exception $e) {
            Log::error('Failed to setup payment plan', [
                'error' => $e->getMessage(),
                'client_info' => $clientInfo,
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Process a payment using saved payment method token
     *
     * @param Customer $customer
     * @param float $amount
     * @param string $token
     * @param string $description
     * @param array $metadata
     * @return array
     */
    public function processPayment(Customer $customer, float $amount, string $token, string $description = '', array $metadata = []): array
    {
        try {
            // Convert dollars to cents
            $amountInCents = (int)($amount * 100);

            $response = $customer->charge($amountInCents, [
                'description' => $description,
                'payment_method' => $token,
                'metadata' => $metadata,
            ]);

            Log::info('Payment processing successful', [
                'customer_id' => $customer->id,
                'amount' => $amount,
                'response' => $response,
            ]);

            return [
                'success' => true,
                'transaction_id' => $response['PnRef'] ?? $response['TransactionId'] ?? uniqid('txn_'),
                'amount' => $amount,
                'status' => 'completed',
            ];

        } catch (PaymentFailedException $e) {
            Log::error('Payment processing failed', [
                'customer_id' => $customer->id,
                'amount' => $amount,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Handle MiPaymentChoice webhook
     *
     * @param array $payload
     * @return void
     */
    public function handleWebhook(array $payload): void
    {
        Log::info('MiPaymentChoice webhook received', [
            'payload' => $payload,
        ]);

        // Handle webhook events as needed
        // MiPaymentChoice may send notifications for transaction status updates
    }

    /**
     * Create a new payment plan and schedule all payments.
     *
     * @param array $planData Payment plan configuration
     * @param array $clientInfo Client information from PracticeCS
     * @param string $paymentMethodToken Saved payment method token
     * @param string $paymentMethodType 'card' or 'ach'
     * @param string|null $lastFour Last 4 digits of card/account
     * @return array Result with success status and plan details
     */
    public function createPaymentPlan(
        array $planData,
        array $clientInfo,
        string $paymentMethodToken,
        string $paymentMethodType,
        ?string $lastFour = null
    ): array {
        DB::beginTransaction();

        try {
            // Get or create customer
            $customer = $this->getOrCreateCustomer($clientInfo);

            // Calculate plan amounts
            $invoiceAmount = (float) $planData['amount'];
            $planFee = (float) $planData['planFee'];
            $totalAmount = $invoiceAmount + $planFee;
            $durationMonths = (int) $planData['planDuration'];
            $monthlyPayment = round($totalAmount / $durationMonths, 2);

            // Generate unique plan ID
            $planId = PaymentPlan::generatePlanId();

            // Create the payment plan record
            $paymentPlan = PaymentPlan::create([
                'customer_id' => $customer->id,
                'client_key' => $clientInfo['client_KEY'],
                'plan_id' => $planId,
                'invoice_amount' => $invoiceAmount,
                'plan_fee' => $planFee,
                'total_amount' => $totalAmount,
                'monthly_payment' => $monthlyPayment,
                'duration_months' => $durationMonths,
                'payment_method_token' => $paymentMethodToken,
                'payment_method_type' => $paymentMethodType,
                'payment_method_last_four' => $lastFour,
                'status' => PaymentPlan::STATUS_ACTIVE,
                'payments_completed' => 0,
                'payments_failed' => 0,
                'amount_paid' => 0,
                'amount_remaining' => $totalAmount,
                'start_date' => now(),
                'next_payment_date' => now()->addMonth(),
                'invoice_references' => $planData['invoices'] ?? [],
                'metadata' => [
                    'payment_schedule' => $planData['paymentSchedule'] ?? [],
                    'client_name' => $clientInfo['client_name'] ?? null,
                    'created_at' => now()->toIso8601String(),
                ],
            ]);

            // Create scheduled payment records for visibility
            $paymentPlan->createScheduledPayments();

            DB::commit();

            Log::info('Payment plan created successfully', [
                'plan_id' => $planId,
                'customer_id' => $customer->id,
                'client_key' => $clientInfo['client_KEY'],
                'total_amount' => $totalAmount,
                'duration_months' => $durationMonths,
                'monthly_payment' => $monthlyPayment,
            ]);

            return [
                'success' => true,
                'plan_id' => $planId,
                'payment_plan' => $paymentPlan,
                'customer_id' => $customer->id,
                'monthly_payment' => $monthlyPayment,
                'next_payment_date' => $paymentPlan->next_payment_date->format('Y-m-d'),
            ];

        } catch (\Exception $e) {
            DB::rollBack();

            Log::error('Failed to create payment plan', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'client_info' => $clientInfo,
            ]);

            return [
                'success' => false,
                'error' => 'Failed to create payment plan: ' . $e->getMessage(),
            ];
        }
    }

    /**
     * Record a one-time payment in the database.
     *
     * @param array $paymentData Payment details
     * @param array $clientInfo Client information
     * @param string $transactionId MiPaymentChoice transaction ID
     * @return Payment
     */
    public function recordPayment(array $paymentData, array $clientInfo, string $transactionId): Payment
    {
        $customer = $this->getOrCreateCustomer($clientInfo);

        return Payment::create([
            'customer_id' => $customer->id,
            'client_key' => $clientInfo['client_KEY'],
            'transaction_id' => $transactionId,
            'amount' => $paymentData['amount'],
            'fee' => $paymentData['fee'] ?? 0,
            'total_amount' => ($paymentData['amount'] + ($paymentData['fee'] ?? 0)),
            'payment_method' => $paymentData['paymentMethod'],
            'payment_method_last_four' => $paymentData['lastFour'] ?? null,
            'status' => Payment::STATUS_COMPLETED,
            'description' => $paymentData['description'] ?? null,
            'metadata' => [
                'invoices' => $paymentData['invoices'] ?? [],
                'client_name' => $clientInfo['client_name'] ?? null,
            ],
            'processed_at' => now(),
        ]);
    }

    /**
     * Get a payment plan by plan ID.
     *
     * @param string $planId
     * @return PaymentPlan|null
     */
    public function getPaymentPlan(string $planId): ?PaymentPlan
    {
        return PaymentPlan::where('plan_id', $planId)->first();
    }

    /**
     * Cancel a payment plan.
     *
     * @param string $planId
     * @param string|null $reason
     * @return bool
     */
    public function cancelPaymentPlan(string $planId, ?string $reason = null): bool
    {
        $plan = $this->getPaymentPlan($planId);

        if (!$plan || !$plan->isActive()) {
            return false;
        }

        $plan->cancel($reason);

        Log::info('Payment plan cancelled', [
            'plan_id' => $planId,
            'reason' => $reason,
        ]);

        return true;
    }
}
