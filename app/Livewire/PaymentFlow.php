<?php

// app/Livewire/PaymentFlow.php

namespace App\Livewire;

use App\Livewire\PaymentFlow\HasCardFormatting;
use App\Livewire\PaymentFlow\HasInvoiceSelection;
use App\Livewire\PaymentFlow\HasPaymentPlans;
use App\Livewire\PaymentFlow\HasProjectAcceptance;
use App\Livewire\PaymentFlow\HasSavedPaymentMethods;
use App\Livewire\PaymentFlow\Steps;
use App\Models\CustomerPaymentMethod;
use App\Repositories\PaymentRepository;
use App\Services\CustomerPaymentMethodService;
use App\Services\PaymentPlanCalculator;
use App\Services\PaymentService;
use App\Support\Money;
use Flux\Flux;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
class PaymentFlow extends Component
{
    use HasCardFormatting;
    use HasInvoiceSelection;
    use HasPaymentPlans;
    use HasProjectAcceptance;
    use HasSavedPaymentMethods;

    // ==================== Step Tracking ====================
    public string $currentStep = 'verify-account';

    public array $stepHistory = [];

    // ==================== Account Type ====================
    public $accountType = 'personal';

    // ==================== Step 2: Identification ====================
    public $last4 = '';

    public $lastName = '';

    public $businessName = '';

    public $loadingInvoices = false;

    // ==================== Client Data ====================
    public $clientInfo = null;

    public $openInvoices = [];

    public $totalBalance = 0;

    // ==================== Engagement Acceptance ====================
    public $pendingEngagements = [];

    public $currentEngagementIndex = 0;

    public $acceptTerms = false;

    public $hasEngagementsToAccept = false;

    public $engagementsToPersist = [];

    // ==================== Step 3: Payment Information ====================
    public $selectedInvoices = [];

    public $paymentAmount = 0;

    public $paymentNotes = '';

    public $selectAll = false;

    public $clientSelectAll = [];

    public $clientNameMap = [];

    // ==================== Sorting ====================
    public $sortBy = 'due_date';

    public $sortDirection = 'asc';

    // ==================== Client Grouping ====================
    public $showRelatedInvoices = true;

    // ==================== Step 4: Payment Method ====================
    public $paymentMethod = null;

    public $creditCardFee = 0;

    // ==================== Payment Plan ====================
    public $isPaymentPlan = false;

    public $planDuration = 3;

    public $paymentSchedule = [];

    public $paymentPlanFee = 0;

    public $availablePlans = [];

    public $downPayment = 0;

    public $monthlyPayment = 0;

    // ==================== Step 5: Payment Plan Authorization ====================
    public $agreeToTerms = false;

    public $paymentMethodDetails = null;

    public $cardNumber = '';

    public $cardExpiry = '';

    public $cardCvv = '';

    public $bankName = '';

    public $accountNumber = '';

    public $routingNumber = '';

    public $bankAccountType = 'checking';

    public $isBusiness = false;

    public $achAuthorization = false;

    // ==================== Step 6: Confirmation ====================
    public $transactionId = null;

    public $paymentProcessed = false;

    // ==================== Saved Payment Methods ====================
    /** @var Collection<CustomerPaymentMethod> */
    public Collection $savedPaymentMethods;

    public ?int $selectedSavedMethodId = null;

    public bool $savePaymentMethod = false;

    public bool $hasSavedMethods = false;

    public ?string $paymentMethodNickname = null;

    public ?int $methodToDelete = null;

    public array $linkedPlansToReassign = [];

    public array $linkedRecurringToReassign = [];

    public ?int $reassignToMethodId = null;

    public bool $showReassignmentModal = false;

    // ==================== Services ====================
    protected PaymentRepository $paymentRepo;

    protected PaymentService $paymentService;

    protected PaymentPlanCalculator $planCalculator;

    protected CustomerPaymentMethodService $paymentMethodService;

    public function boot(
        PaymentRepository $paymentRepo,
        PaymentService $paymentService,
        PaymentPlanCalculator $planCalculator,
        CustomerPaymentMethodService $paymentMethodService
    ): void {
        $this->paymentRepo = $paymentRepo;
        $this->paymentService = $paymentService;
        $this->planCalculator = $planCalculator;
        $this->paymentMethodService = $paymentMethodService;

        // Initialize saved payment methods collection
        $this->savedPaymentMethods = collect();
    }

    /**
     * Navigate to a step (with history tracking)
     */
    protected function goToStep(string $step): void
    {
        $this->stepHistory[] = $this->currentStep;
        $this->currentStep = $step;
        $this->resetValidation();
    }

    /**
     * Step 1: Select account type
     */
    public function selectAccountType(string $type): void
    {
        $this->accountType = $type;
        $this->goToStep(Steps::VERIFY_ACCOUNT);
    }

