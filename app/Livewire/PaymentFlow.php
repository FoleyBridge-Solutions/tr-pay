<?php

// app/Livewire/PaymentFlow.php

namespace App\Livewire;

use Livewire\Component;
use Livewire\Attributes\Layout;
use App\Repositories\PaymentRepository;
use App\Services\PaymentService;
use App\Services\PaymentPlanCalculator;
use App\Services\EngagementAcceptanceService;
use App\Livewire\PaymentFlow\Steps;
use App\Livewire\PaymentFlow\Navigator;
use App\Models\ProjectAcceptance;
use Illuminate\Support\Facades\Log;

#[Layout('layouts.app')]
class PaymentFlow extends Component
{
        // Step tracking - now using named steps
        public string $currentStep = 'account-type'; // Steps::ACCOUNT_TYPE
        public array $stepHistory = []; // Stack of previous steps for back navigation
        
        // Step 1: Account Type
        public $accountType = null; // 'business' or 'personal'
        
        // Step 2: Identification
        public $last4 = '';
        public $lastName = '';
        public $businessName = '';
        public $loadingInvoices = false; // For skeleton loading state
        
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
    public $clientSelectAll = []; // Per-client "Pay All" toggle states (keyed by sanitized client name)
    public $clientNameMap = []; // Maps sanitized keys to real client names
    
    // Sorting
    public $sortBy = 'due_date';
    public $sortDirection = 'asc';

    // Client grouping settings
    public $showRelatedInvoices = true;
    
    // Step 4: Payment Method
    public $paymentMethod = null; // 'credit_card', 'ach', 'check', 'payment_plan'
    public $creditCardFee = 0;
    
    // Payment Plan (Simplified: 3, 6, or 9 months only)
    public $isPaymentPlan = false;
    public $planDuration = 3; // Number of months (3, 6, or 9)
    public $paymentSchedule = [];
    public $paymentPlanFee = 0; // Fee for payment plans ($150, $300, or $450)
    public $availablePlans = []; // Available plan options with fees
    
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

    /**
     * Format CVV as user types (numbers only)
     */
    public function updatedCardCvv()
    {
        // Remove all non-digits and limit to 4 characters
        $cvv = preg_replace('/\D/', '', $this->cardCvv);
        $this->cardCvv = substr($cvv, 0, 4);
    }

