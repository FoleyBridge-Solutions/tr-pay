<?php

// app/Services/PaymentService.php

namespace App\Services;

use App\Models\Customer;
use MiPaymentChoice\Cashier\Services\QuickPaymentsService;
use MiPaymentChoice\Cashier\Services\TokenService;
use MiPaymentChoice\Cashier\Exceptions\PaymentFailedException;
use Illuminate\Support\Facades\Log;

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
}