    /**
     * Step 1: Verify account (always personal - business clients are grouped)
     */
    public function verifyAccount(): void
    {
        $this->validate([
            'last4' => 'required|digits:4',
            'lastName' => 'required|string|max:100',
        ], [
            'last4.required' => 'Please enter the last 4 digits of your SSN',
            'last4.digits' => 'SSN must be exactly 4 digits',
            'lastName.required' => 'Please enter your last name',
        ]);

        $searchName = $this->lastName;

        // Lookup client
        $client = $this->paymentRepo->getClientByTaxIdAndName($this->last4, $searchName);

        if (! $client) {
            $this->addError('last4', 'No account found matching this information. Please check and try again.');

            return;
        }

        $this->clientInfo = $client;

        // Dispatch success toast
        Flux::toast('Successfully verified!', variant: 'success');

        // Set loading state for skeleton
        $this->loadingInvoices = true;

        // Check for pending engagements BEFORE loading invoices
        $this->checkForPendingEngagements();

        if ($this->hasEngagementsToAccept) {
            // Go to project acceptance step
            $this->goToStep(Steps::PROJECT_ACCEPTANCE);
            $this->loadingInvoices = false;
        } else {
            // Show loading skeleton - onSkeletonComplete will load invoices
            $this->goToStep(Steps::LOADING_INVOICES);
        }
    }

    /**
     * Payment Method Selection
     */
    public function selectPaymentMethod(string $method): void
    {
        $this->paymentMethod = $method;

        // Calculate credit card fee
        if ($method === 'credit_card') {
            $this->creditCardFee = Money::multiplyDollars($this->paymentAmount, config('payment-fees.credit_card_rate'));
        } else {
            $this->creditCardFee = 0;
        }

        // If payment plan, stay on payment method step to show plan selection
        if ($method === 'payment_plan') {
            $this->isPaymentPlan = true;
            // Generate available plan options (3, 6, 9 months) with down payment info
            $this->availablePlans = $this->planCalculator->getAvailablePlans($this->paymentAmount);
            // Default to 3 month plan
            $this->planDuration = 3;
            $this->calculatePaymentPlanFee();
            $this->calculateDownPaymentAndSchedule();

            // Load saved payment methods so they can be offered during plan auth
            $this->loadSavedPaymentMethods();
            // Stay on payment method step to show plan selection
        } else {
            $this->isPaymentPlan = false;
            $this->paymentPlanFee = 0;
            $this->downPayment = 0;
            $this->monthlyPayment = 0;

            // Check for saved payment methods and go to saved methods step if available
            $this->loadSavedPaymentMethods();

            if ($this->hasSavedMethods && $this->getSavedMethodsForCurrentType()->isNotEmpty()) {
                // Customer has saved methods of this type - let them choose
                $this->goToStep(Steps::SAVED_PAYMENT_METHODS);
            } else {
                // No saved methods - proceed to payment details
                $this->goToStep(Steps::PAYMENT_DETAILS);
            }
        }
    }

    /**
     * Go back to previous step using history stack
     */
    public function goBack(): void
    {
        if (! empty($this->stepHistory)) {
            $previousStep = array_pop($this->stepHistory);
            $this->currentStep = $previousStep;

            // Special handling based on the step we're going back to
            if ($previousStep === Steps::PROJECT_ACCEPTANCE && $this->hasEngagementsToAccept) {
                // Going back to project acceptance - reset to last engagement or first if all were accepted
                $this->currentEngagementIndex = max(0, count($this->pendingEngagements) - 1);
                $this->acceptTerms = false;
            } elseif ($previousStep === Steps::PAYMENT_PLAN_AUTH && $this->isPaymentPlan) {
                // Going back to authorization from confirmation - keep plan settings
                // No special handling needed, just clear transaction ID
                $this->transactionId = null;
            } elseif ($previousStep === Steps::PAYMENT_METHOD && $this->isPaymentPlan) {
                // Going back to plan configuration from authorization - keep plan settings
                // Clear authorization data but keep plan configuration
                $this->agreeToTerms = false;
                $this->cardNumber = '';
                $this->cardExpiry = '';
                $this->cardCvv = '';
                $this->bankName = '';
                $this->accountNumber = '';
                $this->routingNumber = '';
                $this->paymentMethodDetails = null;
            }

            $this->resetValidation();
        }
    }

    /**
     * Alias for goBack (used in step components)
     */
    public function goToPrevious(): void
    {
        $this->goBack();
    }

    /**
     * Change payment method entirely - go back to method selection
     */
    public function changePaymentMethod(): void
    {
        // Go back to payment method step and reset payment plan state completely
        $this->currentStep = Steps::PAYMENT_METHOD;
        $this->resetValidation();

        // Reset to payment method selection
        $this->isPaymentPlan = false;
        $this->paymentMethod = null;
        $this->planDuration = 3;
        $this->paymentSchedule = [];
        $this->availablePlans = [];

        // Clear authorization data
        $this->agreeToTerms = false;
        $this->cardNumber = '';
        $this->cardExpiry = '';
        $this->cardCvv = '';
        $this->bankName = '';
        $this->accountNumber = '';
        $this->routingNumber = '';
        $this->paymentMethodDetails = null;
        $this->transactionId = null;
    }

