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
use App\Services\PaymentOrchestrator;
use App\Services\PaymentOrchestrator\ProcessPaymentCommand;
use App\Services\PaymentPlanCalculator;
use App\Services\PaymentService;
use App\Support\Money;
use Flux\Flux;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Lazy;
use Livewire\Component;
use RyanChandler\LaravelCloudflareTurnstile\Rules\Turnstile;

#[Layout('layouts::app')]
#[Lazy]
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

    // ==================== Bot Protection ====================
    public string $turnstileToken = '';

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

    public bool $savePaymentMethod = true;

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
            'turnstileToken' => ['required', new Turnstile],
        ], [
            'last4.required' => 'Please enter the last 4 digits of your SSN',
            'last4.digits' => 'SSN must be exactly 4 digits',
            'lastName.required' => 'Please enter your last name',
            'turnstileToken.required' => 'Please complete the security check.',
        ]);

        $searchName = $this->lastName;

        // Lookup client
        $client = $this->paymentRepo->getClientByTaxIdAndName($this->last4, $searchName);

        if (! $client) {
            $this->reset('turnstileToken');
            $this->addError('last4', 'No account found matching this information. Please check and try again.');

            return;
        }

        $this->clientInfo = $client;

        // Dispatch success toast
        Flux::toast('Successfully verified!', variant: 'success');

        // Set loading state for skeleton
        $this->loadingInvoices = true;

        // Engagements/fee request step temporarily disabled
        // $this->checkForPendingEngagements();
        //
        // if ($this->hasEngagementsToAccept) {
        //     $this->goToStep(Steps::PROJECT_ACCEPTANCE);
        //     $this->loadingInvoices = false;
        // } else {
        //     $this->goToStep(Steps::LOADING_INVOICES);
        // }

        // Show loading skeleton - onSkeletonComplete will load invoices
        $this->goToStep(Steps::LOADING_INVOICES);
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
                    'ledger_entry_KEY' => $invoice['ledger_entry_KEY'] ?? null,
                    'open_amount' => $invoice['open_amount'],
                    'client_KEY' => $invoice['client_KEY'] ?? null,
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
                                'is_business' => $savedMethod->is_business ?? false,
                            ];
                        }
                    }

                    // Create the payment plan with saved method
                    $paymentResult = $this->paymentService->createPaymentPlan(
                        [
                            'amount' => $this->paymentAmount,
                            'planFee' => $this->paymentPlanFee,
                            'creditCardFee' => $this->creditCardFee,
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
                        'is_business' => (bool) $this->isBusiness,
                    ];

                // Create the payment plan with 30% down payment charged immediately
                $paymentResult = $this->paymentService->createPaymentPlan(
                    [
                        'amount' => $this->paymentAmount,
                        'planFee' => $this->paymentPlanFee,
                        'creditCardFee' => $this->creditCardFee,
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
                // One-time payment: delegate to PaymentOrchestrator
                $orchestrator = app(PaymentOrchestrator::class);
                $command = $this->buildOrchestratorCommand($customer);
                $result = $orchestrator->processPayment($command);

                if (! $result->success) {
                    $this->addError('payment', $result->error ?? 'Payment processing failed');
                    $this->transactionId = null;

                    return;
                }

                $this->transactionId = $result->transactionId;
                $this->paymentProcessed = true;
                $this->currentStep = Steps::CONFIRMATION;

                return;
            }

            if (! $paymentResult || ! $paymentResult['success']) {
                $error = $paymentResult['error'] ?? 'Payment processing failed';
                $this->addError('payment', $error);
                Log::error('Payment processing failed', [
                    'transaction_id' => $this->transactionId,
                    'error' => $error,
                    'client_id' => $this->clientInfo['client_id'],
                ]);

                $this->transactionId = null;

                return;
            }

            // Payment plan success — save payment method if requested (and not already saved)
            if ($this->savePaymentMethod && ! $this->selectedSavedMethodId) {
                try {
                    $paymentMethodService = app(CustomerPaymentMethodService::class);

                    if ($this->paymentMethod === 'credit_card') {
                        $cardNumber = str_replace(' ', '', $this->cardNumber);
                        $paymentMethodService->create($customer, [
                            'mpc_token' => $token,
                            'type' => CustomerPaymentMethod::TYPE_CARD,
                            'nickname' => $this->paymentMethodNickname,
                            'last_four' => substr($cardNumber, -4),
                            'brand' => CustomerPaymentMethod::detectCardBrand($cardNumber),
                            'exp_month' => (int) substr($this->cardExpiry, 0, 2),
                            'exp_year' => (int) ('20' . substr($this->cardExpiry, 3, 2)),
                        ], false);
                    } elseif ($this->paymentMethod === 'ach') {
                        $savedMethod = $paymentMethodService->create($customer, [
                            'mpc_token' => $token,
                            'type' => CustomerPaymentMethod::TYPE_ACH,
                            'nickname' => $this->paymentMethodNickname,
                            'last_four' => substr($this->accountNumber, -4),
                            'bank_name' => $this->bankName,
                            'account_type' => $this->bankAccountType ?? 'checking',
                            'is_business' => (bool) $this->isBusiness,
                        ], false);

                        // Store encrypted bank details for future scheduled payments
                        if ($savedMethod) {
                            $savedMethod->setBankDetails(
                                $this->routingNumber,
                                $this->accountNumber
                            );
                        }
                    }

                    Log::info('Payment method saved from payment plan flow', [
                        'customer_id' => $customer->id,
                        'method' => $this->paymentMethod,
                    ]);
                } catch (\Exception $e) {
                    // Don't fail the payment plan if saving the method fails
                    Log::warning('Failed to save payment method after payment plan creation', [
                        'error' => $e->getMessage(),
                        'customer_id' => $customer->id,
                    ]);
                }
            }

            $this->paymentProcessed = true;
            $this->currentStep = Steps::CONFIRMATION;

        } catch (\Exception $e) {
            $this->addError('payment', 'Payment processing failed: '.$e->getMessage());
            Log::error('Payment processing exception', [
                'transaction_id' => $this->transactionId,
                'error' => $e->getMessage(),
                'client_id' => $this->clientInfo['client_id'],
            ]);

            return;
        }
    }

    /**
     * Build a ProcessPaymentCommand for the orchestrator from current component state.
     */
    private function buildOrchestratorCommand(\App\Models\Customer $customer): ProcessPaymentCommand
    {
        $invoiceDetails = collect($this->selectedInvoices)->map(function ($invoiceNumber) {
            $invoice = collect($this->openInvoices)->firstWhere('invoice_number', $invoiceNumber);

            return $invoice ? [
                'invoice_number' => $invoice['invoice_number'],
                'description' => $invoice['description'],
                'amount' => $invoice['open_amount'],
                'ledger_entry_KEY' => $invoice['ledger_entry_KEY'] ?? null,
                'open_amount' => $invoice['open_amount'],
                'client_KEY' => $invoice['client_KEY'] ?? null,
                'client_name' => $invoice['client_name'] ?? null,
                'client_id' => $invoice['client_id'] ?? null,
            ] : null;
        })->filter()->values()->toArray();

        if ($this->paymentMethod === 'credit_card') {
            return ProcessPaymentCommand::cardPayment(
                customer: $customer,
                amount: $this->paymentAmount,
                fee: $this->creditCardFee,
                clientInfo: $this->clientInfo,
                selectedInvoiceNumbers: $this->selectedInvoices,
                invoiceDetails: $invoiceDetails,
                openInvoices: $this->openInvoices,
                cardDetails: [
                    'number' => str_replace(' ', '', $this->cardNumber),
                    'exp_month' => (int) substr($this->cardExpiry, 0, 2),
                    'exp_year' => (int) ('20'.substr($this->cardExpiry, 3, 2)),
                    'cvc' => $this->cardCvv,
                    'name' => $this->clientInfo['client_name'],
                    'street' => '',
                    'zip_code' => '',
                    'email' => $this->clientInfo['email'] ?? '',
                ],
                engagements: $this->engagementsToPersist ?? [],
                sendReceipt: true,
                savePaymentMethod: $this->savePaymentMethod && ! $this->selectedSavedMethodId,
                paymentMethodNickname: $this->paymentMethodNickname ?? null,
            );
        }

        if ($this->paymentMethod === 'ach') {
            return ProcessPaymentCommand::achPayment(
                customer: $customer,
                amount: $this->paymentAmount,
                clientInfo: $this->clientInfo,
                selectedInvoiceNumbers: $this->selectedInvoices,
                invoiceDetails: $invoiceDetails,
                openInvoices: $this->openInvoices,
                achDetails: [
                    'routing_number' => $this->routingNumber,
                    'account_number' => $this->accountNumber,
                    'account_type' => $this->bankAccountType,
                    'account_name' => $this->clientInfo['client_name'],
                    'is_business' => (bool) $this->isBusiness,
                    'bank_name' => $this->bankName ?? null,
                ],
                engagements: $this->engagementsToPersist ?? [],
                sendReceipt: true,
                savePaymentMethod: $this->savePaymentMethod && ! $this->selectedSavedMethodId,
                paymentMethodNickname: $this->paymentMethodNickname ?? null,
            );
        }

        // Check payment
        return ProcessPaymentCommand::checkPayment(
            customer: $customer,
            amount: $this->paymentAmount,
            clientInfo: $this->clientInfo,
            selectedInvoiceNumbers: $this->selectedInvoices,
            invoiceDetails: $invoiceDetails,
            openInvoices: $this->openInvoices,
            engagements: $this->engagementsToPersist ?? [],
            sendReceipt: true,
        );
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

    /**
     * Skeleton placeholder shown instantly while the component lazy-loads.
     *
     * Mirrors the verify-account step layout: progress bar + card with form fields.
     */
    public function placeholder(): string
    {
        return <<<'HTML'
        <div class="max-w-4xl mx-auto py-8 px-4">
            {{-- Progress indicator skeleton --}}
            <div class="mb-8">
                <div class="sm:hidden">
                    <div class="flex items-center justify-between mb-2">
                        <flux:skeleton.line class="w-20" />
                        <flux:skeleton.line class="w-24" />
                    </div>
                    <flux:skeleton class="h-2 w-full rounded-full" />
                </div>
                <div class="hidden sm:block">
                    <div class="flex items-center justify-center">
                        @for ($i = 0; $i < 6; $i++)
                            <div class="flex items-center {{ $i < 5 ? 'flex-1' : '' }}">
                                <div class="flex flex-col items-center">
                                    <flux:skeleton class="size-10 rounded-full" />
                                    <flux:skeleton.line class="w-14 mt-2" />
                                </div>
                                @if ($i < 5)
                                    <div class="flex-1 mx-4">
                                        <flux:skeleton class="h-0.5 w-full" />
                                    </div>
                                @endif
                            </div>
                        @endfor
                    </div>
                </div>
            </div>

            {{-- Verify account step skeleton --}}
            <flux:card class="p-8">
                <flux:skeleton.group animate="shimmer">
                    <div class="mb-6 text-center">
                        <flux:skeleton class="h-7 w-56 mx-auto rounded mb-2" />
                        <flux:skeleton.line class="w-64 mx-auto" />
                    </div>

                    <div class="space-y-6 max-w-md mx-auto">
                        {{-- SSN field --}}
                        <div>
                            <flux:skeleton.line class="w-36 mb-2" />
                            <flux:skeleton class="h-10 w-full rounded-lg" />
                        </div>

                        {{-- Last name field --}}
                        <div>
                            <flux:skeleton.line class="w-20 mb-2" />
                            <flux:skeleton class="h-10 w-full rounded-lg" />
                            <flux:skeleton.line class="w-48 mt-2" />
                        </div>

                        {{-- Turnstile placeholder --}}
                        <flux:skeleton class="h-16 w-full rounded-lg" />

                        {{-- Continue button --}}
                        <div class="flex gap-3 pt-4">
                            <flux:skeleton class="h-10 w-full rounded-lg" />
                        </div>
                    </div>
                </flux:skeleton.group>
            </flux:card>
        </div>
        HTML;
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
