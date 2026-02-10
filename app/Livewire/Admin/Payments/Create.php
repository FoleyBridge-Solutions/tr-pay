<?php

namespace App\Livewire\Admin\Payments;

use App\Models\AdminActivity;
use App\Models\Customer;
use App\Models\CustomerPaymentMethod;
use App\Models\Payment;
use App\Models\ProjectAcceptance;
use App\Repositories\PaymentRepository;
use App\Services\EngagementAcceptanceService;
use App\Services\PaymentService;
use App\Services\PracticeCsPaymentWriter;
use App\Support\Money;
use FoleyBridgeSolutions\MiPaymentChoiceCashier\Services\QuickPaymentsService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Create Single Payment Wizard
 *
 * Multi-step wizard for admins to process single payments for clients.
 * Supports both immediate and scheduled payments.
 *
 * Steps:
 * 1. Search and select client
 * 2. Select invoices and enter payment amount
 * 3. Enter payment method, review, and process/schedule payment
 */
#[Layout('layouts.admin')]
class Create extends Component
{
    // Wizard state
    public int $currentStep = 1;

    public const TOTAL_STEPS = 3;

    // Step 1: Client Search
    #[Url]
    public ?int $client = null;

    public string $searchType = 'name'; // 'name', 'client_id', or 'tax_id'

    public string $searchQuery = '';

    public array $searchResults = [];

    public ?array $selectedClient = null;

    // Step 2: Invoice Selection & Amount
    public array $availableInvoices = [];

    public array $selectedInvoices = [];

    public float $invoiceTotal = 0;

    public float $paymentAmount = 0;

    public bool $customPaymentAmount = false;

    public bool $leaveUnapplied = false;

    // Step 2: Fee Requests (EXP Engagements)
    public array $pendingEngagements = [];

    public array $selectedEngagements = [];

    public float $engagementTotal = 0;

    // Fee inclusion option (for custom payment amounts with credit card)
    public bool $feeIncludedInCustomAmount = false;

    // Scheduling
    public bool $scheduleForLater = false;

    public ?string $scheduledDate = null;

    // Step 3: Payment Method
    public string $paymentMethodType = 'card'; // 'card', 'ach', or 'saved'

    public ?int $savedPaymentMethodId = null;

    public Collection $savedPaymentMethods;

    public string $cardNumber = '';

    public string $cardExpiry = '';

    public string $cardCvv = '';

    public string $cardName = '';

    public string $accountNumber = '';

    public string $routingNumber = '';

    public string $accountName = '';

    public string $accountType = 'checking';

    public bool $isBusiness = false;

    // Confirmation
    public bool $confirmed = false;

    // Processing state
    public bool $processing = false;

    public ?string $errorMessage = null;

    public ?string $successMessage = null;

    public ?string $transactionId = null;

    protected PaymentRepository $paymentRepo;

    public function boot(PaymentRepository $paymentRepo): void
    {
        $this->paymentRepo = $paymentRepo;
        $this->savedPaymentMethods = collect();
    }

    public function mount(): void
    {
        // If client query param provided, pre-select the client
        if ($this->client) {
            $this->selectClient($this->client);
        }
    }

    /**
     * Get the credit card fee rate.
     */
    public function getCreditCardFeeRate(): float
    {
        return (float) config('payment-fees.credit_card_rate', 0.04);
    }

    /**
     * Check if current payment method is a card type.
     */
    public function isCardPaymentMethod(): bool
    {
        if ($this->paymentMethodType === 'card') {
            return true;
        }

        if ($this->paymentMethodType === 'saved' && $this->savedPaymentMethodId) {
            $savedMethod = $this->savedPaymentMethods->firstWhere('id', $this->savedPaymentMethodId);

            return $savedMethod && $savedMethod->type === 'card';
        }

        return false;
    }

    /**
     * Calculate credit card fee based on selected payment method.
     *
     * When feeIncludedInCustomAmount is true and using custom payment amount with a card,
     * the entered amount is treated as the total (fee included), so we back-calculate the fee.
     *
     * Uses Money class for precision-safe arithmetic.
     */
    public function getCreditCardFee(): float
    {
        if (! $this->isCardPaymentMethod()) {
            return 0;
        }

        $feeRate = $this->getCreditCardFeeRate();

        // If fee is included in custom amount, back-calculate the fee from total
        if ($this->customPaymentAmount && $this->feeIncludedInCustomAmount) {
            // Use cents for precision: fee = total - (total / (1 + rate))
            $totalCents = Money::toCents($this->paymentAmount);
            $baseCents = (int) round($totalCents / (1 + $feeRate));
            $feeCents = $totalCents - $baseCents;

            return Money::toDollars($feeCents);
        }

        return Money::multiplyDollars($this->paymentAmount, $feeRate);
    }

    /**
     * Get the base payment amount (amount applied to client account).
     *
     * When fee is included in custom amount, this returns the amount less the fee.
     *
     * Uses Money class for precision-safe arithmetic.
     */
    public function getBasePaymentAmount(): float
    {
        if ($this->customPaymentAmount && $this->feeIncludedInCustomAmount && $this->isCardPaymentMethod()) {
            $feeRate = $this->getCreditCardFeeRate();
            $totalCents = Money::toCents($this->paymentAmount);
            $baseCents = (int) round($totalCents / (1 + $feeRate));

            return Money::toDollars($baseCents);
        }

        return $this->paymentAmount;
    }

    /**
     * Get total amount to charge (payment + fee).
     *
     * When fee is included in custom amount, the paymentAmount IS the total.
     *
     * Uses Money class for precision-safe arithmetic.
     */
    public function getTotalCharge(): float
    {
        if ($this->customPaymentAmount && $this->feeIncludedInCustomAmount && $this->isCardPaymentMethod()) {
            return Money::round($this->paymentAmount);
        }

        return Money::addDollars($this->paymentAmount, $this->getCreditCardFee());
    }

