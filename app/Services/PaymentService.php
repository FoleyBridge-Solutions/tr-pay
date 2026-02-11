<?php

// app/Services/PaymentService.php

namespace App\Services;

use App\Models\Customer;
use App\Models\Payment;
use App\Models\PaymentPlan;
use App\Support\Money;
use FoleyBridgeSolutions\KotapayCashier\Services\PaymentService as KotapayPaymentService;
use FoleyBridgeSolutions\MiPaymentChoiceCashier\Exceptions\PaymentFailedException;
use FoleyBridgeSolutions\MiPaymentChoiceCashier\Services\QuickPaymentsService;
use FoleyBridgeSolutions\MiPaymentChoiceCashier\Services\TokenService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * PaymentService
 *
 * Handles payment processing with:
 * - MiPaymentChoice Gateway for credit/debit cards
 * - Kotapay API for ACH payments
 *
 * NOTE: Card payments use MiPaymentChoice. ACH payments use Kotapay.
 */
class PaymentService
{
    // ==================== Payment Type Constants ====================

    /**
     * Payment type: Credit/Debit Card
     */
    public const TYPE_CARD = 'card';

    /**
     * Payment type: ACH/Bank Transfer
     */
    public const TYPE_ACH = 'ach';

    /**
     * Payment type: Check
     */
    public const TYPE_CHECK = 'check';

    // ==================== Payment Status Constants ====================

    /**
     * Payment status: Completed successfully
     */
    public const STATUS_COMPLETED = 'completed';

    /**
     * Payment status: Pending (e.g., ACH awaiting settlement)
     */
    public const STATUS_PENDING = 'pending';

    /**
     * Payment status: Failed
     */
    public const STATUS_FAILED = 'failed';

    // ==================== Dependencies ====================

    protected QuickPaymentsService $quickPayments;

    protected TokenService $tokenService;

    protected ?KotapayPaymentService $kotapayService = null;

    public function __construct(
        QuickPaymentsService $quickPayments,
        TokenService $tokenService
    ) {
        $this->quickPayments = $quickPayments;
        $this->tokenService = $tokenService;
    }

    /**
     * Get the Kotapay API service (lazy-loaded).
     */
    protected function getKotapayService(): KotapayPaymentService
    {
        if ($this->kotapayService === null) {
            $this->kotapayService = app(KotapayPaymentService::class);
        }

        return $this->kotapayService;
    }

    /**
     * Check if Kotapay API is enabled for ACH processing.
     */
    public function isKotapayEnabled(): bool
    {
        return config('kotapay.enabled', false);
    }

    /**
     * Get or create a Customer for payment processing
     */
    public function getOrCreateCustomer(array $clientInfo): Customer
    {
        // Try to find existing customer by client_id (the human-readable identifier)
        $clientId = $clientInfo['client_id'] ?? null;
        $customer = $clientId ? Customer::where('client_id', $clientId)->first() : null;

        if (! $customer) {
            // Create new customer in SQLite database
            $customer = Customer::create([
                'name' => $clientInfo['client_name'] ?? $clientInfo['description'] ?? 'Unknown',
                'email' => $clientInfo['email'] ?? null,
                'client_id' => $clientId,
                'client_key' => $clientInfo['client_KEY'] ?? null,
            ]);

            Log::info('Created new customer', [
                'customer_id' => $customer->id,
                'client_id' => $clientId,
            ]);
        }

        return $customer;
    }

