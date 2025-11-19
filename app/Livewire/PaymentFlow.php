<?php

// app/Livewire/PaymentFlow.php

namespace App\Livewire;

use Livewire\Component;
use App\Repositories\PaymentRepository;
use App\Services\PaymentService;
use App\Services\PaymentPlanCalculator;
    use App\Models\Customer;
    use App\Models\ProjectAcceptance;
    use Illuminate\Support\Facades\Log;

    class PaymentFlow extends Component
    {
        // Step tracking
        public $currentStep = 1;
        
        // Step 1: Account Type
        public $accountType = null; // 'business' or 'personal'
        
        // Step 2: Identification
        public $last4 = '';
        public $lastName = '';
        public $businessName = '';
        
        // Client data
        public $clientInfo = null;
        public $openInvoices = [];
        public $totalBalance = 0;
        
    // Project Acceptance
    public $pendingProjects = [];
    public $currentProjectIndex = 0;
    public $acceptTerms = false; // Changed from acceptanceSignature
    public $hasProjectsToAccept = false;
    public $projectsToPersist = []; // Stores accepted projects temporarily until payment is confirmed

    // Step 3: Payment Information
    public $selectedInvoices = []; // Array of selected invoice IDs/numbers
    public $paymentAmount = 0;
    public $paymentNotes = '';
    public $selectAll = false;
    
    // Sorting
    public $sortBy = 'due_date';
    public $sortDirection = 'asc';

    // Client grouping settings
    public $showRelatedInvoices = true;
    
    // Step 4: Payment Method
    public $paymentMethod = null; // 'credit_card', 'ach', 'check', 'payment_plan'
    public $creditCardFee = 0;
    
    // Payment Plan
    public $isPaymentPlan = false;
    public $planFrequency = 'monthly'; // 'weekly', 'biweekly', 'monthly', 'quarterly', 'semiannually', 'annually'
    public $planDuration = 3; // Number of payments
    public $downPayment = 0;
    public $planStartDate = null; // Custom start date for payments
    public $customAmounts = false; // Allow custom amounts per installment
    public $installmentAmounts = []; // Array of custom amounts
    public $paymentSchedule = [];
    public $paymentPlanFee = 0; // Fee for payment plans (3% of total)
    
    // Step 5: Payment Plan Authorization (for payment plans only)
    public $agreeToTerms = false;
    public $paymentMethodDetails = null; // Will store card/bank details
    public $cardNumber = '';
    public $cardExpiry = '';
    public $cardCvv = '';
    public $bankName = '';
    public $accountNumber = '';
    public $routingNumber = '';

    // Step 6: Confirmation
    public $transactionId = null;
    public $paymentProcessed = false;

    /**
     * Format card number as user types
     */
    public function updatedCardNumber()
    {
        // Remove all non-digits
        $number = preg_replace('/\D/', '', $this->cardNumber);

        // Add spaces every 4 digits
        $formatted = '';
        for ($i = 0; $i < strlen($number); $i++) {
            if ($i > 0 && $i % 4 === 0) {
                $formatted .= ' ';
            }
            $formatted .= $number[$i];
        }

        $this->cardNumber = $formatted;
    }

    /**
     * Format expiry date as user types
     */
    public function updatedCardExpiry()
    {
        // Remove all non-digits
        $expiry = preg_replace('/\D/', '', $this->cardExpiry);

        // Add slash after month
        if (strlen($expiry) >= 2) {
            $expiry = substr($expiry, 0, 2) . '/' . substr($expiry, 2, 2);
        }

        $this->cardExpiry = $expiry;
    }

    public function updatedPaymentMethod($value)
    {
        if ($value === 'credit_card') {
            // Calculate fee on total amount (Invoice + Plan Fee)
            $amountToTax = $this->paymentAmount + $this->paymentPlanFee;
            $this->creditCardFee = round($amountToTax * 0.03, 2);
        } else {
            $this->creditCardFee = 0;
        }
        
        // Recalculate schedule if we are in a plan
        if ($this->isPaymentPlan) {
            $this->calculatePaymentSchedule();
        }
    }

    protected PaymentRepository $paymentRepo;
    protected PaymentService $paymentService;
    protected PaymentPlanCalculator $planCalculator;

    public function boot(
        PaymentRepository $paymentRepo, 
        PaymentService $paymentService,
        PaymentPlanCalculator $planCalculator
    ) {
        $this->paymentRepo = $paymentRepo;
        $this->paymentService = $paymentService;
        $this->planCalculator = $planCalculator;
    }

    /**
     * Step 1: Select account type
     */
    public function selectAccountType($type)
    {
        $this->accountType = $type;
        $this->currentStep = 2;
        $this->resetValidation();
    }

    /**
     * Step 2: Verify account
     */
    public function verifyAccount()
    {
        // Validation
        if ($this->accountType === 'business') {
            $this->validate([
                'last4' => 'required|digits:4',
                'businessName' => 'required|string|max:250',
            ], [
                'last4.required' => 'Please enter the last 4 digits of your EIN',
                'last4.digits' => 'EIN must be exactly 4 digits',
                'businessName.required' => 'Please enter your business name',
            ]);
            
            $searchName = $this->businessName;
        } else {
            $this->validate([
                'last4' => 'required|digits:4',
                'lastName' => 'required|string|max:100',
            ], [
                'last4.required' => 'Please enter the last 4 digits of your SSN',
                'last4.digits' => 'SSN must be exactly 4 digits',
                'lastName.required' => 'Please enter your last name',
            ]);
            
            $searchName = $this->lastName;
        }

        // Lookup client
        $client = $this->paymentRepo->getClientByTaxIdAndName($this->last4, $searchName);
        
        if (!$client) {
            $this->addError('last4', 'No account found matching this information. Please check and try again.');
            return;
        }

        $this->clientInfo = $client;

        // NEW: Check for pending projects BEFORE loading invoices
        $this->checkForPendingProjects();
        
        if ($this->hasProjectsToAccept) {
            // Go to project acceptance step
            $this->currentStep = 3; // Renumber: 3 = Project Acceptance
        } else {
            // No projects to accept, load invoices normally
            $this->loadClientInvoices();
            $this->currentStep = 4; // Renumber: 4 = Invoice Selection
        }
    }

    /**
     * Load client invoices and balance
     */
    private function loadClientInvoices()
    {
        $clientKey = isset($this->clientInfo['clients'])
            ? $this->clientInfo['clients'][0]['client_KEY']
            : $this->clientInfo['client_KEY'];

        $result = $this->paymentRepo->getGroupedInvoicesForClient($clientKey, $this->clientInfo);

        $this->openInvoices = $result['openInvoices'];
        $this->totalBalance = $result['totalBalance'];

        // Sort invoices
        $this->sortInvoices();

        // Pre-select all invoices by default
        $this->selectedInvoices = collect($this->openInvoices)->pluck('invoice_number')->toArray();
        $this->calculatePaymentAmount();
    }

    /**
     * Check for pending projects for the client group
     */
    private function checkForPendingProjects()
    {
        $clientKey = isset($this->clientInfo['clients'])
            ? $this->clientInfo['clients'][0]['client_KEY']
            : $this->clientInfo['client_KEY'];
        
        $this->pendingProjects = $this->paymentRepo->getPendingProjectsForClientGroup($clientKey);
        $this->hasProjectsToAccept = count($this->pendingProjects) > 0;
        $this->currentProjectIndex = 0;
    }

    /**
     * Accept the current project
     */
    public function acceptProject()
    {
        // Validate checkbox
        $this->validate([
            'acceptTerms' => 'accepted',
        ], [
            'acceptTerms.accepted' => 'You must agree to the terms and conditions to continue.',
        ]);
        
        $currentProject = $this->pendingProjects[$this->currentProjectIndex];
        
        // Queue acceptance for persistence after payment
        $this->projectsToPersist[] = [
            'project_engagement_key' => $currentProject['engagement_KEY'],
            'client_key' => $currentProject['client_KEY'],
            'client_group_name' => $currentProject['group_name'] ?? null,
            'engagement_id' => $currentProject['engagement_id'],
            'project_name' => $currentProject['project_name'],
            'budget_amount' => $currentProject['budget_amount'],
            'accepted' => true,
            'accepted_at' => now(),
            'accepted_by_ip' => request()->ip(),
            'acceptance_signature' => 'Checkbox Accepted', // Static value for checkbox acceptance
        ];
        
        Log::info('Project acceptance queued', [
            'engagement_id' => $currentProject['engagement_id'],
            'client_key' => $currentProject['client_KEY'],
        ]);
        
        // Move to next project or continue to invoices
        $this->currentProjectIndex++;
        $this->acceptTerms = false; // Reset checkbox for next project
        
        if ($this->currentProjectIndex >= count($this->pendingProjects)) {
            // All projects accepted, proceed to invoice selection
            $this->loadClientInvoices();
            $this->addAcceptedProjectsAsInvoices();
            $this->currentStep = 4; // Invoice Selection
        }
        // else: Stay on step 3 to show next project
    }

    /**
     * Decline the current project
     */
    public function declineProject()
    {
        // Log the decline
        $currentProject = $this->pendingProjects[$this->currentProjectIndex];
        
        Log::info('Project declined', [
            'engagement_id' => $currentProject['engagement_id'],
            'client_key' => $currentProject['client_KEY'],
        ]);
        
        // Move to next project or continue
        $this->currentProjectIndex++;
        $this->acceptTerms = false; // Reset checkbox
        
        if ($this->currentProjectIndex >= count($this->pendingProjects)) {
            // All projects reviewed, proceed to invoices
            $this->loadClientInvoices();
            $this->addAcceptedProjectsAsInvoices();
            $this->currentStep = 4;
        }
    }

    /**
     * Add accepted projects as invoices to the list
     */
    private function addAcceptedProjectsAsInvoices()
    {
        // Add accepted projects as synthetic "invoices" to the invoice list
        foreach ($this->pendingProjects as $index => $project) {
            // Check if project is in the persistence queue
            $queuedAcceptance = collect($this->projectsToPersist)
                ->firstWhere('project_engagement_key', $project['engagement_KEY']);
            
            // Also check database for previously accepted projects (if any logic required it, though filtering happens earlier)
            // For this session, we rely on the queue.
            
            if ($queuedAcceptance) {
                $this->openInvoices[] = [
                    'ledger_entry_KEY' => 'project_' . $project['engagement_KEY'],
                    'invoice_number' => 'PROJECT-' . $project['engagement_id'],
                    'invoice_date' => $project['start_date'] ? date('m/d/Y', strtotime($project['start_date'])) : date('m/d/Y'),
                    'due_date' => 'Upon Acceptance',
                    'type' => 'Project Budget',
                    'open_amount' => number_format($project['budget_amount'], 2, '.', ''),
                    'description' => $project['project_name'],
                    'client_name' => $project['client_name'],
                    'client_id' => $project['client_id'] ?? '',
                    'client_KEY' => $project['client_KEY'],
                    'is_project' => true, // Flag to identify as project
                    'engagement_id' => $project['engagement_id'],
                ];
                
                // Pre-select the project invoice
                $this->selectedInvoices[] = 'PROJECT-' . $project['engagement_id'];
            }
        }
        
        // Recalculate total
        $this->calculatePaymentAmount();
    }

    /**
     * Toggle display of related client invoices
     */
    public function toggleRelatedInvoices()
    {
        $this->showRelatedInvoices = !$this->showRelatedInvoices;
        $this->loadClientInvoices(); // Reload invoices with new setting
    }
    
    /**
     * Sort invoices
     */
    public function sort($column)
    {
        if ($this->sortBy === $column) {
            $this->sortDirection = $this->sortDirection === 'asc' ? 'desc' : 'asc';
        } else {
            $this->sortBy = $column;
            $this->sortDirection = 'asc';
        }
        
        $this->sortInvoices();
    }
    
    /**
     * Apply sorting to invoices
     */
    private function sortInvoices()
    {
        if (empty($this->openInvoices)) {
            return;
        }
        
        $sortBy = $this->sortBy;
        $direction = $this->sortDirection;
        
        usort($this->openInvoices, function($a, $b) use ($sortBy, $direction) {
            $aVal = $a[$sortBy] ?? '';
            $bVal = $b[$sortBy] ?? '';
            
            // Handle numeric values
            if ($sortBy === 'open_amount') {
                $aVal = (float)$aVal;
                $bVal = (float)$bVal;
            }
            
            // Handle dates
            if (in_array($sortBy, ['invoice_date', 'due_date'])) {
                // Handle 'N/A' dates by treating them as very old dates for sorting
                $aVal = ($aVal === 'N/A' || empty($aVal)) ? 0 : strtotime($aVal);
                $bVal = ($bVal === 'N/A' || empty($bVal)) ? 0 : strtotime($bVal);
            }
            
            $result = $aVal <=> $bVal;
            
            return $direction === 'asc' ? $result : -$result;
        });
    }
    
    /**
     * Toggle invoice selection
     */
    public function toggleInvoice($invoiceNumber)
    {
        // Don't allow selecting placeholder invoices (clients with no actual invoices)
        $invoice = collect($this->openInvoices)->firstWhere('invoice_number', $invoiceNumber);
        if ($invoice && isset($invoice['is_placeholder']) && $invoice['is_placeholder']) {
            return; // Don't select placeholder invoices
        }

        if (in_array($invoiceNumber, $this->selectedInvoices)) {
            $this->selectedInvoices = array_diff($this->selectedInvoices, [$invoiceNumber]);
        } else {
            $this->selectedInvoices[] = $invoiceNumber;
        }

        $this->calculatePaymentAmount();
    }
    
    /**
     * Toggle select all invoices
     */
    public function toggleSelectAll()
    {
        $selectableInvoices = collect($this->openInvoices)->where(function($invoice) {
            return !isset($invoice['is_placeholder']) || !$invoice['is_placeholder'];
        });

        // Check if currently all are selected
        $allSelected = count($this->selectedInvoices) === $selectableInvoices->count();

        if ($allSelected) {
            // Deselect all
            $this->selectedInvoices = [];
            $this->selectAll = false;
        } else {
            // Select all non-placeholder invoices
            $this->selectedInvoices = $selectableInvoices->pluck('invoice_number')->toArray();
            $this->selectAll = true;
        }
        
        $this->calculatePaymentAmount();
    }

    /**
     * Update selectAll state when selection changes
     */
    public function updatedSelectedInvoices()
    {
        $selectableCount = collect($this->openInvoices)->where(function($invoice) {
            return !isset($invoice['is_placeholder']) || !$invoice['is_placeholder'];
        })->count();

        $this->selectAll = count($this->selectedInvoices) > 0 && count($this->selectedInvoices) === $selectableCount;
        $this->calculatePaymentAmount();
    }

    /**
     * Handle selectAll toggle via wire:model
     */
    public function updatedSelectAll($value)
    {
        $this->toggleSelectAll();
    }
    
    /**
     * Calculate payment amount based on selected invoices
     */
    public function calculatePaymentAmount()
    {
        $total = 0;
        
        foreach ($this->openInvoices as $invoice) {
            if (in_array($invoice['invoice_number'], $this->selectedInvoices)) {
                $total += (float)$invoice['open_amount'];
            }
        }
        
        $this->paymentAmount = $total;
    }

    /**
     * Step 3: Save payment information
     */
    public function savePaymentInfo()
    {
        // Validate that at least one invoice is selected
        if (count($this->selectedInvoices) === 0) {
            $this->addError('selectedInvoices', 'Please select at least one invoice to pay.');
            return;
        }

        // Calculate max allowed payment amount
        $selectedTotal = collect($this->openInvoices)
            ->whereIn('invoice_number', $this->selectedInvoices)
            ->sum('open_amount');

        $this->validate([
            'paymentAmount' => 'required|numeric|min:0.01|max:' . $selectedTotal,
        ], [
            'paymentAmount.required' => 'Please enter a payment amount',
            'paymentAmount.min' => 'Payment amount must be at least $0.01',
            'paymentAmount.max' => 'Payment amount cannot exceed the selected invoices total ($' . number_format($selectedTotal, 2) . ')',
        ]);

        $this->currentStep = 5;
    }

    /**
     * Step 4: Payment Method
     */
    public function selectPaymentMethod($method)
    {
        $this->paymentMethod = $method;

        // Calculate credit card fee
        if ($method === 'credit_card') {
            $this->creditCardFee = $this->paymentAmount * 0.03;
        } else {
            $this->creditCardFee = 0;
        }

        // If payment plan, stay on step 4 to show configuration
        if ($method === 'payment_plan') {
            $this->isPaymentPlan = true;
            // Initialize default down payment (20%)
            $this->downPayment = round($this->paymentAmount * 0.20, 2);
            $this->planStartDate = now()->addDay()->format('Y-m-d');
            // Calculate payment plan fee based on terms
            $this->calculatePaymentPlanFee();
            $this->calculatePaymentSchedule();
            // Stay on step 4 to show plan configuration
        } else {
            $this->isPaymentPlan = false;
            $this->paymentPlanFee = 0;

            // Proceed to step 5 to collect payment details
            $this->currentStep = 6;
        }
    }
    
    /**
     * Calculate payment plan fee using the Calculator Service
     */
    public function calculatePaymentPlanFee()
    {
        if (!$this->isPaymentPlan) {
            $this->paymentPlanFee = 0;
            return;
        }

        $result = $this->planCalculator->calculateFee(
            $this->paymentAmount,
            $this->downPayment,
            $this->planDuration,
            $this->planFrequency
        );

        $this->paymentPlanFee = $result['fee_amount'];
        
        // Update Credit Card Fee to include the plan fee if paying by card
        if ($this->paymentMethod === 'credit_card') {
            $this->creditCardFee = ($this->paymentAmount + $this->paymentPlanFee) * 0.03;
            $this->creditCardFee = round($this->creditCardFee, 2);
        }
    }

    /**
     * Confirm payment plan and proceed
     */
    public function confirmPaymentPlan()
    {
        // Get dynamic max installments based on frequency
        $maxInstallments = $this->planCalculator->getMaxInstallments($this->planFrequency);

        // Validate payment plan
        $this->validate([
            'downPayment' => 'required|numeric|min:0|max:' . $this->paymentAmount,
            'planDuration' => "required|integer|min:2|max:$maxInstallments",
            'planStartDate' => 'nullable|date|after_or_equal:today',
        ], [
            'downPayment.required' => 'Please enter a down payment amount',
            'downPayment.max' => 'Down payment cannot exceed total amount',
            'planDuration.min' => 'Payment plan must have at least 2 installments',
            'planDuration.max' => "Payment plan cannot exceed $maxInstallments installments for this frequency",
            'planStartDate.after_or_equal' => 'Start date cannot be in the past',
        ]);

        // Validate custom amounts if enabled
        if ($this->customAmounts) {
            $totalCustom = array_sum($this->installmentAmounts);
            // Calculate total including fee
            $totalAmount = $this->paymentAmount + $this->paymentPlanFee;
            if ($this->paymentMethod === 'credit_card') {
                $totalAmount += $this->creditCardFee;
            }
            $remainingBalance = $totalAmount - $this->downPayment;

            if (abs($totalCustom - $remainingBalance) > 0.01) {
                $this->addError('customAmounts', 'Custom installment amounts must total to the remaining balance: $' . number_format($remainingBalance, 2));
                return;
            }
        }
        
        $this->calculatePaymentSchedule();
        $this->currentStep = 6; // Go to authorization step
    }
    
    /**
     * Calculate payment schedule using Calculator Service
     */
    public function calculatePaymentSchedule()
    {
        // Calculate total amount including payment plan fee and credit card fee
        $totalAmount = $this->paymentAmount + $this->paymentPlanFee;
        
        // If paying by credit card, include the fee in the scheduled amount
        if ($this->paymentMethod === 'credit_card') {
            $totalAmount += $this->creditCardFee;
        }

        $this->paymentSchedule = $this->planCalculator->calculateSchedule(
            $totalAmount,
            $this->downPayment,
            $this->planDuration,
            $this->planFrequency,
            $this->planStartDate,
            $this->customAmounts ? $this->installmentAmounts : []
        );
    }
    
    /**
     * Update payment schedule when plan settings change
     */
    public function updatedPlanFrequency()
    {
        // Reset duration to valid range if needed
        $max = $this->planCalculator->getMaxInstallments($this->planFrequency);
        if ($this->planDuration > $max) {
            $this->planDuration = $max;
        }
        
        $this->calculatePaymentPlanFee();
        $this->calculatePaymentSchedule();
    }

    public function updatedPlanDuration()
    {
        $this->calculatePaymentPlanFee();
        $this->calculatePaymentSchedule();
    }
    
    public function updatedDownPayment()
    {
        $this->calculatePaymentPlanFee();
        $this->calculatePaymentSchedule();
    }

    /**
     * When customAmounts checkbox is toggled
     */
    public function updatedCustomAmounts($value)
    {
        if ($value) {
            $this->initializeCustomAmounts();
        } else {
            $this->installmentAmounts = [];
        }
        $this->calculatePaymentSchedule();
    }

    /**
     * Toggle custom amounts mode
     */
    public function toggleCustomAmounts()
    {
        $this->customAmounts = !$this->customAmounts;
        if ($this->customAmounts) {
            $this->initializeCustomAmounts();
        } else {
            $this->installmentAmounts = [];
        }
        $this->calculatePaymentSchedule();
    }

    /**
     * Initialize custom amounts array with equal installments
     */
    private function initializeCustomAmounts()
    {
        // Include payment plan fee in total
        $totalAmount = $this->paymentAmount + $this->paymentPlanFee;
        
        // If paying by credit card, include the fee
        if ($this->paymentMethod === 'credit_card') {
            $totalAmount += $this->creditCardFee;
        }

        $remainingBalance = $totalAmount - $this->downPayment;

        $this->installmentAmounts = $this->planCalculator->getEqualInstallments(
            $remainingBalance,
            $this->planDuration
        );
    }

    /**
     * Update custom installment amount
     */
    public function updateInstallmentAmount($index, $amount)
    {
        if (isset($this->installmentAmounts[$index])) {
            $this->installmentAmounts[$index] = (float)$amount;
            $this->calculatePaymentSchedule();
        }
    }

    /**
     * Step 5: Authorize payment plan
     */
    public function authorizePaymentPlan()
    {
        // Validate terms agreement
        $this->validate([
            'agreeToTerms' => 'accepted',
        ], [
            'agreeToTerms.accepted' => 'You must agree to the terms and conditions to continue.',
        ]);

            // Validate payment method details based on selected method
        if ($this->paymentMethod === 'credit_card') {
            $this->validate([
                'cardNumber' => ['required', 'string', 'regex:/^\d{4}\s\d{4}\s\d{4}\s\d{4}$/'],
                'cardExpiry' => ['required', 'string', 'regex:/^(0[1-9]|1[0-2])\/\d{2}$/'],
                'cardCvv' => ['required', 'string', 'regex:/^\d{3,4}$/'],
            ], [
                'cardNumber.required' => 'Credit card number is required',
                'cardNumber.regex' => 'Please enter a valid credit card number (XXXX XXXX XXXX XXXX)',
                'cardExpiry.required' => 'Expiration date is required',
                'cardExpiry.regex' => 'Please enter a valid expiration date (MM/YY)',
                'cardCvv.required' => 'CVV is required',
                'cardCvv.regex' => 'Please enter a valid CVV (3-4 digits)',
            ]);
        } elseif ($this->paymentMethod === 'ach') {
            $this->validate([
                'bankName' => 'required|string|max:100',
                'accountNumber' => ['required', 'string', 'regex:/^\d{8,17}$/'],
                'routingNumber' => ['required', 'string', 'regex:/^\d{9}$/'],
            ], [
                'bankName.required' => 'Bank name is required',
                'accountNumber.required' => 'Account number is required',
                'accountNumber.regex' => 'Please enter a valid account number (8-17 digits)',
                'routingNumber.required' => 'Routing number is required',
                'routingNumber.regex' => 'Please enter a valid routing number (9 digits)',
            ]);
        }

        // For payment plans, create setup intent for saving payment method (MiPaymentChoice)
        if ($this->isPaymentPlan) {
            $setupIntentResult = $this->paymentService->createSetupIntent($this->clientInfo);

            if (!$setupIntentResult['success']) {
                $this->addError('payment_method', 'Failed to initialize payment plan setup: ' . $setupIntentResult['error']);
                return;
            }

            $this->paymentMethodDetails = [
                'type' => $this->paymentMethod === 'credit_card' ? 'card_token' : 'ach_token',
                'customer_id' => $setupIntentResult['customer_id'],
                'ready_for_tokenization' => true,
            ];
        } else {
            // Store payment method details (will be tokenized with MiPaymentChoice)
            $this->paymentMethodDetails = [
                'method' => $this->paymentMethod,
                'card_number' => $this->paymentMethod === 'credit_card' ? '**** **** **** ' . substr(str_replace(' ', '', $this->cardNumber), -4) : null,
                'card_expiry' => $this->cardExpiry,
                'bank_name' => $this->bankName,
                'account_number' => $this->paymentMethod === 'ach' ? '****' . substr($this->accountNumber, -4) : null,
                'routing_number' => $this->paymentMethod === 'ach' ? '****' . substr($this->routingNumber, -4) : null,
            ];
        }

        // Proceed to confirmation
        $this->currentStep = 7;
        $this->transactionId = 'mpc_plan_' . uniqid();
    }

    /**
     * Go back to previous step
     */
    public function goBack()
    {
        if ($this->currentStep > 1) {
            $this->currentStep--;

            // Special handling based on current step after decrementing
            if ($this->currentStep === 5 && $this->isPaymentPlan) {
                // Going back to authorization from confirmation - keep plan settings
                // No special handling needed, just clear transaction ID
                $this->transactionId = null;
            } elseif ($this->currentStep === 4 && $this->isPaymentPlan) {
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
     * Change payment method entirely - go back to method selection
     */
    public function changePaymentMethod()
    {
        // Go back to step 4 and reset payment plan state completely
        $this->currentStep = 4;
        $this->resetValidation();

        // Reset to payment method selection
        $this->isPaymentPlan = false;
        $this->paymentMethod = null;
        $this->downPayment = 0;
        $this->planFrequency = 'monthly';
        $this->planDuration = 3;
        $this->planStartDate = null;
        $this->customAmounts = false;
        $this->installmentAmounts = [];
        $this->paymentSchedule = [];

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
    public function editPaymentPlan()
    {
        if ($this->isPaymentPlan) {
            // Go back to step 4 (plan configuration) but keep plan settings
            $this->currentStep = 4;
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
    public function confirmPayment()
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
            ], [
                'routingNumber.required' => 'Routing number is required',
                'routingNumber.digits' => 'Routing number must be exactly 9 digits',
                'accountNumber.required' => 'Account number is required',
                'accountNumber.digits_between' => 'Account number must be 8-17 digits',
                'bankName.required' => 'Bank name is required',
            ]);
        }
        
        // Generate transaction ID if not already set
        if (!$this->transactionId) {
            $this->transactionId = ($this->isPaymentPlan ? 'plan_' : 'txn_') . uniqid();
        }

        // Prepare payment data
        $paymentData = [
            'amount' => $this->paymentAmount,
            'paymentMethod' => $this->paymentMethod,
            'fee' => $this->creditCardFee,
            'isPaymentPlan' => $this->isPaymentPlan,
            'planFrequency' => $this->planFrequency,
            'planDuration' => $this->planDuration,
            'downPayment' => $this->downPayment,
            'paymentSchedule' => $this->paymentSchedule,
            'planId' => $this->transactionId,
            'invoices' => collect($this->selectedInvoices)->map(function($invoiceNumber) {
                $invoice = collect($this->openInvoices)->firstWhere('invoice_number', $invoiceNumber);
                return $invoice ? [
                    'invoice_number' => $invoice['invoice_number'],
                    'description' => $invoice['description'],
                    'amount' => $invoice['open_amount']
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
                if ($this->paymentMethod === 'credit_card') {
                    // Create reusable card token
                    $token = $customer->tokenizeCard([
                        'number' => str_replace(' ', '', $this->cardNumber),
                        'exp_month' => (int)substr($this->cardExpiry, 0, 2),
                        'exp_year' => (int)('20' . substr($this->cardExpiry, 3, 2)),
                        'cvc' => $this->cardCvv,
                        'name' => $this->clientInfo['client_name'],
                        'street' => '', // Could add billing address fields
                        'postal_code' => '',
                    ]);
                } else {
                    // Create reusable check token
                    $token = $customer->tokenizeCheck([
                        'routing_number' => $this->routingNumber,
                        'account_number' => $this->accountNumber,
                        'name' => $this->clientInfo['client_name'],
                        'account_type' => 'Checking',
                        'check_type' => 'Personal',
                    ]);
                }
                
                $paymentResult = $this->paymentService->setupPaymentPlan(
                    array_merge($paymentData, [
                        'payment_type' => $this->paymentMethod === 'credit_card' ? 'card' : 'check',
                    ]),
                    $this->clientInfo,
                    $token
                );
            } else {
                // For one-time payments, process immediately
                if ($this->paymentMethod === 'credit_card') {
                    // Create QuickPayments token and charge
                    $qpToken = $customer->createQuickPaymentsToken([
                        'number' => str_replace(' ', '', $this->cardNumber),
                        'exp_month' => (int)substr($this->cardExpiry, 0, 2),
                        'exp_year' => (int)('20' . substr($this->cardExpiry, 3, 2)),
                        'cvc' => $this->cardCvv,
                        'name' => $this->clientInfo['client_name'],
                        'street' => '',
                        'zip_code' => '',
                        'email' => $this->clientInfo['email'] ?? '',
                    ]);
                    
                    $paymentResult = $this->paymentService->chargeWithQuickPayments(
                        $customer,
                        $qpToken,
                        $this->paymentAmount + $this->creditCardFee,
                        [
                            'description' => "Payment for {$this->clientInfo['client_name']} - " . count($this->selectedInvoices) . " invoice(s)",
                        ]
                    );
                } elseif ($this->paymentMethod === 'ach') {
                    // Create QuickPayments check token and charge
                    $qpToken = $customer->createQuickPaymentsTokenFromCheck([
                        'routing_number' => $this->routingNumber,
                        'account_number' => $this->accountNumber,
                        'name' => $this->clientInfo['client_name'],
                        'check_type' => 'Personal',
                        'account_type' => 'Checking',
                        'sec_code' => 'WEB',
                        'address' => [
                            'street' => '',
                            'city' => '',
                            'state' => '',
                            'zip' => '',
                            'country' => 'USA',
                        ],
                    ]);
                    
                    $paymentResult = $this->paymentService->chargeWithQuickPayments(
                        $customer,
                        $qpToken,
                        $this->paymentAmount,
                        [
                            'description' => "ACH Payment for {$this->clientInfo['client_name']}",
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

            if (!$paymentResult || !$paymentResult['success']) {
                $error = $paymentResult['error'] ?? 'Payment processing failed';
                $this->addError('payment', $error);
                Log::error('Payment processing failed', [
                    'transaction_id' => $this->transactionId,
                    'error' => $error,
                    'client_id' => $this->clientInfo['client_id'],
                ]);
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

        } catch (\Exception $e) {
            $this->addError('payment', 'Payment processing failed: ' . $e->getMessage());
            Log::error('Payment processing exception', [
                'transaction_id' => $this->transactionId,
                'error' => $e->getMessage(),
                'client_id' => $this->clientInfo['client_id'],
            ]);
            return;
        }

        // Send payment receipt email
        try {
            \Illuminate\Support\Facades\Mail::to('client@example.com') // TODO: Get actual client email
                ->send(new \App\Mail\PaymentReceipt($paymentData, $this->clientInfo, $this->transactionId));

            // Log successful email send
            \Illuminate\Support\Facades\Log::info('Payment receipt email sent', [
                'transaction_id' => $this->transactionId,
                'client_id' => $this->clientInfo['client_id'],
                'amount' => $paymentData['amount']
            ]);
        } catch (\Exception $e) {
            // Log email failure but don't block payment completion
            \Illuminate\Support\Facades\Log::error('Failed to send payment receipt email', [
                'transaction_id' => $this->transactionId,
                'error' => $e->getMessage()
            ]);
        }

        // Persist accepted projects
        $this->persistAcceptedProjects();

        // Mark as completed
        $this->paymentProcessed = true;
        $this->currentStep = 6; // Success step (view handles success state)
    }
    
    /**
     * Persist queued accepted projects to the database
     */
    private function persistAcceptedProjects()
    {
        foreach ($this->projectsToPersist as $project) {
            // Check if already persisted to avoid duplicates
            $exists = ProjectAcceptance::where('project_engagement_key', $project['project_engagement_key'])
                ->exists();
                
            if (!$exists) {
                ProjectAcceptance::create($project);
                
                Log::info('Project acceptance persisted after payment', [
                    'engagement_id' => $project['engagement_id'],
                    'client_key' => $project['client_key'],
                ]);
            }
        }
        
        // Clear the queue
        $this->projectsToPersist = [];
    }

    /**
     * Start over
     */
    public function startOver()
    {
        $this->reset();
        $this->currentStep = 1;
    }

    public function render()
    {
        return view('livewire.payment-flow')->layout('layouts.app');
    }
}