    /**
     * Get the selected saved payment method.
     */
    public function getSelectedSavedMethod(): ?CustomerPaymentMethod
    {
        if (! $this->savedPaymentMethodId) {
            return null;
        }

        return $this->savedPaymentMethods->firstWhere('id', $this->savedPaymentMethodId);
    }

    /**
     * Search for clients.
     */
    public function searchClients(): void
    {
        $this->searchResults = [];
        $this->errorMessage = null;

        if (strlen($this->searchQuery) < 2) {
            return;
        }

        try {
            if ($this->searchType === 'client_id') {
                $result = DB::connection('sqlsrv')->select('
                    SELECT TOP 20
                        client_KEY,
                        client_id,
                        description AS client_name,
                        individual_first_name,
                        individual_last_name,
                        federal_tin
                    FROM Client
                    WHERE client_id LIKE ?
                    ORDER BY description
                ', ["%{$this->searchQuery}%"]);
            } elseif ($this->searchType === 'tax_id') {
                // Search by last 4 digits of SSN/EIN (federal_tin)
                $last4 = preg_replace('/\D/', '', $this->searchQuery);
                if (strlen($last4) !== 4) {
                    $this->errorMessage = 'Please enter exactly 4 digits for Tax ID search.';

                    return;
                }
                $result = DB::connection('sqlsrv')->select('
                    SELECT TOP 20
                        client_KEY,
                        client_id,
                        description AS client_name,
                        individual_first_name,
                        individual_last_name,
                        federal_tin
                    FROM Client
                    WHERE RIGHT(REPLACE(REPLACE(federal_tin, \'-\', \'\'), \' \', \'\'), 4) = ?
                    ORDER BY description
                ', [$last4]);
            } else {
                $result = DB::connection('sqlsrv')->select('
                    SELECT TOP 20
                        client_KEY,
                        client_id,
                        description AS client_name,
                        individual_first_name,
                        individual_last_name,
                        federal_tin
                    FROM Client
                    WHERE description LIKE ?
                       OR individual_last_name LIKE ?
                       OR individual_first_name LIKE ?
                    ORDER BY description
                ', ["%{$this->searchQuery}%", "%{$this->searchQuery}%", "%{$this->searchQuery}%"]);
            }

            $this->searchResults = array_map(fn ($r) => (array) $r, $result);
        } catch (\Exception $e) {
            Log::error('Client search failed', ['error' => $e->getMessage()]);
            $this->errorMessage = 'Failed to search clients. Please try again.';
        }
    }

    /**
     * Select a client and load their invoices.
     */
    public function selectClient(int $clientKey): void
    {
        $this->errorMessage = null;

        try {
            $client = DB::connection('sqlsrv')->selectOne('
                SELECT
                    client_KEY,
                    client_id,
                    description AS client_name,
                    individual_first_name,
                    individual_last_name,
                    federal_tin
                FROM Client
                WHERE client_KEY = ?
            ', [$clientKey]);

            if (! $client) {
                $this->errorMessage = 'Client not found.';

                return;
            }

            $this->selectedClient = (array) $client;

            // Get client balance
            $balance = $this->paymentRepo->getClientBalance($clientKey);
            $this->selectedClient['balance'] = $balance['balance'];

            // Load invoices
            $this->loadInvoices();

            // Load pending engagements (fee requests)
            $this->loadPendingEngagements();

            // Load saved payment methods
            $this->loadSavedPaymentMethods();

            // Move to step 2
            $this->currentStep = 2;
        } catch (\Exception $e) {
            Log::error('Failed to select client', ['error' => $e->getMessage()]);
            $this->errorMessage = 'Failed to load client data. Please try again.';
        }
    }

    /**
     * Load open invoices for the selected client.
     */
    protected function loadInvoices(): void
    {
        if (! $this->selectedClient) {
            return;
        }

        try {
            $invoices = $this->paymentRepo->getClientOpenInvoices($this->selectedClient['client_KEY']);
            $this->availableInvoices = $invoices;
        } catch (\Exception $e) {
            Log::error('Failed to load invoices', ['error' => $e->getMessage()]);
            $this->availableInvoices = [];
        }
    }

    /**
     * Load pending EXP engagements (fee requests) for the selected client.
     */
    protected function loadPendingEngagements(): void
    {
        if (! $this->selectedClient) {
            $this->pendingEngagements = [];

            return;
        }

        try {
            $this->pendingEngagements = $this->paymentRepo->getPendingProjectsForClientGroup(
                $this->selectedClient['client_KEY']
            );
        } catch (\Exception $e) {
            Log::error('Failed to load pending engagements', ['error' => $e->getMessage()]);
            $this->pendingEngagements = [];
        }
    }

    /**
     * Load saved payment methods for the client.
     */
    protected function loadSavedPaymentMethods(): void
    {
        if (! $this->selectedClient) {
            $this->savedPaymentMethods = collect();

            return;
        }

        $customer = Customer::where('client_key', $this->selectedClient['client_KEY'])->first();

        if ($customer) {
            $this->savedPaymentMethods = $customer->customerPaymentMethods;
        } else {
            $this->savedPaymentMethods = collect();
        }
    }

    /**
     * Toggle invoice selection.
     */
    public function toggleInvoice(string $ledgerEntryKey): void
    {
        $key = (string) $ledgerEntryKey;

        if (in_array($key, $this->selectedInvoices, true)) {
            $this->selectedInvoices = array_values(array_filter(
                $this->selectedInvoices,
                fn ($k) => $k !== $key
            ));
        } else {
            $this->selectedInvoices[] = $key;
        }

        $this->calculateTotals();
    }

    /**
     * Select all invoices.
     */
    public function selectAllInvoices(): void
    {
        $this->selectedInvoices = [];
        foreach ($this->availableInvoices as $invoice) {
            $this->selectedInvoices[] = (string) $invoice['ledger_entry_KEY'];
        }
        $this->calculateTotals();
    }

    /**
     * Clear invoice selection.
     */
    public function clearSelection(): void
    {
        $this->reset('selectedInvoices');
        $this->invoiceTotal = 0;
        if (! $this->customPaymentAmount) {
            $this->paymentAmount = 0;
        }
    }

    /**
     * Toggle engagement selection.
     */
    public function toggleEngagement(int $engagementKey): void
    {
        $key = $engagementKey;

        if (in_array($key, $this->selectedEngagements, true)) {
            $this->selectedEngagements = array_values(array_filter(
                $this->selectedEngagements,
                fn ($k) => $k !== $key
            ));
        } else {
            $this->selectedEngagements[] = $key;
        }

        $this->calculateTotals();
    }

    /**
     * Select all pending engagements.
     */
    public function selectAllEngagements(): void
    {
        $this->selectedEngagements = [];
        foreach ($this->pendingEngagements as $engagement) {
            $this->selectedEngagements[] = (int) $engagement['engagement_KEY'];
        }
        $this->calculateTotals();
    }

    /**
     * Clear engagement selection.
     */
    public function clearEngagementSelection(): void
    {
        $this->reset('selectedEngagements');
        $this->engagementTotal = 0;
        $this->calculateTotals();
    }

    /**
     * Calculate totals based on selected invoices and projects.
     *
     * Uses Money class for precision-safe arithmetic.
     */
    public function calculateTotals(): void
    {
        // Calculate invoice total using cents for precision
        $invoiceTotalCents = 0;
        foreach ($this->availableInvoices as $invoice) {
            $key = (string) $invoice['ledger_entry_KEY'];
            if (in_array($key, $this->selectedInvoices, true)) {
                $invoiceTotalCents += Money::toCents($invoice['open_amount']);
            }
        }
        $this->invoiceTotal = Money::toDollars($invoiceTotalCents);

        // Calculate engagement total using cents for precision
        $engagementTotalCents = 0;
        foreach ($this->pendingEngagements as $engagement) {
            $key = (int) $engagement['engagement_KEY'];
            if (in_array($key, $this->selectedEngagements, true)) {
                $engagementTotalCents += Money::toCents($engagement['total_budget']);
            }
        }
        $this->engagementTotal = Money::toDollars($engagementTotalCents);

        // Only update payment amount if custom amount is not enabled
        if (! $this->customPaymentAmount) {
            $this->paymentAmount = Money::addDollars($this->invoiceTotal, $this->engagementTotal);
        }
    }

    /**
     * Update payment amount (for partial payments).
     *
     * Uses Money class for precision-safe arithmetic.
     */
    public function updatedPaymentAmount(): void
    {
        // Round to 2 decimal places using Money class
        $this->paymentAmount = Money::round($this->paymentAmount);

        // Ensure payment amount is positive
        if ($this->paymentAmount < 0) {
            $this->paymentAmount = 0;
        }

        // Cap at invoice + engagement total only when applying to invoices/engagements
        $maxAmount = Money::addDollars($this->invoiceTotal, $this->engagementTotal);
        if (! $this->leaveUnapplied && $this->paymentAmount > $maxAmount) {
            $this->paymentAmount = $maxAmount;
        }
    }

    /**
     * Handle custom payment amount toggle change.
     */
    public function updatedCustomPaymentAmount(): void
    {
        // Reset to invoice + engagement total when disabling custom amount
        if (! $this->customPaymentAmount) {
            $this->paymentAmount = Money::addDollars($this->invoiceTotal, $this->engagementTotal);
            $this->feeIncludedInCustomAmount = false;
        }
    }

    /**
     * Handle leave unapplied toggle change.
     */
    public function updatedLeaveUnapplied(): void
    {
        if ($this->leaveUnapplied) {
            // Clear invoice selection and enable custom amount
            $this->selectedInvoices = [];
            $this->invoiceTotal = 0;
            $this->customPaymentAmount = true;
            // Don't reset paymentAmount - let admin enter it
        } else {
            // Reset to normal mode
            $this->customPaymentAmount = false;
            $this->paymentAmount = 0;
        }
    }

    /**
     * Handle schedule toggle change.
     */
    public function updatedScheduleForLater(): void
    {
        if ($this->scheduleForLater) {
            // Default to tomorrow
            $this->scheduledDate = now()->addDay()->format('Y-m-d');

            // If client has saved payment methods, default to saved
            if ($this->savedPaymentMethods->count() > 0) {
                $this->paymentMethodType = 'saved';
                $defaultMethod = $this->savedPaymentMethods->firstWhere('is_default', true);
                $this->savedPaymentMethodId = $defaultMethod?->id ?? $this->savedPaymentMethods->first()?->id;
            }
        } else {
            $this->scheduledDate = null;
            // Reset to card if was on saved
            if ($this->paymentMethodType === 'saved') {
                $this->paymentMethodType = 'card';
            }
        }
    }

    /**
     * Go to next step.
     */
    public function nextStep(): void
    {
        $this->errorMessage = null;

        if (! $this->validateStep()) {
            return;
        }

        if ($this->currentStep < self::TOTAL_STEPS) {
            $this->currentStep++;
        }
    }

    /**
     * Go to previous step.
     */
    public function previousStep(): void
    {
        if ($this->currentStep > 1) {
            $this->currentStep--;
        }
        $this->errorMessage = null;
    }

    /**
     * Validate the current step.
     */
    protected function validateStep(): bool
    {
        switch ($this->currentStep) {
            case 1:
                if (! $this->selectedClient) {
                    $this->errorMessage = 'Please select a client.';

                    return false;
                }
                break;

            case 2:
                // Skip invoice/engagement requirement if leaving unapplied
                if (! $this->leaveUnapplied && count($this->selectedInvoices) === 0 && count($this->selectedEngagements) === 0) {
                    $this->errorMessage = 'Please select at least one invoice or fee request.';

                    return false;
                }
                if ($this->paymentAmount <= 0) {
                    $this->errorMessage = 'Payment amount must be greater than zero.';

                    return false;
                }
                // Only enforce invoice/engagement total cap when applying to invoices/engagements
                $maxAmount = Money::addDollars($this->invoiceTotal, $this->engagementTotal);
                if (! $this->leaveUnapplied && $this->paymentAmount > $maxAmount) {
                    $this->errorMessage = 'Payment amount cannot exceed selected total.';

                    return false;
                }
                if ($this->scheduleForLater) {
                    if (empty($this->scheduledDate)) {
                        $this->errorMessage = 'Please select a scheduled date.';

                        return false;
                    }
                    if (strtotime($this->scheduledDate) <= strtotime('today')) {
                        $this->errorMessage = 'Scheduled date must be in the future.';

                        return false;
                    }
                    if ($this->savedPaymentMethods->count() === 0) {
                        $this->errorMessage = 'Client has no saved payment methods. Scheduled payments require a saved payment method.';

                        return false;
                    }
                }
                break;

            case 3:
                if (! $this->validatePaymentMethod()) {
                    return false;
                }
                break;
        }

        return true;
    }

    /**
     * Validate payment method fields.
     */
    protected function validatePaymentMethod(): bool
    {
        if ($this->paymentMethodType === 'saved') {
            if (! $this->savedPaymentMethodId) {
                $this->errorMessage = 'Please select a saved payment method.';

                return false;
            }
            $method = $this->savedPaymentMethods->firstWhere('id', $this->savedPaymentMethodId);
            if (! $method) {
                $this->errorMessage = 'Selected payment method not found.';

                return false;
            }

            return true;
        }

        if ($this->paymentMethodType === 'card') {
            if (empty($this->cardNumber) || strlen(preg_replace('/\D/', '', $this->cardNumber)) < 13) {
                $this->errorMessage = 'Please enter a valid card number.';

                return false;
            }
            if (empty($this->cardExpiry) || ! preg_match('/^\d{2}\/\d{2}$/', $this->cardExpiry)) {
                $this->errorMessage = 'Please enter a valid expiry date (MM/YY).';

                return false;
            }
            if (empty($this->cardCvv) || strlen($this->cardCvv) < 3) {
                $this->errorMessage = 'Please enter a valid CVV.';

                return false;
            }
            if (empty($this->cardName)) {
                $this->errorMessage = 'Please enter the name on card.';

                return false;
            }
        } else {
            if (empty($this->routingNumber) || strlen($this->routingNumber) !== 9) {
                $this->errorMessage = 'Please enter a valid 9-digit routing number.';

                return false;
            }
            if (empty($this->accountNumber) || strlen($this->accountNumber) < 4) {
                $this->errorMessage = 'Please enter a valid account number.';

                return false;
            }
            if (empty($this->accountName)) {
                $this->errorMessage = 'Please enter the account holder name.';

                return false;
            }
        }

        return true;
    }

    /**
     * Process or schedule the payment.
     */
    public function processPayment(QuickPaymentsService $quickPayments, PracticeCsPaymentWriter $practiceWriter): void
    {
        $this->errorMessage = null;
        $this->processing = true;

        try {
            if ($this->scheduleForLater) {
                $this->schedulePayment();
            } else {
                $this->processImmediatePayment($quickPayments, $practiceWriter);
            }
        } catch (\Exception $e) {
            Log::error('Failed to process payment', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            $this->errorMessage = 'Payment failed: '.$e->getMessage();
        } finally {
            $this->processing = false;
        }
    }

    /**
     * Schedule the payment for later.
     *
     * Uses getBasePaymentAmount() and stores project selections for processing later.
     */
    protected function schedulePayment(): void
    {
        $savedMethod = $this->getSelectedSavedMethod();
        if (! $savedMethod) {
            throw new \Exception('No saved payment method selected.');
        }

        // Look up or create the customer record
        $paymentService = app(\App\Services\PaymentService::class);
        $customer = Customer::where('client_key', $this->selectedClient['client_KEY'])->first();
        if (! $customer) {
            $customer = $paymentService->getOrCreateCustomer([
                'client_KEY' => $this->selectedClient['client_KEY'],
                'client_id' => $this->selectedClient['client_id'],
                'client_name' => $this->selectedClient['client_name'],
                'email' => $this->selectedClient['email'] ?? null,
            ]);
        }

        // Generate transaction ID
        $this->transactionId = 'sch_'.bin2hex(random_bytes(6));

        $isCard = $savedMethod->type === 'card';
        $fee = $isCard ? $this->getCreditCardFee() : 0;
        $baseAmount = $this->getBasePaymentAmount();

        // Build description including engagements if any
        $invoiceCount = $this->leaveUnapplied ? 0 : count($this->selectedInvoices);
        $engagementCount = count($this->selectedEngagements);

        if ($this->leaveUnapplied) {
            $description = 'Scheduled single payment - Unapplied (credit balance)';
        } elseif ($engagementCount > 0 && $invoiceCount > 0) {
            $description = "Scheduled single payment - {$invoiceCount} invoice(s), {$engagementCount} fee request(s)";
        } elseif ($engagementCount > 0) {
            $description = "Scheduled single payment - {$engagementCount} fee request(s)";
        } else {
            $description = "Scheduled single payment - {$invoiceCount} invoice(s)";
        }

        $payment = Payment::create([
            'transaction_id' => $this->transactionId,
            'customer_id' => $customer->id,
            'client_key' => $this->selectedClient['client_KEY'],
            'amount' => $baseAmount,
            'fee' => $fee,
            'total_amount' => Money::addDollars($baseAmount, $fee),
            'payment_method' => $isCard ? 'credit_card' : 'ach',
            'payment_method_last_four' => $savedMethod->last_four,
            'status' => Payment::STATUS_PENDING,
            'scheduled_date' => $this->scheduledDate,
            'scheduled_at' => $this->scheduledDate,
            'is_automated' => true,
            'description' => $description,
            'metadata' => [
                'source' => 'admin-scheduled',
                'client_id' => $this->selectedClient['client_id'],
                'client_name' => $this->selectedClient['client_name'],
                'payment_method_id' => $savedMethod->id,
                'payment_method_token' => $savedMethod->mpc_token,
                'invoice_keys' => $this->leaveUnapplied ? [] : $this->selectedInvoices,
                'invoices' => $this->leaveUnapplied ? [] : $this->getInvoicesForApplication(),
                'engagement_keys' => $this->selectedEngagements,
                'pending_engagements' => $this->getSelectedEngagementsData(),
                'unapplied' => $this->leaveUnapplied,
                'fee_included_in_amount' => $this->feeIncludedInCustomAmount,
            ],
        ]);

        Log::info('Payment scheduled', [
            'payment_id' => $payment->id,
            'transaction_id' => $this->transactionId,
            'scheduled_date' => $this->scheduledDate,
            'base_amount' => $baseAmount,
            'engagement_count' => $engagementCount,
        ]);

        $this->successMessage = 'Payment scheduled successfully for '.date('F j, Y', strtotime($this->scheduledDate)).'!';
        $this->currentStep = 4; // Success step

        // Log the activity
        $activityDescription = $this->leaveUnapplied
            ? "Scheduled unapplied payment {$this->transactionId} for {$this->selectedClient['client_name']} on {$this->scheduledDate} - Amount: \$".number_format($baseAmount, 2).' (credit balance)'
            : "Scheduled payment {$this->transactionId} for {$this->selectedClient['client_name']} on {$this->scheduledDate} - Amount: \$".number_format($baseAmount, 2);

        AdminActivity::log(
            AdminActivity::ACTION_CREATED,
            $payment,
            description: $activityDescription,
            newValues: [
                'transaction_id' => $this->transactionId,
                'client_id' => $this->selectedClient['client_id'],
                'client_name' => $this->selectedClient['client_name'],
                'amount' => $baseAmount,
                'fee' => $fee,
                'total_amount' => Money::addDollars($baseAmount, $fee),
                'payment_method' => $isCard ? 'credit_card' : 'ach',
                'payment_method_last_four' => $savedMethod->last_four,
                'scheduled_date' => $this->scheduledDate,
                'invoice_count' => $invoiceCount,
                'invoice_keys' => $this->leaveUnapplied ? [] : $this->selectedInvoices,
                'engagement_count' => $engagementCount,
                'engagement_keys' => $this->selectedEngagements,
                'unapplied' => $this->leaveUnapplied,
            ]
        );
    }

    /**
     * Get selected engagements data for metadata storage.
     */
    protected function getSelectedEngagementsData(): array
    {
        $selectedData = [];
        foreach ($this->pendingEngagements as $engagement) {
            $engagementKey = (int) $engagement['engagement_KEY'];
            if (in_array($engagementKey, $this->selectedEngagements, true)) {
                $selectedData[] = [
                    'engagement_KEY' => $engagementKey,
                    'client_KEY' => $engagement['client_KEY'],
                    'engagement_name' => $engagement['engagement_name'],
                    'engagement_type' => $engagement['engagement_type'],
                    'total_budget' => $engagement['total_budget'],
                    'project_count' => count($engagement['projects']),
                    'group_name' => $engagement['group_name'] ?? null,
                ];
            }
        }

        return $selectedData;
    }

    /**
     * Process an immediate payment.
     */
    protected function processImmediatePayment(QuickPaymentsService $quickPayments, PracticeCsPaymentWriter $practiceWriter): void
    {
        // Generate transaction ID
        $this->transactionId = 'txn_'.bin2hex(random_bytes(6));

        // Determine if using saved method or new details
        if ($this->paymentMethodType === 'saved') {
            $this->processWithSavedMethod($quickPayments, $practiceWriter);
        } else {
            $this->processWithNewMethod($quickPayments, $practiceWriter);
        }
    }

    /**
     * Process payment with saved payment method.
     */
    protected function processWithSavedMethod(QuickPaymentsService $quickPayments, PracticeCsPaymentWriter $practiceWriter): void
    {
        $savedMethod = $this->getSelectedSavedMethod();
        if (! $savedMethod) {
            throw new \Exception('No saved payment method selected.');
        }

        $customer = Customer::where('client_key', $this->selectedClient['client_KEY'])->first();
        if (! $customer) {
            throw new \Exception('Customer not found.');
        }

        $isCard = $savedMethod->type === 'card';
        $chargeAmount = $isCard ? $this->getTotalCharge() : $this->paymentAmount;

        // Charge using the saved token
        $chargeResponse = $customer->charge($chargeAmount, $savedMethod->mpc_token, [
            'description' => 'Admin payment - '.count($this->selectedInvoices).' invoice(s)',
        ]);

        if (! $chargeResponse || empty($chargeResponse['TransactionKey'])) {
            throw new \Exception($chargeResponse['ResponseStatus']['Message'] ?? 'Payment failed');
        }

        $this->recordSuccessfulPayment(
            $chargeResponse['TransactionKey'],
            $isCard ? 'credit_card' : 'ach',
            $savedMethod->last_four,
            $practiceWriter,
            $customer
        );
    }

    /**
     * Process payment with new card/ACH details.
     */
    protected function processWithNewMethod(QuickPaymentsService $quickPayments, PracticeCsPaymentWriter $practiceWriter): void
    {
        $paymentService = app(PaymentService::class);

        // Ensure a local customer record exists for this PracticeCS client
        $customer = Customer::where('client_key', $this->selectedClient['client_KEY'])->first();
        if (! $customer) {
            $customer = $paymentService->getOrCreateCustomer([
                'client_KEY' => $this->selectedClient['client_KEY'],
                'client_name' => $this->selectedClient['name'] ?? $this->selectedClient['client_name'] ?? 'Client',
            ]);
        }

        if ($this->paymentMethodType === 'card') {
            $expiryParts = explode('/', $this->cardExpiry);

            $tokenResponse = $quickPayments->createQpToken([
                'number' => preg_replace('/\D/', '', $this->cardNumber),
                'exp_month' => $expiryParts[0],
                'exp_year' => '20'.$expiryParts[1],
                'cvc' => $this->cardCvv,
                'name' => $this->cardName,
            ]);

            if (empty($tokenResponse['QuickPaymentsToken'])) {
                throw new \Exception('Failed to create payment token');
            }

            $qpToken = $tokenResponse['QuickPaymentsToken'];
            $chargeResponse = $quickPayments->charge($qpToken, $this->getTotalCharge(), [
                'invoice_number' => $this->transactionId,
                'force_duplicate' => true,
            ]);

            $lastFour = substr(preg_replace('/\D/', '', $this->cardNumber), -4);
            $methodType = 'credit_card';

            if (empty($chargeResponse['TransactionKey'])) {
                throw new \Exception($chargeResponse['ResponseStatus']['Message'] ?? 'Payment failed');
            }

            $this->recordSuccessfulPayment(
                $chargeResponse['TransactionKey'],
                $methodType,
                $lastFour,
                $practiceWriter,
                $customer
            );
        } else {
            // ACH payment via Kotapay
            $chargeResponse = $paymentService->chargeAchWithKotapay(
                $customer,
                [
                    'routing_number' => preg_replace('/\D/', '', $this->routingNumber),
                    'account_number' => preg_replace('/\D/', '', $this->accountNumber),
                    'account_type' => ucfirst($this->accountType),
                    'account_name' => $this->accountName,
                    'is_business' => $this->isBusiness,
                ],
                $this->paymentAmount,
                [
                    'description' => 'Admin payment - '.count($this->selectedInvoices).' invoice(s)',
                ]
            );

            if (! $chargeResponse['success']) {
                throw new \Exception($chargeResponse['error'] ?? 'ACH payment failed');
            }

            $lastFour = substr($this->accountNumber, -4);
            $methodType = 'ach';

            $this->recordSuccessfulPayment(
                $chargeResponse['transaction_id'],
                $methodType,
                $lastFour,
                $practiceWriter,
                $customer
            );
        }
    }

    /**
     * Record a successful payment.
     *
     * Uses getBasePaymentAmount() to get the amount applied to client account
     * (which may differ from paymentAmount if fee is included in custom amount).
     */
    protected function recordSuccessfulPayment(string $gatewayTransactionId, string $methodType, string $lastFour, PracticeCsPaymentWriter $practiceWriter, Customer $customer): void
    {
        $fee = $methodType === 'credit_card' ? $this->getCreditCardFee() : 0;
        $baseAmount = $this->getBasePaymentAmount();

        Log::info('Payment charged successfully', [
            'transaction_id' => $this->transactionId,
            'gateway_transaction_id' => $gatewayTransactionId,
            'base_amount' => $baseAmount,
            'fee' => $fee,
            'total_charged' => $this->getTotalCharge(),
        ]);

        // Build description including engagements if any
        $invoiceCount = $this->leaveUnapplied ? 0 : count($this->selectedInvoices);
        $engagementCount = count($this->selectedEngagements);

        if ($this->leaveUnapplied) {
            $description = 'Admin payment - Unapplied (credit balance)';
        } elseif ($engagementCount > 0 && $invoiceCount > 0) {
            $description = "Admin payment - {$invoiceCount} invoice(s), {$engagementCount} fee request(s)";
        } elseif ($engagementCount > 0) {
            $description = "Admin payment - {$engagementCount} fee request(s)";
        } else {
            $description = "Admin payment - {$invoiceCount} invoice(s)";
        }

        $isAch = $methodType === 'ach';

        $payment = Payment::create([
            'customer_id' => $customer->id,
            'transaction_id' => $this->transactionId,
            'client_key' => $this->selectedClient['client_KEY'],
            'amount' => $baseAmount,
            'fee' => $fee,
            'total_amount' => Money::addDollars($baseAmount, $fee),
            'payment_method' => $methodType,
            'payment_method_last_four' => $lastFour,
            'status' => $isAch ? Payment::STATUS_PROCESSING : Payment::STATUS_COMPLETED,
            'processed_at' => $isAch ? null : now(),
            'payment_vendor' => $isAch ? 'kotapay' : null,
            'vendor_transaction_id' => $isAch ? $gatewayTransactionId : null,
            'description' => $description,
            'metadata' => array_filter([
                'source' => 'admin-immediate',
                'client_id' => $this->selectedClient['client_id'],
                'client_name' => $this->selectedClient['client_name'],
                'gateway_transaction_id' => $gatewayTransactionId,
                'invoice_keys' => $this->leaveUnapplied ? [] : $this->selectedInvoices,
                'engagement_keys' => $this->selectedEngagements,
                'pending_engagements' => $this->getSelectedEngagementsData(),
                'unapplied' => $this->leaveUnapplied,
                'fee_included_in_amount' => $this->feeIncludedInCustomAmount,
                // ACH-specific fields for potential batch reprocessing
                'is_business' => $methodType === 'ach' ? $this->isBusiness : null,
            ], fn ($v) => $v !== null),
        ]);

        // Persist accepted engagements (auto-accept on behalf of client)
        $this->persistAcceptedEngagements();

        // Write to PracticeCS if enabled
        if (config('practicecs.payment_integration.enabled')) {
            $this->writeToPracticeCs($practiceWriter, $methodType);
        }

        $this->successMessage = $isAch
            ? 'ACH payment submitted successfully! It will be marked as completed once it settles (2-3 business days).'
            : 'Payment processed successfully!';
        $this->currentStep = 4; // Success step

        // Log the activity
        $activityDescription = $this->leaveUnapplied
            ? "Processed unapplied payment {$this->transactionId} for {$this->selectedClient['client_name']} - Amount: \$".number_format($baseAmount, 2).' (credit balance)'
            : "Processed payment {$this->transactionId} for {$this->selectedClient['client_name']} - Amount: \$".number_format($baseAmount, 2);

        AdminActivity::log(
            AdminActivity::ACTION_CREATED,
            $payment,
            description: $activityDescription,
            newValues: [
                'transaction_id' => $this->transactionId,
                'gateway_transaction_id' => $gatewayTransactionId,
                'client_id' => $this->selectedClient['client_id'],
                'client_name' => $this->selectedClient['client_name'],
                'amount' => $baseAmount,
                'fee' => $fee,
                'total_amount' => Money::addDollars($baseAmount, $fee),
                'payment_method' => $methodType,
                'payment_method_last_four' => $lastFour,
                'invoice_count' => $invoiceCount,
                'invoice_keys' => $this->leaveUnapplied ? [] : $this->selectedInvoices,
                'engagement_count' => $engagementCount,
                'engagement_keys' => $this->selectedEngagements,
                'unapplied' => $this->leaveUnapplied,
            ]
        );
    }

    /**
     * Get invoices formatted for application.
     *
     * Uses getBasePaymentAmount() to get the amount available for invoice application
     * (which excludes fee when fee is included in custom amount).
     *
     * Uses Money class for precision-safe arithmetic.
     */
    protected function getInvoicesForApplication(): array
    {
        $invoicesToApply = [];
        $remainingCents = Money::toCents($this->getBasePaymentAmount());

        $selectedInvoiceDetails = array_filter($this->availableInvoices, function ($inv) {
            return in_array((string) $inv['ledger_entry_KEY'], $this->selectedInvoices);
        });
        usort($selectedInvoiceDetails, fn ($a, $b) => $a['ledger_entry_KEY'] <=> $b['ledger_entry_KEY']);

        foreach ($selectedInvoiceDetails as $invoice) {
            if ($remainingCents <= 0) {
                break;
            }

            $invoiceOpenCents = Money::toCents((float) $invoice['open_amount']);
            $applyCents = min($remainingCents, $invoiceOpenCents);

            $invoicesToApply[] = [
                'ledger_entry_KEY' => $invoice['ledger_entry_KEY'],
                'amount' => Money::toDollars($applyCents),
            ];
            $remainingCents -= $applyCents;
        }

        return $invoicesToApply;
    }

    /**
     * Write payment to PracticeCS.
     *
     * Uses getBasePaymentAmount() to write the amount applied to client account
     * (which excludes fee when fee is included in custom amount).
     */
    protected function writeToPracticeCs(PracticeCsPaymentWriter $writer, string $methodType): void
    {
        $invoiceCount = $this->leaveUnapplied ? 0 : count($this->selectedInvoices);
        $engagementCount = count($this->selectedEngagements);
        $baseAmount = $this->getBasePaymentAmount();

        // Build comments including engagements if any
        if ($this->leaveUnapplied) {
            $comments = "Online payment - {$methodType} - Unapplied (credit balance)";
        } elseif ($engagementCount > 0 && $invoiceCount > 0) {
            $comments = "Online payment - {$methodType} - {$invoiceCount} invoice(s), {$engagementCount} fee request(s)";
        } elseif ($engagementCount > 0) {
            $comments = "Online payment - {$methodType} - {$engagementCount} fee request(s)";
        } else {
            $comments = "Online payment - {$methodType} - {$invoiceCount} invoice(s)";
        }

        $practiceCsData = [
            'client_KEY' => $this->selectedClient['client_KEY'],
            'amount' => $baseAmount,
            'reference' => $this->transactionId,
            'comments' => $comments,
            'internal_comments' => json_encode([
                'source' => 'tr-pay-admin',
                'transaction_id' => $this->transactionId,
                'payment_method' => $methodType,
                'fee' => $this->getCreditCardFee(),
                'processed_at' => now()->toIso8601String(),
                'unapplied' => $this->leaveUnapplied,
                'fee_included_in_amount' => $this->feeIncludedInCustomAmount,
                'engagement_keys' => $this->selectedEngagements,
            ]),
            'staff_KEY' => config('practicecs.payment_integration.staff_key'),
            'bank_account_KEY' => config('practicecs.payment_integration.bank_account_key'),
            'ledger_type_KEY' => config("practicecs.payment_integration.ledger_types.{$methodType}"),
            'subtype_KEY' => config("practicecs.payment_integration.payment_subtypes.{$methodType}"),
            'invoices' => $this->leaveUnapplied ? [] : $this->getInvoicesForApplication(),
        ];

        $result = $writer->writePayment($practiceCsData);

        if (! $result['success']) {
            Log::error('Failed to write payment to PracticeCS', [
                'transaction_id' => $this->transactionId,
                'error' => $result['error'],
            ]);
        } else {
            Log::info('Payment written to PracticeCS', [
                'transaction_id' => $this->transactionId,
                'ledger_entry_KEY' => $result['ledger_entry_KEY'],
            ]);
        }
    }

    /**
     * Persist accepted engagements to the database and update PracticeCS.
     *
     * When admin selects fee requests (EXP engagements) and payment succeeds,
     * this method:
     * 1. Creates ProjectAcceptance records (admin accepting on behalf of client)
     * 2. Updates PracticeCS engagement type from EXPANSION to target type
     */
    protected function persistAcceptedEngagements(): void
    {
        if (empty($this->selectedEngagements)) {
            return;
        }

        $engagementService = app(EngagementAcceptanceService::class);
        $staffKey = config('practicecs.payment_integration.staff_key', 1552);

        foreach ($this->pendingEngagements as $engagement) {
            $engagementKey = (int) $engagement['engagement_KEY'];

            if (! in_array($engagementKey, $this->selectedEngagements, true)) {
                continue;
            }

            // Check if already persisted to avoid duplicates
            $existing = ProjectAcceptance::where('project_engagement_key', $engagementKey)->first();

            // Get first project notes for year-aware type resolution
            $firstProjectNotes = ! empty($engagement['projects']) ? ($engagement['projects'][0]['notes'] ?? null) : null;

            if (! $existing) {
                // Create acceptance record (admin accepting on behalf of client)
                $acceptance = ProjectAcceptance::create([
                    'project_engagement_key' => $engagementKey,
                    'client_key' => $engagement['client_KEY'],
                    'client_group_name' => $engagement['group_name'] ?? null,
                    'engagement_id' => $engagement['engagement_type_id'] ?? null,
                    'project_name' => $engagement['engagement_name'],
                    'budget_amount' => $engagement['total_budget'],
                    'accepted' => true,
                    'accepted_at' => now(),
                    'accepted_by_ip' => request()->ip(),
                    'acceptance_signature' => 'Admin Accepted',
                    'paid' => true,
                    'paid_at' => now(),
                    'payment_transaction_id' => $this->transactionId,
                ]);

                Log::info('Admin accepted engagement on behalf of client', [
                    'engagement_KEY' => $engagementKey,
                    'engagement_name' => $engagement['engagement_name'],
                    'client_key' => $engagement['client_KEY'],
                    'transaction_id' => $this->transactionId,
                    'project_count' => count($engagement['projects']),
                ]);

                // Update PracticeCS engagement type (year-aware)
                $result = $engagementService->acceptEngagement(
                    $engagementKey,
                    $staffKey,
                    $firstProjectNotes
                );

                if ($result['success']) {
                    $acceptance->update([
                        'practicecs_updated' => true,
                        'new_engagement_type_key' => $result['new_type_KEY'] ?? null,
                        'practicecs_updated_at' => now(),
                    ]);

                    Log::info('PracticeCS engagement type updated', [
                        'engagement_KEY' => $engagementKey,
                        'new_type_KEY' => $result['new_type_KEY'] ?? null,
                    ]);
                } else {
                    $acceptance->update([
                        'practicecs_updated' => false,
                        'practicecs_error' => $result['error'] ?? 'Unknown error',
                    ]);

                    Log::error('Failed to update PracticeCS engagement type', [
                        'engagement_KEY' => $engagementKey,
                        'error' => $result['error'] ?? 'Unknown error',
                    ]);
                }
            } else {
                // Already exists - update with payment info if not already paid
                if (! $existing->paid) {
                    $existing->update([
                        'paid' => true,
                        'paid_at' => now(),
                        'payment_transaction_id' => $this->transactionId,
                    ]);
                }
            }
        }
    }

    /**
     * Get last 4 digits of payment method.
     */
    protected function getLastFour(): string
    {
        if ($this->paymentMethodType === 'saved') {
            $method = $this->getSelectedSavedMethod();

            return $method?->last_four ?? '****';
        }

        if ($this->paymentMethodType === 'card') {
            $cleaned = preg_replace('/\D/', '', $this->cardNumber);

            return substr($cleaned, -4);
        }

        return substr($this->accountNumber, -4);
    }

    /**
     * Reset wizard and start over.
     */
    public function startOver(): void
    {
        $this->reset([
            'currentStep',
            'searchQuery',
            'searchResults',
            'selectedClient',
            'availableInvoices',
            'selectedInvoices',
            'invoiceTotal',
            'pendingEngagements',
            'selectedEngagements',
            'engagementTotal',
            'paymentAmount',
            'customPaymentAmount',
            'feeIncludedInCustomAmount',
            'leaveUnapplied',
            'scheduleForLater',
            'scheduledDate',
            'paymentMethodType',
            'savedPaymentMethodId',
            'cardNumber',
            'cardExpiry',
            'cardCvv',
            'cardName',
            'accountNumber',
            'routingNumber',
            'accountName',
            'accountType',
            'isBusiness',
            'confirmed',
            'errorMessage',
            'successMessage',
            'transactionId',
        ]);
        $this->currentStep = 1;
        $this->paymentMethodType = 'card';
        $this->savedPaymentMethods = collect();
    }

    public function render()
    {
        return view('livewire.admin.payments.create');
    }
}