    /**
     * Edit payment plan - go back to plan configuration
     */
    public function editPaymentPlan(): void
    {
        if ($this->isPaymentPlan) {
            // Go back to payment method step (plan configuration) but keep plan settings
            $this->currentStep = Steps::PAYMENT_METHOD;
            $this->resetValidation();

            // Clear authorization and confirmation data but keep plan configuration
            $this->agreeToTerms = false;
            $this->cardNumber = '';
            $this->cardExpiry = '';
            $this->cardCvv = '';
            $this->bankName = '';
            $this->accountNumber = '';
            $this->routingNumber = '';
            $this->paymentMethodDetails = null;
            $this->transactionId = null; // Clear any generated transaction ID
        }
    }

    /**
     * Confirm and process the payment
     */
    public function confirmPayment(): void
    {
        // Validate payment details
        if ($this->paymentMethod === 'credit_card') {
            $this->validate([
                'cardNumber' => ['required', 'regex:/^[0-9\s]{13,19}$/'],
                'cardExpiry' => ['required', 'regex:/^(0[1-9]|1[0-2])\/[0-9]{2}$/'],
                'cardCvv' => ['required', 'regex:/^[0-9]{3,4}$/'],
            ], [
                'cardNumber.required' => 'Card number is required',
                'cardNumber.regex' => 'Invalid card number format',
                'cardExpiry.required' => 'Expiration date is required',
                'cardExpiry.regex' => 'Invalid expiration date (use MM/YY)',
                'cardCvv.required' => 'CVV is required',
                'cardCvv.regex' => 'CVV must be 3 or 4 digits',
            ]);
        } elseif ($this->paymentMethod === 'ach') {
            $this->validate([
                'routingNumber' => ['required', 'digits:9'],
                'accountNumber' => ['required', 'digits_between:8,17'],
                'bankName' => ['required', 'string', 'min:2'],
                'achAuthorization' => ['accepted'],
            ], [
                'routingNumber.required' => 'Routing number is required',
                'routingNumber.digits' => 'Routing number must be exactly 9 digits',
                'accountNumber.required' => 'Account number is required',
                'accountNumber.digits_between' => 'Account number must be 8-17 digits',
                'bankName.required' => 'Bank name is required',
                'achAuthorization.accepted' => 'You must authorize the ACH debit to continue',
            ]);
        }

        // Generate a new transaction ID for each payment attempt
        // This prevents duplicate transaction rejection by the gateway
        $this->transactionId = ($this->isPaymentPlan ? 'plan_' : 'txn_').bin2hex(random_bytes(16));

        // Prepare payment data
        $paymentData = [
            'amount' => $this->paymentAmount,
            'paymentMethod' => $this->paymentMethod,
            'fee' => $this->creditCardFee,
            'isPaymentPlan' => $this->isPaymentPlan,
            'planFrequency' => 'monthly', // Always monthly now
            'planDuration' => $this->planDuration,
            'downPayment' => $this->downPayment, // 30% down payment
            'paymentSchedule' => $this->paymentSchedule,
            'planId' => $this->transactionId,
            'invoices' => collect($this->selectedInvoices)->map(function ($invoiceNumber) {
                $invoice = collect($this->openInvoices)->firstWhere('invoice_number', $invoiceNumber);

                return $invoice ? [
                    'invoice_number' => $invoice['invoice_number'],
                    'description' => $invoice['description'],
                    'amount' => $invoice['open_amount'],
                ] : null;
            })->filter()->values()->toArray(),
            'notes' => $this->paymentNotes,
        ];

        $paymentResult = null;

        try {
            // Get or create customer for MiPaymentChoice
            $customer = $this->paymentService->getOrCreateCustomer($this->clientInfo);

            if ($this->isPaymentPlan) {
                // For payment plans, tokenize card/check and save payment method
                if ($this->selectedSavedMethodId) {
                    // Use existing saved payment method for the plan
                    $savedMethod = $this->savedPaymentMethods->firstWhere('id', $this->selectedSavedMethodId);

                    if (! $savedMethod) {
                        $this->addError('payment', 'Selected payment method not found.');

                        return;
                    }

                    $token = $savedMethod->mpc_token;
                    $lastFour = $savedMethod->last_four;
                    $methodType = $savedMethod->type === \App\Models\CustomerPaymentMethod::TYPE_CARD ? 'card' : 'ach';

                    // For saved ACH methods, retrieve bank details for down payment processing
                    $paymentMethodData = null;
                    if ($methodType === 'ach') {
                        $bankDetails = $savedMethod->getBankDetails();
                        if ($bankDetails) {
                            $paymentMethodData = [
                                'type' => 'ach',
                                'routing' => $bankDetails['routing_number'],
                                'account' => $bankDetails['account_number'],
                                'account_type' => $savedMethod->account_type ?? 'checking',
                                'name' => $this->clientInfo['client_name'],
                            ];
                        }
                    }

                    // Create the payment plan with saved method
                    $paymentResult = $this->paymentService->createPaymentPlan(
                        [
                            'amount' => $this->paymentAmount,
                            'planFee' => $this->paymentPlanFee,
                            'planDuration' => $this->planDuration,
                            'paymentSchedule' => $this->paymentSchedule,
                            'invoices' => $paymentData['invoices'],
                        ],
                        $this->clientInfo,
                        $token,
                        $methodType,
                        $lastFour,
                        $paymentMethodData,
                        false // Don't split down payment (public flow)
                    );

                    // Store the plan ID for confirmation display
                    if ($paymentResult && $paymentResult['success']) {
                        $this->transactionId = $paymentResult['plan_id'];
                    }
                } elseif ($this->paymentMethod === 'credit_card') {
                    // Create reusable card token
                    $token = $customer->tokenizeCard([
                        'number' => str_replace(' ', '', $this->cardNumber),
                        'exp_month' => (int) substr($this->cardExpiry, 0, 2),
                        'exp_year' => (int) ('20'.substr($this->cardExpiry, 3, 2)),
                        'cvc' => $this->cardCvv,
                        'name' => $this->clientInfo['client_name'],
                        'street' => '', // Could add billing address fields
                        'postal_code' => '',
                    ]);
                    $lastFour = substr(str_replace(' ', '', $this->cardNumber), -4);
                } else {
                    // For ACH payment plans, generate a local pseudo-token
                    // Kotapay does not support tokenization - ACH details are stored encrypted with the plan
                    $tokenResult = $this->paymentService->tokenizeCheck([
                        'routing_number' => $this->routingNumber,
                        'account_number' => $this->accountNumber,
                        'name' => $this->clientInfo['client_name'],
                        'account_type' => ucfirst($this->bankAccountType),
                    ]);
                    $token = $tokenResult['token'] ?? 'ach_local_'.bin2hex(random_bytes(16));
                    $lastFour = substr($this->accountNumber, -4);
                }

                // Prepare payment method data for down payment processing
                $paymentMethodData = $this->paymentMethod === 'credit_card'
                    ? [
                        'type' => 'card',
                        'number' => str_replace(' ', '', $this->cardNumber),
                        'expiry' => $this->cardExpiry,
                        'cvv' => $this->cardCvv,
                        'name' => $this->clientInfo['client_name'],
                    ]
                    : [
                        'type' => 'ach',
                        'routing' => $this->routingNumber,
                        'account' => $this->accountNumber,
                        'account_type' => $this->bankAccountType,
                        'name' => $this->clientInfo['client_name'],
                    ];

                // Create the payment plan with 30% down payment charged immediately
                $paymentResult = $this->paymentService->createPaymentPlan(
                    [
                        'amount' => $this->paymentAmount,
                        'planFee' => $this->paymentPlanFee,
                        'planDuration' => $this->planDuration,
                        'paymentSchedule' => $this->paymentSchedule,
                        'invoices' => $paymentData['invoices'],
                    ],
                    $this->clientInfo,
                    $token,
                    $this->paymentMethod === 'credit_card' ? 'card' : 'ach',
                    $lastFour,
                    $paymentMethodData, // For charging down payment
                    false // Don't split down payment (public flow)
                );

                // Store the plan ID for confirmation display
                if ($paymentResult['success']) {
                    $this->transactionId = $paymentResult['plan_id'];
                }
            } else {
                // For one-time payments, process immediately
                if ($this->paymentMethod === 'credit_card') {
                    // Create QuickPayments token and charge
                    $cardData = [
                        'number' => str_replace(' ', '', $this->cardNumber),
                        'exp_month' => (int) substr($this->cardExpiry, 0, 2),
                        'exp_year' => (int) ('20'.substr($this->cardExpiry, 3, 2)),
                        'cvc' => $this->cardCvv,
                        'name' => $this->clientInfo['client_name'],
                        'street' => '',
                        'zip_code' => '',
                        'email' => $this->clientInfo['email'] ?? '',
                    ];

                    Log::info('Creating QP token with card data', [
                        'last_four' => substr($cardData['number'], -4),
                        'exp_month' => $cardData['exp_month'],
                        'exp_year' => $cardData['exp_year'],
                        'has_cvv' => ! empty($cardData['cvc']),
                        'name' => $cardData['name'],
                    ]);

                    $qpToken = $customer->createQuickPaymentsToken($cardData);

                    $paymentResult = $this->paymentService->chargeWithQuickPayments(
                        $customer,
                        $qpToken,
                        $this->paymentAmount + $this->creditCardFee,
                        [
                            'description' => "Payment for {$this->clientInfo['client_name']} - ".count($this->selectedInvoices).' invoice(s)',
                        ]
                    );
                } elseif ($this->paymentMethod === 'ach') {
                    // Process ACH payment via Kotapay
                    $paymentResult = $this->paymentService->chargeAchWithKotapay(
                        $customer,
                        [
                            'routing_number' => $this->routingNumber,
                            'account_number' => $this->accountNumber,
                            'account_type' => ucfirst($this->bankAccountType),
                            'account_name' => $this->clientInfo['client_name'],
                        ],
                        $this->paymentAmount,
                        [
                            'description' => "ACH Payment for {$this->clientInfo['client_name']} - ".count($this->selectedInvoices).' invoice(s)',
                        ]
                    );
                } else {
                    // For check payments, log for manual processing
                    $paymentResult = [
                        'success' => true,
                        'transaction_id' => $this->transactionId,
                        'amount' => $this->paymentAmount,
                        'status' => 'pending_check',
                        'message' => 'Check payment logged for manual processing',
                    ];
                }
            }

            if (! $paymentResult || ! $paymentResult['success']) {
                $error = $paymentResult['error'] ?? 'Payment processing failed';
                $this->addError('payment', $error);
                Log::error('Payment processing failed', [
                    'transaction_id' => $this->transactionId,
                    'error' => $error,
                    'client_id' => $this->clientInfo['client_id'],
                ]);

                // Clear transaction ID on failure so next attempt gets a fresh one
                $this->transactionId = null;

                return;
            }

            // Log successful payment
            Log::info('Payment processed successfully', [
                'transaction_id' => $this->transactionId,
                'client_id' => $this->clientInfo['client_id'],
                'amount' => $paymentData['amount'],
                'payment_method' => $this->paymentMethod,
                'is_payment_plan' => $this->isPaymentPlan,
            ]);

            // Record payment to database (for one-time payments only - payment plans are recorded in createPaymentPlan)
            if (! $this->isPaymentPlan) {
                $lastFour = $this->paymentMethod === 'credit_card'
                    ? substr(str_replace(' ', '', $this->cardNumber), -4)
                    : substr($this->accountNumber, -4);

                $this->paymentService->recordPayment([
                    'amount' => $this->paymentAmount,
                    'fee' => $this->creditCardFee,
                    'paymentMethod' => $this->paymentMethod,
                    'lastFour' => $lastFour,
                    'invoices' => $this->selectedInvoices,
                    'description' => "Payment for {$this->clientInfo['client_name']} - ".count($this->selectedInvoices).' invoice(s)',
                ], $this->clientInfo, $this->transactionId, [
                    'payment_vendor' => $paymentResult['payment_vendor'] ?? null,
                    'vendor_transaction_id' => $paymentResult['transaction_id'] ?? null,
                ]);
            }

            // Save payment method if requested (only for one-time payments with new card/bank)
            if ($this->savePaymentMethod && ! $this->isPaymentPlan && ! $this->selectedSavedMethodId) {
                try {
                    if ($this->paymentMethod === 'credit_card') {
                        // For cards, create reusable token from the successful transaction using PnRef
                        $pnRef = $paymentResult['response']['PnRef'] ?? null;

                        if ($pnRef) {
                            $tokenResponse = $customer->tokenizeFromTransaction((int) $pnRef);

                            if (isset($tokenResponse['CardToken']['Token'])) {
                                $token = $tokenResponse['CardToken']['Token'];
                                $this->saveCurrentPaymentMethod($token, CustomerPaymentMethod::TYPE_CARD);
                                Log::info('Card payment method saved from transaction', [
                                    'pnRef' => $pnRef,
                                ]);
                            } else {
                                Log::warning('Could not extract card token from transaction response', [
                                    'pnRef' => $pnRef,
                                    'tokenResponse' => $tokenResponse,
                                ]);
                            }
                        } else {
                            Log::warning('No PnRef in payment result, cannot save card payment method');
                        }
                    } elseif ($this->paymentMethod === 'ach') {
                        // For ACH, Kotapay doesn't support tokenization from transactions
                        // Generate a local pseudo-token and save ACH details encrypted
                        $tokenResult = $this->paymentService->tokenizeCheck([
                            'routing_number' => $this->routingNumber,
                            'account_number' => $this->accountNumber,
                            'account_type' => ucfirst($this->bankAccountType),
                        ]);

                        if ($tokenResult['token']) {
                            $this->saveCurrentPaymentMethod($tokenResult['token'], CustomerPaymentMethod::TYPE_ACH);
                            Log::info('ACH payment method saved with local pseudo-token', [
                                'last_four' => $tokenResult['last_four'],
                            ]);
                        }
                    }
                } catch (\Exception $e) {
                    // Log but don't fail - payment already succeeded
                    Log::warning('Failed to save payment method after payment', [
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            // Write payment to PracticeCS if enabled
            if (config('practicecs.payment_integration.enabled')) {
                try {
                    $this->writeToPracticeCs($paymentResult);
                } catch (\Exception $e) {
                    // Log but don't fail the payment - it already succeeded with MiPaymentChoice
                    Log::error('Failed to write payment to PracticeCS', [
                        'transaction_id' => $this->transactionId,
                        'error' => $e->getMessage(),
                        'trace' => $e->getTraceAsString(),
                    ]);
                    // Could queue for retry here
                }
            }

        } catch (\Exception $e) {
            $this->addError('payment', 'Payment processing failed: '.$e->getMessage());
            Log::error('Payment processing exception', [
                'transaction_id' => $this->transactionId,
                'error' => $e->getMessage(),
                'client_id' => $this->clientInfo['client_id'],
            ]);

            return;
        }

        // Send payment receipt email
        try {
            $clientEmail = $this->clientInfo['email'] ?? null;

            if ($clientEmail) {
                Mail::to($clientEmail)
                    ->send(new \App\Mail\PaymentReceipt($paymentData, $this->clientInfo, $this->transactionId));

                // Log successful email send
                Log::info('Payment receipt email sent', [
                    'transaction_id' => $this->transactionId,
                    'client_id' => $this->clientInfo['client_id'],
                    'amount' => $paymentData['amount'],
                ]);
            } else {
                Log::warning('Payment receipt email not sent - no client email on file', [
                    'transaction_id' => $this->transactionId,
                    'client_id' => $this->clientInfo['client_id'],
                ]);
            }
        } catch (\Exception $e) {
            // Log email failure but don't block payment completion
            Log::error('Failed to send payment receipt email', [
                'transaction_id' => $this->transactionId,
                'error' => $e->getMessage(),
            ]);
        }

        // Persist accepted engagements
        $this->persistAcceptedEngagements();

        // Mark as completed
        $this->paymentProcessed = true;
        $this->currentStep = Steps::CONFIRMATION;
    }

    /**
     * Write payment to PracticeCS with client group support
     *
     * Handles payments across client groups by:
     * 1. Writing payment to primary client
     * 2. Applying payment to primary client's invoices
     * 3. Creating debit memo on primary client for overpayment
     * 4. Creating credit memos on other group clients
     * 5. Applying credit memos to other clients' invoices
     */
    protected function writeToPracticeCs(array $paymentResult): void
    {
        $writer = app(\App\Services\PracticeCsPaymentWriter::class);

        // Get payment method type mapping
        $paymentMethodMap = [
            'credit_card' => 'credit_card',
            'ach' => 'ach',
            'check' => 'check',
        ];

        $methodType = $paymentMethodMap[$this->paymentMethod] ?? 'cash';

        // CRITICAL: Payment amount should NOT include credit card fee
        $paymentAmount = $this->paymentAmount;

        // Get primary client KEY (the client who logged in)
        $primaryClientKey = $this->clientInfo['client_KEY'] ?? $this->clientInfo['clients'][0]['client_KEY'];

        // Step 1: Separate invoices by client
        $primaryInvoices = [];
        $otherClientsInvoices = [];  // Grouped by client_KEY

        foreach ($this->selectedInvoices as $invoiceNumber) {
            $invoice = collect($this->openInvoices)->firstWhere('invoice_number', $invoiceNumber);

            if (! $invoice) {
                Log::warning('Invoice not found for application', [
                    'invoice_number' => $invoiceNumber,
                    'transaction_id' => $paymentResult['transaction_id'],
                ]);

                continue;
            }

            // Skip placeholder invoices
            if (isset($invoice['is_placeholder']) && $invoice['is_placeholder']) {
                continue;
            }

            if ($invoice['client_KEY'] == $primaryClientKey) {
                $primaryInvoices[] = $invoice;
            } else {
                $clientKey = $invoice['client_KEY'];
                if (! isset($otherClientsInvoices[$clientKey])) {
                    $otherClientsInvoices[$clientKey] = [
                        'client_KEY' => $clientKey,
                        'client_name' => $invoice['client_name'],
                        'client_id' => $invoice['client_id'],
                        'invoices' => [],
                    ];
                }
                $otherClientsInvoices[$clientKey]['invoices'][] = $invoice;
            }
        }

        // Step 2: Calculate amounts
        $primaryInvoicesTotal = array_sum(array_column($primaryInvoices, 'open_amount'));
        $otherInvoicesTotal = 0;

        foreach ($otherClientsInvoices as $clientGroup) {
            $otherInvoicesTotal += array_sum(array_column($clientGroup['invoices'], 'open_amount'));
        }

        Log::info('Payment distribution analysis', [
            'total_payment' => $paymentAmount,
            'primary_client_KEY' => $primaryClientKey,
            'primary_invoices_total' => $primaryInvoicesTotal,
            'other_clients_count' => count($otherClientsInvoices),
            'other_invoices_total' => $otherInvoicesTotal,
        ]);

        // Step 3: Write payment to primary client
        $primaryInvoicesToApply = [];
        $remainingAmount = $paymentAmount;

        foreach ($primaryInvoices as $invoice) {
            if ($remainingAmount <= 0) {
                break;
            }

            $applyAmount = min($remainingAmount, (float) $invoice['open_amount']);
            $primaryInvoicesToApply[] = [
                'ledger_entry_KEY' => $invoice['ledger_entry_KEY'],
                'amount' => $applyAmount,
            ];
            $remainingAmount -= $applyAmount;
        }

        // Prepare payment data for PracticeCS
        $practiceCsData = [
            'client_KEY' => $primaryClientKey,
            'amount' => $paymentAmount,  // EXCLUDE credit card fee!
            'reference' => $paymentResult['transaction_id'],
            'comments' => "Online payment - {$this->paymentMethod} - ".count($this->selectedInvoices).' invoice(s)',
            'internal_comments' => json_encode([
                'source' => 'tr-pay',
                'transaction_id' => $paymentResult['transaction_id'],
                'payment_method' => $this->paymentMethod,
                'is_payment_plan' => $this->isPaymentPlan,
                'fee' => $this->creditCardFee,
                'has_group_distribution' => ! empty($otherClientsInvoices),
                'processed_at' => now()->toIso8601String(),
            ]),
            'staff_KEY' => config('practicecs.payment_integration.staff_key'),
            'bank_account_KEY' => config('practicecs.payment_integration.bank_account_key'),
            'ledger_type_KEY' => config("practicecs.payment_integration.ledger_types.{$methodType}"),
            'subtype_KEY' => config("practicecs.payment_integration.payment_subtypes.{$methodType}"),
            'invoices' => $primaryInvoicesToApply,
        ];

        $result = $writer->writePayment($practiceCsData);

        if (! $result['success']) {
            throw new \Exception('PracticeCS payment write failed: '.($result['error'] ?? 'Unknown error'));
        }

        $paymentLedgerKey = $result['ledger_entry_KEY'];

        Log::info('Payment written to PracticeCS', [
            'transaction_id' => $paymentResult['transaction_id'],
            'ledger_entry_KEY' => $paymentLedgerKey,
            'entry_number' => $result['entry_number'],
            'remaining_amount' => $remainingAmount,
        ]);

        // Step 4: Handle client group distribution if needed
        if ($remainingAmount > 0.01 && ! empty($otherClientsInvoices)) {
            // Generate unique memo reference with date
            $memoReference = 'MEMO_'.now()->format('Ymd').'_'.$paymentResult['transaction_id'];

            Log::info('Creating memos for client group distribution', [
                'memo_reference' => $memoReference,
                'remaining_amount' => $remainingAmount,
                'other_clients_count' => count($otherClientsInvoices),
            ]);

            $distributedAmount = 0;
            $connection = config('practicecs.payment_integration.connection', 'sqlsrv');
            $staffKey = config('practicecs.payment_integration.staff_key');

            // Process each other client
            foreach ($otherClientsInvoices as $clientData) {
                $clientKey = $clientData['client_KEY'];
                $clientInvoices = $clientData['invoices'];

                // Calculate amount for this client
                $clientTotal = array_sum(array_column($clientInvoices, 'open_amount'));
                $clientAmount = min($remainingAmount - $distributedAmount, $clientTotal);

                if ($clientAmount <= 0.01) {
                    break;
                }

                // Step 4a: Create CREDIT MEMO on OTHER client
                // This represents money "received" by the other client from the logged-in client
                $creditMemoData = [
                    'client_KEY' => $clientKey,
                    'amount' => $clientAmount,
                    'reference' => $memoReference,
                    'comments' => 'Credit memo - payment from '.($this->clientInfo['client_name'] ?? 'group member'),
                    'internal_comments' => json_encode([
                        'source' => 'tr-pay',
                        'transaction_id' => $paymentResult['transaction_id'],
                        'original_payment_key' => $paymentLedgerKey,
                        'memo_type' => 'credit',
                        'from_client_KEY' => $primaryClientKey,
                        'from_client_name' => $this->clientInfo['client_name'] ?? '',
                        'group_distribution' => true,
                        'processed_at' => now()->toIso8601String(),
                    ]),
                    'staff_KEY' => $staffKey,
                    'bank_account_KEY' => config('practicecs.payment_integration.bank_account_key'),
                    'ledger_type_KEY' => config('practicecs.payment_integration.memo_types.credit'),
                    'subtype_KEY' => config('practicecs.payment_integration.memo_subtypes.credit'),
                    'invoices' => [],
                ];

                $creditResult = $writer->writeMemo($creditMemoData, 'credit');

                if (! $creditResult['success']) {
                    throw new \Exception("Failed to create credit memo for client {$clientKey}: ".($creditResult['error'] ?? 'Unknown error'));
                }

                $creditMemoLedgerKey = $creditResult['ledger_entry_KEY'];

                Log::info('Credit memo created', [
                    'client_KEY' => $clientKey,
                    'client_name' => $clientData['client_name'],
                    'ledger_entry_KEY' => $creditMemoLedgerKey,
                    'amount' => $clientAmount,
                ]);

                // Step 4b: Apply OTHER client's invoices TO the credit memo
                $invoicesToApply = [];
                $applyRemaining = $clientAmount;

                foreach ($clientInvoices as $invoice) {
                    if ($applyRemaining <= 0.01) {
                        break;
                    }

                    $applyAmount = min($applyRemaining, (float) $invoice['open_amount']);
                    $invoicesToApply[] = [
                        'ledger_entry_KEY' => $invoice['ledger_entry_KEY'],
                        'amount' => $applyAmount,
                    ];
                    $applyRemaining -= $applyAmount;
                }

                if (! empty($invoicesToApply)) {
                    DB::connection($connection)->transaction(function () use ($connection, $creditMemoLedgerKey, $invoicesToApply, $writer, $staffKey) {
                        $reflection = new \ReflectionClass($writer);
                        $method = $reflection->getMethod('applyPaymentToInvoices');
                        $method->setAccessible(true);
                        $method->invoke($writer, $connection, $creditMemoLedgerKey, $invoicesToApply, $staffKey);
                    });

                    Log::info('Credit memo applied to invoices', [
                        'credit_memo_KEY' => $creditMemoLedgerKey,
                        'invoices_count' => count($invoicesToApply),
                    ]);
                }

                // Step 4c: Create DEBIT MEMO on LOGGED-IN client
                // This represents money "sent" from the logged-in client to cover the other client
                $debitMemoData = [
                    'client_KEY' => $primaryClientKey,
                    'amount' => $clientAmount,
                    'reference' => $memoReference,
                    'comments' => 'Debit memo - payment to '.($clientData['client_name'] ?? 'group member'),
                    'internal_comments' => json_encode([
                        'source' => 'tr-pay',
                        'transaction_id' => $paymentResult['transaction_id'],
                        'original_payment_key' => $paymentLedgerKey,
                        'memo_type' => 'debit',
                        'to_client_KEY' => $clientKey,
                        'to_client_name' => $clientData['client_name'] ?? '',
                        'group_distribution' => true,
                        'processed_at' => now()->toIso8601String(),
                    ]),
                    'staff_KEY' => $staffKey,
                    'bank_account_KEY' => config('practicecs.payment_integration.bank_account_key'),
                    'ledger_type_KEY' => config('practicecs.payment_integration.memo_types.debit'),
                    'subtype_KEY' => config('practicecs.payment_integration.memo_subtypes.debit'),
                    'invoices' => [],
                ];

                $debitResult = $writer->writeMemo($debitMemoData, 'debit');

                if (! $debitResult['success']) {
                    throw new \Exception('Failed to create debit memo: '.($debitResult['error'] ?? 'Unknown error'));
                }

                $debitMemoLedgerKey = $debitResult['ledger_entry_KEY'];

                Log::info('Debit memo created', [
                    'client_KEY' => $primaryClientKey,
                    'ledger_entry_KEY' => $debitMemoLedgerKey,
                    'amount' => $clientAmount,
                ]);

                // Step 4d: Apply DEBIT MEMO to the PAYMENT
                // This is the critical step - the debit memo must be applied to the payment
                // just like an invoice is applied to a payment
                DB::connection($connection)->insert('
                    INSERT INTO Ledger_Entry_Application (
                        update__staff_KEY,
                        update_date_utc,
                        from__ledger_entry_KEY,
                        to__ledger_entry_KEY,
                        applied_amount,
                        create_date_utc
                    )
                    VALUES (?, GETUTCDATE(), ?, ?, ?, GETUTCDATE())
                ', [
                    $staffKey,
                    $debitMemoLedgerKey,  // FROM = Debit Memo
                    $paymentLedgerKey,     // TO = Payment
                    $clientAmount,
                ]);

                Log::info('Debit memo applied to payment', [
                    'debit_memo_KEY' => $debitMemoLedgerKey,
                    'payment_KEY' => $paymentLedgerKey,
                    'amount' => $clientAmount,
                ]);

                $distributedAmount += $clientAmount;
            }

            Log::info('Client group distribution completed', [
                'total_distributed' => $distributedAmount,
                'memo_reference' => $memoReference,
            ]);
        }
    }

    /**
     * Start over
     */
    public function startOver(): void
    {
        $this->reset();
        $this->currentStep = Steps::ACCOUNT_TYPE;
        $this->stepHistory = [];
    }

    /**
     * Called when skeleton loading completes to advance to actual step
     */
    public function onSkeletonComplete(): void
    {
        // Transition from loading step to actual step
        if ($this->currentStep === Steps::LOADING_INVOICES) {
            $this->loadClientInvoices();
            $this->loadingInvoices = false;
            $this->currentStep = Steps::INVOICE_SELECTION;
        } elseif ($this->currentStep === Steps::LOADING_PAYMENT) {
            $this->currentStep = Steps::PAYMENT_METHOD;
        } elseif ($this->currentStep === Steps::PROCESSING_PAYMENT) {
            $this->currentStep = Steps::CONFIRMATION;
        }
    }

    /**
     * Get navigation context for Navigator class
     */
    protected function getNavigationContext(): array
    {
        return [
            'hasEngagementsToAccept' => $this->hasEngagementsToAccept,
            'currentEngagementIndex' => $this->currentEngagementIndex,
            'totalEngagements' => count($this->pendingEngagements),
            'isPaymentPlan' => $this->isPaymentPlan,
        ];
    }

    public function render()
    {
        // Safety check: ensure currentEngagementIndex is valid
        if ($this->hasEngagementsToAccept && $this->currentStep === Steps::PROJECT_ACCEPTANCE) {
            if ($this->currentEngagementIndex >= count($this->pendingEngagements)) {
                $this->currentEngagementIndex = max(0, count($this->pendingEngagements) - 1);
            }
        }

        return view('livewire.payment-flow');
    }
}