    public function updatedPaymentMethod($value)
    {
        if ($value === 'credit_card') {
            // Calculate fee on total amount (Invoice + Plan Fee)
            $amountToTax = $this->paymentAmount + $this->paymentPlanFee;
            $this->creditCardFee = round($amountToTax * config('payment-fees.credit_card_rate'), 2);
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
    public function selectAccountType($type)
    {
        $this->accountType = $type;
        $this->goToStep(Steps::VERIFY_ACCOUNT);
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

        // Dispatch success toast
        $this->dispatch('toast', 
            text: 'Successfully verified!',
            type: 'success'
        );

        // Set loading state for skeleton
        $this->loadingInvoices = true;

        // Check for pending projects BEFORE loading invoices
        $this->checkForPendingProjects();
        
        if ($this->hasProjectsToAccept) {
            // Go to project acceptance step
            $this->goToStep(Steps::PROJECT_ACCEPTANCE);
            $this->loadingInvoices = false;
        } else {
            // Show loading skeleton - onSkeletonComplete will load invoices
            $this->goToStep(Steps::LOADING_INVOICES);
        }
    }
    
    /**
     * Load invoices (called from frontend after skeleton is shown)
     */
    public function loadInvoicesData()
    {
        $this->loadClientInvoices();
        $this->loadingInvoices = false;
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

        // Pre-select all invoices by default (excluding placeholders)
        $this->selectedInvoices = collect($this->openInvoices)
            ->filter(function($invoice) {
                return !isset($invoice['is_placeholder']) || !$invoice['is_placeholder'];
            })
            ->pluck('invoice_number')
            ->map(fn($num) => (string)$num)
            ->toArray();
        $this->selectAll = true; // Set toggle to match pre-selected state
        
        // Initialize per-client toggle states
        $this->updateClientToggleStates();
        
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
            // Reset index to last project so user can go back if needed
            $this->currentProjectIndex = count($this->pendingProjects) - 1;
            $this->loadClientInvoices();
            $this->addAcceptedProjectsAsInvoices();
            $this->goToStep(Steps::INVOICE_SELECTION);
        }
        // else: Stay on project acceptance to show next project
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
            $this->goToStep(Steps::INVOICE_SELECTION);
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
     * Toggle individual invoice selection
     */
    public function toggleInvoice($invoiceNumber)
    {
        // Ensure string type for consistency
        $invoiceNumber = (string)$invoiceNumber;
        
        // Don't allow selecting placeholder invoices (clients with no actual invoices)
        $invoice = collect($this->openInvoices)->firstWhere('invoice_number', $invoiceNumber);
        if ($invoice && isset($invoice['is_placeholder']) && $invoice['is_placeholder']) {
            return; // Don't select placeholder invoices
        }

        if (in_array($invoiceNumber, $this->selectedInvoices, true)) {
            $this->selectedInvoices = array_values(array_diff($this->selectedInvoices, [$invoiceNumber]));
        } else {
            $this->selectedInvoices[] = $invoiceNumber;
        }

        $this->updatedSelectedInvoices();
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
            // Select all non-placeholder invoices (cast to string)
            $this->selectedInvoices = $selectableInvoices->pluck('invoice_number')
                ->map(fn($num) => (string)$num)
                ->toArray();
            $this->selectAll = true;
        }
        
        $this->updatedSelectedInvoices();
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
        
        // Update per-client toggle states
        $this->updateClientToggleStates();
        
        $this->calculatePaymentAmount();
    }

    /**
     * Update all client toggle states based on current selection
     */
    private function updateClientToggleStates()
    {
        $invoicesByClient = collect($this->openInvoices)->groupBy('client_name');
        
        foreach ($invoicesByClient as $clientName => $clientInvoices) {
            // Sanitize client name for use as array key
            $sanitizedKey = $this->sanitizeClientKey($clientName);
            
            // Store mapping from sanitized key to real client name
            $this->clientNameMap[$sanitizedKey] = $clientName;
            
            $selectableInvoices = $clientInvoices->where(function($invoice) {
                return !isset($invoice['is_placeholder']) || !$invoice['is_placeholder'];
            });
            
            // Cast invoice numbers to strings for consistent comparison
            $clientInvoiceNumbers = $selectableInvoices->pluck('invoice_number')
                ->map(fn($num) => (string)$num)
                ->toArray();
            
            if (!empty($clientInvoiceNumbers)) {
                $selectedCount = count(array_intersect($this->selectedInvoices, $clientInvoiceNumbers));
                $this->clientSelectAll[$sanitizedKey] = $selectedCount === count($clientInvoiceNumbers);
            }
        }
    }

    /**
     * Handle selectAll toggle via wire:model
     */
    public function updatedSelectAll($value)
    {
        $this->toggleSelectAll();
    }

    /**
     * Sanitize client name to create a valid array key for wire:model
     * Uses md5 hash to handle spaces and special characters
     */
    private function sanitizeClientKey($clientName)
    {
        return md5($clientName);
    }

    /**
     * Toggle all invoices for a specific client
     */
    public function toggleClientSelectAll($clientKey)
    {
        // Get real client name from map
        $clientName = $this->clientNameMap[$clientKey] ?? null;
        
        if (!$clientName) {
            // Key not found, rebuild the map and try again
            $this->updateClientToggleStates();
            $clientName = $this->clientNameMap[$clientKey] ?? null;
            
            if (!$clientName) {
                return; // Still invalid, abort
            }
        }
        
        // Get all selectable invoices for this client (cast to strings)
        $clientInvoices = collect($this->openInvoices)
            ->where('client_name', $clientName)
            ->where(function($invoice) {
                return !isset($invoice['is_placeholder']) || !$invoice['is_placeholder'];
            });

        $clientInvoiceNumbers = $clientInvoices->pluck('invoice_number')
            ->map(fn($num) => (string)$num)
            ->toArray();
        
        // Check current state
        $allSelected = !empty($clientInvoiceNumbers) && 
                      count(array_intersect($this->selectedInvoices, $clientInvoiceNumbers)) === count($clientInvoiceNumbers);
        
        if ($allSelected) {
            // Deselect all client invoices
            $this->selectedInvoices = array_values(array_diff($this->selectedInvoices, $clientInvoiceNumbers));
        } else {
            // Select all client invoices
            $this->selectedInvoices = array_values(array_unique(array_merge($this->selectedInvoices, $clientInvoiceNumbers)));
        }

        $this->updatedSelectedInvoices();
    }
    
    /**
     * Update per-client toggle states when changed via wire:model
     * Livewire calls this automatically when clientSelectAll.{sanitizedKey} changes
     */
    public function updatedClientSelectAll($value, $sanitizedKey)
    {
        // Get real client name from map
        $clientName = $this->clientNameMap[$sanitizedKey] ?? null;
        
        if (!$clientName) {
            // Key not found, rebuild the map and try again
            $this->updateClientToggleStates();
            $clientName = $this->clientNameMap[$sanitizedKey] ?? null;
            
            if (!$clientName) {
                return; // Still invalid, abort
            }
        }
        
        // Get all selectable invoices for this client (cast to string)
        $clientInvoices = collect($this->openInvoices)
            ->where('client_name', $clientName)
            ->where(function($invoice) {
                return !isset($invoice['is_placeholder']) || !$invoice['is_placeholder'];
            });

        $clientInvoiceNumbers = $clientInvoices->pluck('invoice_number')
            ->map(fn($num) => (string)$num)
            ->toArray();
        
        if ($value) {
            // Select all client invoices
            $this->selectedInvoices = array_values(array_unique(array_merge($this->selectedInvoices, $clientInvoiceNumbers)));
        } else {
            // Deselect all client invoices
            $this->selectedInvoices = array_values(array_diff($this->selectedInvoices, $clientInvoiceNumbers));
        }

        $this->updatedSelectedInvoices();
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

        $this->goToStep(Steps::PAYMENT_METHOD);
    }

    /**
     * Payment Method Selection
     */
    public function selectPaymentMethod($method)
    {
        $this->paymentMethod = $method;

        // Calculate credit card fee
        if ($method === 'credit_card') {
            $this->creditCardFee = $this->paymentAmount * config('payment-fees.credit_card_rate');
        } else {
            $this->creditCardFee = 0;
        }

        // If payment plan, stay on payment method step to show plan selection
        if ($method === 'payment_plan') {
            $this->isPaymentPlan = true;
            // Generate available plan options (3, 6, 9 months)
            $this->availablePlans = $this->planCalculator->getAvailablePlans($this->paymentAmount);
            // Default to 3 month plan
            $this->planDuration = 3;
            $this->calculatePaymentPlanFee();
            $this->calculatePaymentSchedule();
            // Stay on payment method step to show plan selection
        } else {
            $this->isPaymentPlan = false;
            $this->paymentPlanFee = 0;

            // Proceed to payment details
            $this->goToStep(Steps::PAYMENT_DETAILS);
        }
    }
    
    /**
     * Select a payment plan duration (3, 6, or 9 months)
     */
    public function selectPlanDuration(int $months)
    {
        if (!$this->planCalculator->isValidDuration($months)) {
            $this->addError('planDuration', 'Please select a valid plan duration.');
            return;
        }
        
        $this->planDuration = $months;
        $this->calculatePaymentPlanFee();
        $this->calculatePaymentSchedule();
    }

    /**
     * Calculate payment plan fee (simple flat fee based on duration)
     */
    public function calculatePaymentPlanFee()
    {
        if (!$this->isPaymentPlan) {
            $this->paymentPlanFee = 0;
            return;
        }

        $this->paymentPlanFee = $this->planCalculator->getFee($this->planDuration);
        
        // Update Credit Card Fee to include the plan fee if paying by card
        if ($this->paymentMethod === 'credit_card') {
            $this->creditCardFee = ($this->paymentAmount + $this->paymentPlanFee) * config('payment-fees.credit_card_rate');
            $this->creditCardFee = round($this->creditCardFee, 2);
        }
    }

    /**
     * Confirm payment plan and proceed to payment details
     */
    public function confirmPaymentPlan()
    {
        // Validate plan duration is valid (3, 6, or 9 months)
        if (!$this->planCalculator->isValidDuration($this->planDuration)) {
            $this->addError('planDuration', 'Please select a valid payment plan (3, 6, or 9 months).');
            return;
        }
        
        $this->calculatePaymentSchedule();
        $this->goToStep(Steps::PAYMENT_PLAN_AUTH);
    }
    
    /**
     * Calculate payment schedule (simple monthly payments)
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
            0, // No down payment
            $this->planDuration,
            'monthly',
            null,
            []
        );
    }

    /**
     * Update when plan duration changes
     */
    public function updatedPlanDuration()
    {
        $this->calculatePaymentPlanFee();
        $this->calculatePaymentSchedule();
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
        $this->goToStep(Steps::CONFIRMATION);
        $this->transactionId = 'mpc_plan_' . uniqid();
    }

    /**
     * Go back to previous step using history stack
     */
    public function goBack()
    {
        if (!empty($this->stepHistory)) {
            $previousStep = array_pop($this->stepHistory);
            $this->currentStep = $previousStep;

            // Special handling based on the step we're going back to
            if ($previousStep === Steps::PROJECT_ACCEPTANCE && $this->hasProjectsToAccept) {
                // Going back to project acceptance - reset to last project or first if all were accepted
                $this->currentProjectIndex = max(0, count($this->pendingProjects) - 1);
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
    public function goToPrevious()
    {
        $this->goBack();
    }

    /**
     * Change payment method entirely - go back to method selection
     */
    public function changePaymentMethod()
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
    public function editPaymentPlan()
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
            'planFrequency' => 'monthly', // Always monthly now
            'planDuration' => $this->planDuration,
            'downPayment' => 0, // No down payment in simplified plans
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
                    $lastFour = substr(str_replace(' ', '', $this->cardNumber), -4);
                } else {
                    // Create reusable check token
                    $token = $customer->tokenizeCheck([
                        'routing_number' => $this->routingNumber,
                        'account_number' => $this->accountNumber,
                        'name' => $this->clientInfo['client_name'],
                        'account_type' => 'Checking',
                        'check_type' => 'Personal',
                    ]);
                    $lastFour = substr($this->accountNumber, -4);
                }
                
                // Create the payment plan with scheduled payments
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
                    $lastFour
                );
                
                // Store the plan ID for confirmation display
                if ($paymentResult['success']) {
                    $this->transactionId = $paymentResult['plan_id'];
                }
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
        $this->currentStep = Steps::CONFIRMATION;
    }
    
    /**
     * Persist queued accepted projects to the database and update PracticeCS
     * 
     * This method:
     * 1. Saves acceptance records to local SQLite database (audit trail)
     * 2. Updates PracticeCS to change engagement type from EXPANSION to target type
     *    (e.g., EXPTAX -> TAXDELIVERY) - this "activates" the proposed work
     * 3. Updates local record with payment and sync status
     */
    private function persistAcceptedProjects()
    {
        $engagementService = app(EngagementAcceptanceService::class);
        $staffKey = config('practicecs.payment_integration.staff_key', 1552);
        
        foreach ($this->projectsToPersist as $project) {
            // Check if already persisted to avoid duplicates
            $existing = ProjectAcceptance::where('project_engagement_key', $project['project_engagement_key'])
                ->first();
                
            if (!$existing) {
                // 1. Save to local SQLite database (audit trail) with payment info
                $acceptance = ProjectAcceptance::create(array_merge($project, [
                    'paid' => true,
                    'paid_at' => now(),
                    'payment_transaction_id' => $this->transactionId ?? null,
                ]));
                
                Log::info('Project acceptance persisted after payment', [
                    'engagement_id' => $project['engagement_id'],
                    'client_key' => $project['client_key'],
                ]);
                
                // 2. Update PracticeCS: Change engagement type from EXPANSION to target type
                // This converts the "proposed" engagement into an active engagement
                $result = $engagementService->acceptEngagement(
                    (int) $project['project_engagement_key'],
                    $staffKey
                );
                
                // 3. Update local record with sync status
                if ($result['success']) {
                    $acceptance->update([
                        'practicecs_updated' => true,
                        'new_engagement_type_key' => $result['new_type_KEY'] ?? null,
                        'practicecs_updated_at' => now(),
                    ]);
                    
                    Log::info('PracticeCS engagement type updated', [
                        'engagement_KEY' => $project['project_engagement_key'],
                        'new_type_KEY' => $result['new_type_KEY'] ?? null,
                    ]);
                } else {
                    // Log error but don't fail the payment - local record is saved
                    $acceptance->update([
                        'practicecs_updated' => false,
                        'practicecs_error' => $result['error'] ?? 'Unknown error',
                    ]);
                    
                    Log::error('Failed to update PracticeCS engagement type', [
                        'engagement_KEY' => $project['project_engagement_key'],
                        'error' => $result['error'] ?? 'Unknown error',
                    ]);
                }
            } else {
                // Already exists - update with payment info if not already paid
                if (!$existing->paid) {
                    $existing->update([
                        'paid' => true,
                        'paid_at' => now(),
                        'payment_transaction_id' => $this->transactionId ?? null,
                    ]);
                }
            }
        }
        
        // Clear the queue
        $this->projectsToPersist = [];
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
            
            if (!$invoice) {
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
                if (!isset($otherClientsInvoices[$clientKey])) {
                    $otherClientsInvoices[$clientKey] = [
                        'client_KEY' => $clientKey,
                        'client_name' => $invoice['client_name'],
                        'client_id' => $invoice['client_id'],
                        'invoices' => []
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
            if ($remainingAmount <= 0) break;
            
            $applyAmount = min($remainingAmount, (float)$invoice['open_amount']);
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
            'comments' => "Online payment - {$this->paymentMethod} - " . count($this->selectedInvoices) . " invoice(s)",
            'internal_comments' => json_encode([
                'source' => 'tr-pay',
                'transaction_id' => $paymentResult['transaction_id'],
                'payment_method' => $this->paymentMethod,
                'is_payment_plan' => $this->isPaymentPlan,
                'fee' => $this->creditCardFee,
                'has_group_distribution' => !empty($otherClientsInvoices),
                'processed_at' => now()->toIso8601String(),
            ]),
            'staff_KEY' => config('practicecs.payment_integration.staff_key'),
            'bank_account_KEY' => config('practicecs.payment_integration.bank_account_key'),
            'ledger_type_KEY' => config("practicecs.payment_integration.ledger_types.{$methodType}"),
            'subtype_KEY' => config("practicecs.payment_integration.payment_subtypes.{$methodType}"),
            'invoices' => $primaryInvoicesToApply,
        ];
        
        $result = $writer->writePayment($practiceCsData);
        
        if (!$result['success']) {
            throw new \Exception('PracticeCS payment write failed: ' . ($result['error'] ?? 'Unknown error'));
        }
        
        $paymentLedgerKey = $result['ledger_entry_KEY'];
        
        Log::info('Payment written to PracticeCS', [
            'transaction_id' => $paymentResult['transaction_id'],
            'ledger_entry_KEY' => $paymentLedgerKey,
            'entry_number' => $result['entry_number'],
            'remaining_amount' => $remainingAmount,
        ]);
        
        // Step 4: Handle client group distribution if needed
        if ($remainingAmount > 0.01 && !empty($otherClientsInvoices)) {
            // Generate unique memo reference with date
            $memoReference = 'MEMO_' . now()->format('Ymd') . '_' . $paymentResult['transaction_id'];
            
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
                
                if ($clientAmount <= 0.01) break;
                
                // Step 4a: Create CREDIT MEMO on OTHER client
                // This represents money "received" by the other client from the logged-in client
                $creditMemoData = [
                    'client_KEY' => $clientKey,
                    'amount' => $clientAmount,
                    'reference' => $memoReference,
                    'comments' => "Credit memo - payment from " . ($this->clientInfo['client_name'] ?? 'group member'),
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
                
                if (!$creditResult['success']) {
                    throw new \Exception("Failed to create credit memo for client {$clientKey}: " . ($creditResult['error'] ?? 'Unknown error'));
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
                    if ($applyRemaining <= 0.01) break;
                    
                    $applyAmount = min($applyRemaining, (float)$invoice['open_amount']);
                    $invoicesToApply[] = [
                        'ledger_entry_KEY' => $invoice['ledger_entry_KEY'],
                        'amount' => $applyAmount,
                    ];
                    $applyRemaining -= $applyAmount;
                }
                
                if (!empty($invoicesToApply)) {
                    \Illuminate\Support\Facades\DB::connection($connection)->transaction(function() use ($connection, $creditMemoLedgerKey, $invoicesToApply, $writer, $staffKey) {
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
                    'comments' => "Debit memo - payment to " . ($clientData['client_name'] ?? 'group member'),
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
                
                if (!$debitResult['success']) {
                    throw new \Exception("Failed to create debit memo: " . ($debitResult['error'] ?? 'Unknown error'));
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
                \Illuminate\Support\Facades\DB::connection($connection)->insert("
                    INSERT INTO Ledger_Entry_Application (
                        update__staff_KEY,
                        update_date_utc,
                        from__ledger_entry_KEY,
                        to__ledger_entry_KEY,
                        applied_amount,
                        create_date_utc
                    )
                    VALUES (?, GETUTCDATE(), ?, ?, ?, GETUTCDATE())
                ", [
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
    public function startOver()
    {
        $this->reset();
        $this->currentStep = Steps::ACCOUNT_TYPE;
        $this->stepHistory = [];
    }

    /**
     * Called when skeleton loading completes to advance to actual step
     */
    public function onSkeletonComplete()
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
            'hasProjectsToAccept' => $this->hasProjectsToAccept,
            'currentProjectIndex' => $this->currentProjectIndex,
            'totalProjects' => count($this->pendingProjects),
            'isPaymentPlan' => $this->isPaymentPlan,
        ];
    }

    public function render()
    {
        // Safety check: ensure currentProjectIndex is valid
        if ($this->hasProjectsToAccept && $this->currentStep === Steps::PROJECT_ACCEPTANCE) {
            if ($this->currentProjectIndex >= count($this->pendingProjects)) {
                $this->currentProjectIndex = max(0, count($this->pendingProjects) - 1);
            }
        }
        
        return view('livewire.payment-flow');
    }
}