    /**
     * Create a QuickPayments token for one-time payment (Credit Card)
     *
     * @param  array  $paymentData  Payment data including 'amount' and optional 'fee'
     * @param  array  $clientInfo  Client information from PracticeCS
     * @return array Result array with success status and intent data
     */
    public function createPaymentIntent(array $paymentData, array $clientInfo): array
    {
        try {
            Log::info('createPaymentIntent called', ['client_info' => $clientInfo, 'paymentData' => $paymentData]);

            $customer = $this->getOrCreateCustomer($clientInfo);

            Log::info('Customer retrieved/created', ['customer_id' => $customer->id]);

            // Use Money class to safely add dollar amounts (avoids floating-point errors)
            $amount = Money::round($paymentData['amount'] ?? 0);
            $fee = Money::round($paymentData['fee'] ?? 0);
            $totalAmount = Money::addDollars($amount, $fee);

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
                'trace' => $e->getTraceAsString(),
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
     * @param  Customer  $customer  The customer to charge
     * @param  string  $qpToken  QuickPayments token from frontend
     * @param  float  $amount  Amount in dollars
     * @param  array  $options  Additional options (description, currency, etc.)
     * @return array Result array with success status and transaction details
     */
    public function chargeWithQuickPayments(Customer $customer, string $qpToken, float $amount, array $options = []): array
    {
        try {
            // Use Money class to convert dollars to cents safely (avoids floating-point errors)
            $amountInCents = Money::toCents($amount);

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
                'transaction_id' => $response['PnRef'] ?? $response['TransactionId'] ?? 'txn_'.bin2hex(random_bytes(16)),
                'amount' => $amount,
                'status' => self::STATUS_COMPLETED,
                'response' => $response,
            ];

        } catch (PaymentFailedException $e) {
            // Get full error response if available
            $errorResponse = method_exists($e, 'getResponse') ? $e->getResponse() : [];

            Log::error('QuickPayments charge failed', [
                'customer_id' => $customer->id,
                'amount' => $amount,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'response' => $errorResponse,
            ]);

            // Parse the error to provide a user-friendly message
            $userMessage = $this->parsePaymentError($e->getMessage(), $errorResponse);

            return [
                'success' => false,
                'error' => $userMessage,
                'technical_error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Charge an ACH/check payment using Kotapay.
     *
     * @param  Customer  $customer  The customer
     * @param  array  $achDetails  ACH details (routing_number, account_number, account_type, account_name)
     * @param  float  $amount  Amount in dollars
     * @param  array  $options  Additional options (description, effective_date)
     * @return array Result array with success status and transaction details
     */
    public function chargeAchWithKotapay(Customer $customer, array $achDetails, float $amount, array $options = []): array
    {
        try {
            if (! $this->isKotapayEnabled()) {
                throw new PaymentFailedException('ACH payments are not currently enabled.');
            }

            // Use Money class to convert dollars to cents safely
            $amountInCents = Money::toCents($amount);

            // Select application ID based on personal vs business account
            $isBusiness = $achDetails['is_business'] ?? false;
            $applicationId = $isBusiness
                ? config('kotapay.application_id.business')
                : config('kotapay.application_id.personal');

            // Use the AchBillable trait method on the customer
            $response = $customer->chargeAch([
                'routing_number' => $achDetails['routing_number'],
                'account_number' => $achDetails['account_number'],
                'account_type' => $achDetails['account_type'] ?? 'Checking',
                'account_name' => ! empty($achDetails['account_name']) ? $achDetails['account_name'] : $customer->name,
                'application_id' => $applicationId,
            ], $amountInCents, [
                'description' => $options['description'] ?? 'ACH Payment',
                'effective_date' => $options['effective_date'] ?? now()->format('Y-m-d'),
            ]);

            // Defense-in-depth: validate Kotapay response status
            $responseStatus = $response['status'] ?? null;
            if ($responseStatus === 'fail' || $responseStatus === 'error') {
                $errors = $response['data'] ?? $response['message'] ?? 'Unknown error';
                throw new PaymentFailedException(
                    'Kotapay rejected ACH payment: '.json_encode($errors)
                );
            }

            $transactionId = $response['data']['TransactionId']
                ?? $response['data']['transactionId']
                ?? $response['transaction_id']
                ?? null;

            if (empty($transactionId)) {
                Log::warning('Kotapay returned no transactionId', [
                    'customer_id' => $customer->id,
                    'response_status' => $responseStatus,
                ]);
                $transactionId = 'ach_'.bin2hex(random_bytes(16));
            }

            Log::info('Kotapay ACH charge successful', [
                'customer_id' => $customer->id,
                'amount' => $amount,
                'transaction_id' => $transactionId,
            ]);

            return [
                'success' => true,
                'transaction_id' => $transactionId,
                'amount' => $amount,
                'status' => self::STATUS_PENDING, // ACH payments are pending until settled
                'response' => $response,
                'payment_vendor' => 'kotapay',
            ];

        } catch (\Exception $e) {
            Log::error('Kotapay ACH charge failed', [
                'customer_id' => $customer->id,
                'amount' => $amount,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return [
                'success' => false,
                'error' => $this->parseAchPaymentError($e->getMessage()),
                'technical_error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Parse ACH payment error and return user-friendly message.
     */
    protected function parseAchPaymentError(string $error): string
    {
        $errorLower = strtolower($error);

        if (str_contains($errorLower, 'invalid routing')) {
            return 'Invalid routing number. Please check and try again.';
        }

        if (str_contains($errorLower, 'invalid account')) {
            return 'Invalid account number. Please check and try again.';
        }

        if (str_contains($errorLower, 'insufficient funds')) {
            return 'Insufficient funds. Please try a different account.';
        }

        if (str_contains($errorLower, 'account closed')) {
            return 'This account appears to be closed. Please use a different account.';
        }

        if (str_contains($errorLower, 'curl error') || str_contains($errorLower, 'timed out') || str_contains($errorLower, 'connection failed')) {
            return 'Payment gateway temporarily unavailable. Please wait a moment and try again.';
        }

        return 'ACH payment could not be processed. Please try again or use a different payment method.';
    }

    /**
     * Parse payment gateway error and return user-friendly message.
     */
    protected function parsePaymentError(string $error, array $response = []): string
    {
        // Check response for specific error codes/messages
        $responseMessage = $response['ResponseStatus']['Message'] ?? '';
        $responseCode = $response['ResponseCode'] ?? '';
        $errorLower = strtolower($error.' '.$responseMessage);

        // Check for common error patterns
        if (str_contains($errorLower, 'declined') || str_contains($errorLower, 'do not honor')) {
            return 'Your card was declined. Please try a different card or contact your bank.';
        }

        if (str_contains($errorLower, 'insufficient funds')) {
            return 'Insufficient funds. Please try a different payment method.';
        }

        if (str_contains($errorLower, 'expired')) {
            return 'Your card has expired. Please use a different card.';
        }

        if (str_contains($errorLower, 'invalid card') || str_contains($errorLower, 'invalid account')) {
            return 'Invalid card information. Please check your card details and try again.';
        }

        if (str_contains($errorLower, 'cvv') || str_contains($errorLower, 'cvc') || str_contains($errorLower, 'security code')) {
            return 'Invalid security code (CVV). Please check and try again.';
        }

        if (str_contains($errorLower, 'address') || str_contains($errorLower, 'avs')) {
            return 'Address verification failed. Please check your billing address.';
        }

        if (str_contains($errorLower, 'duplicate')) {
            return 'This appears to be a duplicate transaction. Please wait a moment before trying again.';
        }

        if (str_contains($errorLower, '400') || str_contains($errorLower, 'bad request')) {
            return 'Your payment could not be processed. Please verify your card information and try again.';
        }

        if (str_contains($errorLower, 'timeout') || str_contains($errorLower, 'connection')) {
            return 'Connection error. Please try again in a moment.';
        }

        // Default message
        return 'Your payment could not be processed. Please try again or use a different payment method.';
    }

    /**
     * Setup a payment plan with saved payment method
     *
     * @param  string  $token  Card or Check token
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

                if (! $downPaymentResult['success']) {
                    return $downPaymentResult;
                }

                $result['down_payment'] = $downPaymentResult;
            }

            return $result;

        } catch (\Exception $e) {
            Log::error('Failed to setup payment plan', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
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
     * @param  Customer  $customer  The customer to charge
     * @param  float  $amount  Amount in dollars
     * @param  string  $token  Payment method token
     * @param  string  $description  Payment description
     * @param  array  $metadata  Additional metadata to store with payment
     * @return array Result array with success status and transaction details
     */
    public function processPayment(Customer $customer, float $amount, string $token, string $description = '', array $metadata = []): array
    {
        try {
            // Use Money class to convert dollars to cents safely
            $amountInCents = Money::toCents($amount);

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
                'transaction_id' => $response['PnRef'] ?? $response['TransactionId'] ?? 'txn_'.bin2hex(random_bytes(16)),
                'amount' => $amount,
                'status' => self::STATUS_COMPLETED,
            ];

        } catch (PaymentFailedException $e) {
            Log::error('Payment processing failed', [
                'customer_id' => $customer->id,
                'amount' => $amount,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Handle MiPaymentChoice webhook
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
     * Process a recurring payment using stored payment method data.
     *
     * This method handles the full flow:
     * - For cards: Creates a QP token via MiPaymentChoice and charges it
     * - For ACH: Charges directly via Kotapay (no tokenization)
     *
     * @param  array  $paymentData  Decrypted payment method data (card/ACH details)
     * @param  float  $amount  Amount in dollars
     * @param  string  $description  Payment description
     * @param  Customer|null  $customer  Customer object (required for ACH payments)
     * @return array Result with success status and transaction_id
     */
    public function processRecurringCharge(array $paymentData, float $amount, string $description = '', ?Customer $customer = null): array
    {
        try {
            // Route ACH payments to Kotapay using constant for type comparison
            if (($paymentData['type'] ?? '') !== self::TYPE_CARD) {
                return $this->processRecurringAchCharge($paymentData, $amount, $description, $customer);
            }

            // Card payments continue to use MiPaymentChoice
            // Note: QuickPaymentsService::charge() expects amount in DOLLARS, not cents

            // Parse expiry — supports MM/YY (e.g. "06/28") and M/D/YYYY (e.g. "6/1/2028")
            $expParts = explode('/', $paymentData['expiry'] ?? '');

            if (count($expParts) === 3) {
                // M/D/YYYY format — month is first part, year is third part
                $expMonth = (int) $expParts[0];
                $expYear = (int) $expParts[2];
            } else {
                // MM/YY format (default) — month is first part, year is second part
                $expMonth = isset($expParts[0]) ? (int) $expParts[0] : 12;
                $rawYear = isset($expParts[1]) ? $expParts[1] : date('y');
                $expYear = strlen((string) $rawYear) <= 2 ? (int) ('20'.$rawYear) : (int) $rawYear;
            }

            $tokenResponse = $this->quickPayments->createQpToken([
                'number' => $paymentData['number'],
                'exp_month' => $expMonth,
                'exp_year' => $expYear,
                'cvc' => $paymentData['cvv'] ?? null,
                'name' => ! empty($paymentData['name']) ? $paymentData['name'] : null,
            ]);

            if (empty($tokenResponse['QuickPaymentsToken'])) {
                throw new \Exception('Failed to create payment token');
            }

            $qpToken = $tokenResponse['QuickPaymentsToken'];

            // Charge card using the QP token - amount is in DOLLARS (not cents)
            // Use Money::round() to ensure consistent precision
            $chargeResponse = $this->quickPayments->charge($qpToken, Money::round($amount), [
                'description' => $description,
                'currency' => 'USD',
            ]);

            // Check for successful response
            $transactionId = $chargeResponse['PnRef'] ?? $chargeResponse['TransactionId'] ?? null;

            if (! $transactionId) {
                $errorMessage = $chargeResponse['Message'] ?? $chargeResponse['ResponseMessage'] ?? 'Payment failed';
                throw new \Exception($errorMessage);
            }

            Log::info('Recurring charge processed successfully', [
                'transaction_id' => $transactionId,
                'amount' => $amount,
                'type' => $paymentData['type'] ?? self::TYPE_CARD,
            ]);

            return [
                'success' => true,
                'transaction_id' => $transactionId,
                'amount' => $amount,
                'response' => $chargeResponse,
            ];

        } catch (\Exception $e) {
            Log::error('Recurring charge failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'amount' => $amount,
                'type' => $paymentData['type'] ?? 'unknown',
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Process a recurring ACH payment via Kotapay.
     *
     * @param  array  $paymentData  ACH details (routing, account, account_type, name)
     * @param  float  $amount  Amount in dollars
     * @param  string  $description  Payment description
     * @param  Customer|null  $customer  Customer object (required for Kotapay)
     * @return array Result with success status and transaction_id
     *
     * @throws \Exception If ACH is disabled or customer is null
     */
    protected function processRecurringAchCharge(array $paymentData, float $amount, string $description, ?Customer $customer): array
    {
        // Validate prerequisites before any processing
        if (! $this->isKotapayEnabled()) {
            throw new \Exception('ACH payments are not currently enabled.');
        }

        if (! $customer) {
            throw new \Exception('Customer object is required for ACH payments.');
        }

        // Normalize account type to proper case
        $accountType = match (strtolower($paymentData['account_type'] ?? 'checking')) {
            'savings' => 'Savings',
            default => 'Checking',
        };

        // Use Money class to convert dollars to cents safely
        $amountInCents = Money::toCents($amount);

        // Select application ID based on personal vs business account
        $isBusiness = $paymentData['is_business'] ?? false;
        $applicationId = $isBusiness
            ? config('kotapay.application_id.business')
            : config('kotapay.application_id.personal');

        // Use the AchBillable trait on the customer to charge via Kotapay
        $response = $customer->chargeAch([
            'routing_number' => $paymentData['routing'],
            'account_number' => $paymentData['account'],
            'account_type' => $accountType,
            'account_name' => ! empty($paymentData['name']) ? $paymentData['name'] : $customer->name,
            'application_id' => $applicationId,
        ], $amountInCents, [
            'description' => $description,
            'effective_date' => now()->format('Y-m-d'),
        ]);

        // Defense-in-depth: validate Kotapay response status
        $responseStatus = $response['status'] ?? null;
        if ($responseStatus === 'fail' || $responseStatus === 'error') {
            $errors = $response['data'] ?? $response['message'] ?? 'Unknown error';
            throw new \Exception('Kotapay rejected recurring ACH payment: '.json_encode($errors));
        }

        $transactionId = $response['data']['TransactionId']
            ?? $response['data']['transactionId']
            ?? $response['transaction_id']
            ?? 'ach_'.bin2hex(random_bytes(16));

        Log::info('Recurring ACH charge processed via Kotapay', [
            'transaction_id' => $transactionId,
            'amount' => $amount,
            'customer_id' => $customer->id,
        ]);

        return [
            'success' => true,
            'transaction_id' => $transactionId,
            'amount' => $amount,
            'status' => self::STATUS_PENDING, // ACH payments are pending until settled
            'response' => $response,
            'payment_vendor' => 'kotapay',
        ];
    }

    /**
     * Create a new payment plan with required 30% down payment.
     *
     * The down payment is charged immediately. The remaining 70% is split
     * into equal monthly payments over the plan duration.
     *
     * @param  array  $planData  Payment plan configuration
     * @param  array  $clientInfo  Client information from PracticeCS
     * @param  string  $paymentMethodToken  Saved payment method token
     * @param  string  $paymentMethodType  'card' or 'ach'
     * @param  string|null  $lastFour  Last 4 digits of card/account
     * @param  array|null  $paymentMethodData  Raw payment data for charging (card/ACH details)
     * @param  bool  $splitDownPayment  Admin only: split down payment into two payments
     * @return array Result with success status and plan details
     */
    public function createPaymentPlan(
        array $planData,
        array $clientInfo,
        string $paymentMethodToken,
        string $paymentMethodType,
        ?string $lastFour = null,
        ?array $paymentMethodData = null,
        bool $splitDownPayment = false
    ): array {
        // Validate ACH is enabled BEFORE starting transaction to fail fast
        if ($paymentMethodType === self::TYPE_ACH && ! $this->isKotapayEnabled()) {
            return [
                'success' => false,
                'error' => 'ACH payments are not currently enabled.',
            ];
        }

        DB::beginTransaction();

        try {
            // Get or create customer
            $customer = $this->getOrCreateCustomer($clientInfo);

            // Use Money class for all currency calculations (avoids floating-point errors)
            $invoiceAmount = Money::round((float) $planData['amount']);
            $planFee = Money::round((float) $planData['planFee']);
            $totalAmount = Money::addDollars($invoiceAmount, $planFee);
            $durationMonths = (int) $planData['planDuration'];

            // Calculate down payment (custom or standard 30%)
            $customDownPayment = isset($planData['customDownPayment']) ? (float) $planData['customDownPayment'] : null;
            if ($customDownPayment !== null) {
                $downPayment = Money::round(max(0, min($customDownPayment, $totalAmount)));
            } else {
                $downPaymentPercent = PaymentPlanCalculator::DOWN_PAYMENT_PERCENT;
                $downPayment = Money::percentOf($totalAmount, $downPaymentPercent * 100);
            }
            $remainingBalance = Money::subtractDollars($totalAmount, $downPayment);

            // Calculate monthly payment - divide remaining by months, then round
            $monthlyPayment = $remainingBalance > 0 && $durationMonths > 0
                ? Money::round($remainingBalance / $durationMonths)
                : 0;

            // Generate unique plan ID
            $planId = PaymentPlan::generatePlanId();

            // Process down payment immediately (skip if $0)
            $downPaymentResult = ['success' => true, 'transaction_id' => null];
            if ($downPayment > 0) {
                $downPaymentResult = $this->processDownPayment(
                    $customer,
                    $downPayment,
                    $paymentMethodData,
                    $paymentMethodToken,
                    $planId,
                    $clientInfo['client_name'] ?? 'Unknown',
                    $splitDownPayment
                );

                if (! $downPaymentResult['success']) {
                    DB::rollBack();

                    return [
                        'success' => false,
                        'error' => 'Down payment failed: '.($downPaymentResult['error'] ?? 'Unknown error'),
                    ];
                }
            }

            // Create the payment plan record
            $paymentPlan = PaymentPlan::create([
                'customer_id' => $customer->id,
                'client_id' => $clientInfo['client_id'],
                'plan_id' => $planId,
                'invoice_amount' => $invoiceAmount,
                'plan_fee' => $planFee,
                'total_amount' => $totalAmount,
                'down_payment' => $downPayment,
                'monthly_payment' => $monthlyPayment,
                'duration_months' => $durationMonths,
                'payment_method_token' => $paymentMethodToken,
                'payment_method_type' => $paymentMethodType,
                'payment_method_last_four' => $lastFour,
                'status' => PaymentPlan::STATUS_ACTIVE,
                'payments_completed' => 0,
                'payments_failed' => 0,
                'amount_paid' => $downPayment,
                'amount_remaining' => $remainingBalance,
                'start_date' => now(),
                'next_payment_date' => now()->addMonth(),
                'invoice_references' => $planData['invoices'] ?? [],
                'metadata' => [
                    'payment_schedule' => $planData['paymentSchedule'] ?? [],
                    'client_name' => $clientInfo['client_name'] ?? null,
                    'down_payment_transaction_id' => $downPaymentResult['transaction_id'] ?? null,
                    'down_payment_split' => $splitDownPayment,
                    'custom_down_payment' => $customDownPayment !== null,
                    'fee_waived' => $planFee == 0,
                    'created_at' => now()->toIso8601String(),
                ],
            ]);

            // Record the down payment in payments table (skip if $0)
            if ($downPayment > 0) {
                $isAchDownPayment = $paymentMethodType === self::TYPE_ACH;
                $downPaymentDescription = $customDownPayment !== null
                    ? "Down payment for plan {$planId}"
                    : "Down payment (30%) for plan {$planId}";

                Payment::create([
                    'customer_id' => $customer->id,
                    'client_id' => $clientInfo['client_id'],
                    'payment_plan_id' => $paymentPlan->id,
                    'transaction_id' => $downPaymentResult['transaction_id'],
                    'amount' => $downPayment,
                    'fee' => 0,
                    'total_amount' => $downPayment,
                    'payment_method' => $paymentMethodType,
                    'payment_method_last_four' => $lastFour,
                    'status' => $isAchDownPayment ? Payment::STATUS_PROCESSING : Payment::STATUS_COMPLETED,
                    'is_automated' => false,
                    'description' => $downPaymentDescription,
                    'processed_at' => $isAchDownPayment ? null : now(),
                    'payment_vendor' => $isAchDownPayment ? ($downPaymentResult['payment_vendor'] ?? 'kotapay') : null,
                    'vendor_transaction_id' => $isAchDownPayment ? ($downPaymentResult['transaction_id'] ?? null) : null,
                ]);
            }

            // Create scheduled payment records for the remaining installments
            $paymentPlan->createScheduledPayments();

            DB::commit();

            Log::info('Payment plan created successfully with down payment', [
                'plan_id' => $planId,
                'customer_id' => $customer->id,
                'client_id' => $clientInfo['client_id'],
                'total_amount' => $totalAmount,
                'down_payment' => $downPayment,
                'remaining_balance' => $remainingBalance,
                'duration_months' => $durationMonths,
                'monthly_payment' => $monthlyPayment,
                'down_payment_transaction' => $downPaymentResult['transaction_id'],
            ]);

            return [
                'success' => true,
                'plan_id' => $planId,
                'payment_plan' => $paymentPlan,
                'customer_id' => $customer->id,
                'down_payment' => $downPayment,
                'down_payment_transaction_id' => $downPaymentResult['transaction_id'],
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
                'error' => 'Failed to create payment plan: '.$e->getMessage(),
            ];
        }
    }

    /**
     * Process the down payment for a payment plan.
     *
     * @param  Customer  $customer  The customer
     * @param  float  $amount  Down payment amount in dollars
     * @param  array|null  $paymentData  Raw payment method data (card/ACH details)
     * @param  string  $token  Payment method token
     * @param  string  $planId  Plan ID for description
     * @param  string  $clientName  Client name for description
     * @param  bool  $split  Whether this is a split payment (admin only)
     * @return array Result with success status and transaction_id
     */
    protected function processDownPayment(
        Customer $customer,
        float $amount,
        ?array $paymentData,
        string $token,
        string $planId,
        string $clientName,
        bool $split = false
    ): array {
        $description = $split
            ? "Down payment (split) for payment plan {$planId} - {$clientName}"
            : "Down payment (30%) for payment plan {$planId} - {$clientName}";

        // If we have raw payment data, use processRecurringCharge (which creates a token and charges)
        if ($paymentData && ! empty($paymentData['type'])) {
            // Pass customer for ACH payments (required by Kotapay)
            return $this->processRecurringCharge($paymentData, $amount, $description, $customer);
        }

        // Otherwise, try to charge using the existing token
        try {
            // Use Money class to convert dollars to cents safely
            $amountInCents = Money::toCents($amount);

            $response = $customer->charge($amountInCents, [
                'description' => $description,
                'payment_method' => $token,
            ]);

            return [
                'success' => true,
                'transaction_id' => $response['PnRef'] ?? $response['TransactionId'] ?? 'dp_'.bin2hex(random_bytes(16)),
                'amount' => $amount,
            ];

        } catch (PaymentFailedException $e) {
            Log::error('Down payment failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'amount' => $amount,
                'plan_id' => $planId,
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Record a one-time payment in the database.
     *
     * @param  array  $paymentData  Payment details (amount, fee, paymentMethod, etc.)
     * @param  array  $clientInfo  Client information from PracticeCS
     * @param  string  $transactionId  MiPaymentChoice transaction ID
     * @param  array  $vendorInfo  Optional vendor info for ACH (payment_vendor, vendor_transaction_id)
     * @return Payment The created payment record
     */
    public function recordPayment(array $paymentData, array $clientInfo, string $transactionId, array $vendorInfo = []): Payment
    {
        $customer = $this->getOrCreateCustomer($clientInfo);

        // Use Money class for safe currency calculations
        $amount = Money::round($paymentData['amount'] ?? 0);
        $fee = Money::round($paymentData['fee'] ?? 0);
        $totalAmount = Money::addDollars($amount, $fee);

        $isAch = ($paymentData['paymentMethod'] ?? '') === 'ach';

        return Payment::create([
            'customer_id' => $customer->id,
            'client_id' => $clientInfo['client_id'],
            'transaction_id' => $transactionId,
            'amount' => $amount,
            'fee' => $fee,
            'total_amount' => $totalAmount,
            'payment_method' => $paymentData['paymentMethod'],
            'payment_method_last_four' => $paymentData['lastFour'] ?? null,
            'status' => $isAch ? Payment::STATUS_PROCESSING : Payment::STATUS_COMPLETED,
            'description' => $paymentData['description'] ?? null,
            'metadata' => [
                'invoices' => $paymentData['invoices'] ?? [],
                'client_name' => $clientInfo['client_name'] ?? null,
            ],
            'processed_at' => $isAch ? null : now(),
            'payment_vendor' => $isAch ? ($vendorInfo['payment_vendor'] ?? 'kotapay') : null,
            'vendor_transaction_id' => $isAch ? ($vendorInfo['vendor_transaction_id'] ?? $transactionId) : null,
        ]);
    }

    /**
     * Get a payment plan by plan ID.
     */
    public function getPaymentPlan(string $planId): ?PaymentPlan
    {
        return PaymentPlan::where('plan_id', $planId)->first();
    }

    /**
     * Cancel a payment plan.
     */
    public function cancelPaymentPlan(string $planId, ?string $reason = null): bool
    {
        $plan = $this->getPaymentPlan($planId);

        if (! $plan || ! $plan->isActive()) {
            return false;
        }

        $plan->cancel($reason);

        Log::info('Payment plan cancelled', [
            'plan_id' => $planId,
            'reason' => $reason,
        ]);

        return true;
    }

    // ==================== Saved Payment Method Support ====================

    /**
     * Process a one-time payment using a saved CustomerPaymentMethod.
     *
     * @param  Customer  $customer  The customer
     * @param  \App\Models\CustomerPaymentMethod  $savedMethod  The saved payment method
     * @param  float  $amount  Amount in dollars
     * @param  array  $options  Additional options (description, invoices, etc.)
     * @return array Result with success status and transaction_id
     */
    public function chargeWithSavedMethod(
        Customer $customer,
        \App\Models\CustomerPaymentMethod $savedMethod,
        float $amount,
        array $options = []
    ): array {
        try {
            // Use Money class to convert dollars to cents safely
            $amountInCents = Money::toCents($amount);

            $response = $customer->charge($amountInCents, array_merge([
                'description' => $options['description'] ?? 'Payment',
                'payment_method' => $savedMethod->mpc_token,
            ], $options));

            Log::info('Payment with saved method successful', [
                'customer_id' => $customer->id,
                'payment_method_id' => $savedMethod->id,
                'amount' => $amount,
                'transaction_id' => $response['PnRef'] ?? $response['TransactionId'] ?? null,
            ]);

            return [
                'success' => true,
                'transaction_id' => $response['PnRef'] ?? $response['TransactionId'] ?? 'txn_'.bin2hex(random_bytes(16)),
                'amount' => $amount,
                'status' => self::STATUS_COMPLETED,
                'response' => $response,
            ];

        } catch (PaymentFailedException $e) {
            Log::error('Payment with saved method failed', [
                'customer_id' => $customer->id,
                'payment_method_id' => $savedMethod->id,
                'amount' => $amount,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Create a payment plan using a saved CustomerPaymentMethod.
     *
     * @param  array  $planData  Payment plan configuration
     * @param  array  $clientInfo  Client information from PracticeCS
     * @param  \App\Models\CustomerPaymentMethod  $savedMethod  The saved payment method
     * @param  bool  $splitDownPayment  Admin only: split down payment into two payments
     * @return array Result with success status and plan details
     */
    public function createPaymentPlanWithSavedMethod(
        array $planData,
        array $clientInfo,
        \App\Models\CustomerPaymentMethod $savedMethod,
        bool $splitDownPayment = false
    ): array {
        // Map saved method type to payment method type expected by createPaymentPlan
        $paymentMethodType = $savedMethod->type === \App\Models\CustomerPaymentMethod::TYPE_CARD ? 'card' : 'ach';

        return $this->createPaymentPlan(
            $planData,
            $clientInfo,
            $savedMethod->mpc_token,
            $paymentMethodType,
            $savedMethod->last_four,
            null, // No raw payment data needed - we have the token
            $splitDownPayment
        );
    }

    /**
     * Convert a QuickPayments token to a reusable token.
     *
     * @param  string  $qpToken  QuickPayments one-time token
     * @return string|null Reusable token or null on failure
     */
    public function convertQpTokenToReusable(string $qpToken): ?string
    {
        try {
            $response = $this->quickPayments->createTokenFromQpToken($qpToken);

            return $response['Token'] ?? null;
        } catch (\Exception $e) {
            Log::error('Failed to convert QP token to reusable token', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return null;
        }
    }

    /**
     * Create a reusable token from card details.
     *
     * @param  array  $cardDetails  Card details (number, exp_month, exp_year, cvc)
     * @param  int|null  $customerKey  MiPaymentChoice customer key
     * @return array{token: string|null, card_type: string|null, last_four: string|null, error: string|null}
     */
    public function tokenizeCard(array $cardDetails, ?int $customerKey = null): array
    {
        try {
            $response = $this->tokenService->createCardToken($cardDetails, $customerKey);

            $cardNumber = $cardDetails['number'] ?? '';
            $lastFour = substr(preg_replace('/\D/', '', $cardNumber), -4);

            return [
                'token' => $response['Token'] ?? null,
                'card_type' => $response['CardType'] ?? \App\Models\CustomerPaymentMethod::detectCardBrand($cardNumber),
                'last_four' => $lastFour,
                'error' => null,
            ];
        } catch (\Exception $e) {
            Log::error('Failed to tokenize card', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return [
                'token' => null,
                'card_type' => null,
                'last_four' => null,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Create a reusable token from check/ACH details.
     *
     * NOTE: Kotapay does not support ACH tokenization. For payment plans,
     * ACH details are stored locally encrypted. This method now generates
     * a local pseudo-token that can be used as a reference.
     *
     * @param  array  $checkDetails  Check details (account_number, routing_number, account_type)
     * @param  int|null  $customerKey  Not used (legacy parameter)
     * @return array{token: string|null, last_four: string|null, error: string|null}
     */
    public function tokenizeCheck(array $checkDetails, ?int $customerKey = null): array
    {
        try {
            // Kotapay does not support tokenization.
            // Generate a local pseudo-token for reference purposes.
            // The actual ACH details are stored encrypted with the payment plan.
            $accountNumber = $checkDetails['account_number'] ?? '';
            $lastFour = substr(preg_replace('/\D/', '', $accountNumber), -4);

            // Generate a unique pseudo-token for local reference
            $pseudoToken = 'ach_local_'.bin2hex(random_bytes(16));

            Log::info('Generated local ACH pseudo-token (Kotapay does not tokenize)', [
                'last_four' => $lastFour,
            ]);

            return [
                'token' => $pseudoToken,
                'last_four' => $lastFour,
                'error' => null,
            ];
        } catch (\Exception $e) {
            Log::error('Failed to generate ACH pseudo-token', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return [
                'token' => null,
                'last_four' => null,
                'error' => $e->getMessage(),
            ];
        }
    }
}
