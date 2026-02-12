<?php

namespace App\Livewire\Admin\PaymentPlans;

use App\Livewire\Admin\Concerns\HasInvoiceManagement;
use App\Livewire\Admin\Concerns\SearchesClients;
use App\Livewire\Admin\Concerns\ValidatesPaymentMethod;
use App\Models\AdminActivity;
use App\Models\CustomerPaymentMethod;
use App\Repositories\PaymentRepository;
use App\Services\PaymentPlanCalculator;
use App\Services\PaymentService;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Create Payment Plan Wizard
 *
 * Multi-step wizard for admins to create scheduled payment plans for clients.
 *
 * Steps:
 * 1. Search and select client
 * 2. Select invoices to include
 * 3. Enter payment method (card or ACH)
 * 4. Configure plan (duration, fees, down payment)
 * 5. Review and confirm
 */
#[Layout('layouts::admin')]
class Create extends Component
{
    use HasInvoiceManagement;
    use SearchesClients;
    use ValidatesPaymentMethod;

    // Wizard state
    public int $currentStep = 1;

    public const TOTAL_STEPS = 5;

    // Step 1: Client Search
    public string $searchType = 'name'; // 'name', 'client_id', or 'tax_id'

    public string $searchQuery = '';

    public array $searchResults = [];

    public ?array $selectedClient = null;

    // Step 2: Invoice Selection
    public array $availableInvoices = [];

    public array $selectedInvoices = [];

    // Step 3: Payment Method
    public string $paymentMethodType = CustomerPaymentMethod::TYPE_CARD; // 'card' or 'ach'

    public string $cardNumber = '';

    public string $cardExpiry = '';

    public string $cardCvv = '';

    public string $cardName = '';

    public string $accountNumber = '';

    public string $routingNumber = '';

    public string $accountName = '';

    public string $accountType = 'checking';

    // Step 4: Plan Configuration
    public int $planDuration = 3; // 3, 6, or 9 months

    public float $planFee = 0;

    public float $invoiceTotal = 0;

    public float $creditCardFee = 0; // NCA fee (4% of invoice + plan fee) for credit card payments

    public float $totalAmount = 0;

    public float $downPayment = 0; // 30% down payment

    public float $monthlyPayment = 0;

    public bool $splitDownPayment = false; // Admin option to split into two payments

    public array $splitPaymentDetails = []; // Details of split payments

    public bool $waivePlanFee = false; // Admin option to waive the plan fee

    public bool $useCustomDownPayment = false; // Admin option to set a custom down payment

    public string $customDownPaymentAmount = ''; // Custom down payment dollar amount

    public ?int $recurringDay = null; // Admin option to set a custom day-of-month for recurring payments

    // Step 5: Review
    public bool $confirmed = false;

    // Processing state
    public bool $processing = false;

    public ?string $errorMessage = null;

    public ?string $successMessage = null;

    public ?string $createdPlanId = null;

    protected PaymentRepository $paymentRepo;

    public function boot(PaymentRepository $paymentRepo): void
    {
        $this->paymentRepo = $paymentRepo;
    }

    /**
     * Select a client and load their invoices.
     */
    public function selectClient(string $clientId): void
    {
        $this->errorMessage = null;

        try {
            // Get client details
            $client = DB::connection('sqlsrv')->selectOne('
                SELECT
                    client_KEY,
                    client_id,
                    description AS client_name,
                    individual_first_name,
                    individual_last_name,
                    federal_tin
                FROM Client
                WHERE client_id = ?
            ', [$clientId]);

            if (! $client) {
                $this->errorMessage = 'Client not found.';

                return;
            }

            $this->selectedClient = (array) $client;

            // Get client balance
            $balance = $this->paymentRepo->getClientBalance(null, $clientId);
            $this->selectedClient['balance'] = $balance['balance'];

            // Load invoices
            $this->loadInvoices();

            // Move to step 2
            $this->currentStep = 2;
        } catch (\Exception $e) {
            Log::error('Failed to select client', ['error' => $e->getMessage()]);
            $this->errorMessage = 'Failed to load client data. Please try again.';
        }
    }

    /**
     * Clear invoice selection.
     */
    public function clearSelection(): void
    {
        $this->selectedInvoices = [];
        $this->calculateTotals();
    }

    /**
     * Calculate plan totals based on selected invoices and duration.
     * Supports admin overrides for fee waiver and custom down payment.
     * Includes credit card NCA fee when payment method is card.
     */
    public function calculateTotals(): void
    {
        // Calculate invoice total
        $invoiceTotalCents = 0;
        foreach ($this->availableInvoices as $invoice) {
            if (in_array((string) $invoice['ledger_entry_KEY'], $this->selectedInvoices, true)) {
                $invoiceTotalCents += Money::toCents($invoice['open_amount']);
            }
        }
        $this->invoiceTotal = Money::toDollars($invoiceTotalCents);

        // Get plan fee (waived or from config)
        if ($this->waivePlanFee) {
            $this->planFee = 0;
        } else {
            $fees = config('payment-fees.payment_plan_fees', [3 => 150, 6 => 300, 9 => 450]);
            $this->planFee = $fees[$this->planDuration] ?? 0;
        }

        // Calculate credit card NCA fee (4% of invoice + plan fee)
        $subtotal = Money::addDollars($this->invoiceTotal, $this->planFee);
        $this->creditCardFee = $this->getCreditCardFeeRate() > 0
            ? Money::round($subtotal * $this->getCreditCardFeeRate())
            : 0;

        // Calculate totals (invoice + plan fee + NCA)
        $this->totalAmount = Money::addDollars($subtotal, $this->creditCardFee);

        // Calculate down payment (custom or 30%)
        if ($this->useCustomDownPayment && $this->customDownPaymentAmount !== '') {
            $customAmount = (float) $this->customDownPaymentAmount;
            // Clamp to [0, totalAmount]
            $this->downPayment = Money::round(max(0, min($customAmount, $this->totalAmount)));
        } else {
            $this->downPayment = Money::multiplyDollars($this->totalAmount, PaymentPlanCalculator::DOWN_PAYMENT_PERCENT);
        }

        // Calculate monthly payment on remaining balance
        $remainingBalance = Money::subtractDollars($this->totalAmount, $this->downPayment);
        $this->monthlyPayment = $this->planDuration > 0 && $remainingBalance > 0
            ? Money::round($remainingBalance / $this->planDuration)
            : 0;

        // Update split payment details if enabled (only for standard 30% down payment)
        $this->updateSplitPaymentDetails();
    }

    /**
     * Get the credit card NCA fee rate based on the selected payment method.
     *
     * @return float The fee rate (e.g. 0.04 for 4%) or 0 if not applicable
     */
    protected function getCreditCardFeeRate(): float
    {
        if ($this->paymentMethodType === CustomerPaymentMethod::TYPE_CARD) {
            return (float) config('payment-fees.credit_card_rate', 0);
        }

        return 0.0;
    }

    /**
     * Update split down payment details.
     * Split is disabled when using a custom down payment amount.
     */
    protected function updateSplitPaymentDetails(): void
    {
        if (! $this->splitDownPayment || $this->downPayment <= 0 || $this->useCustomDownPayment) {
            $this->splitPaymentDetails = [];

            return;
        }

        $calculator = new PaymentPlanCalculator;
        $this->splitPaymentDetails = $calculator->calculateSplitDownPayment($this->totalAmount);
    }

    /**
     * Toggle split down payment option.
     */
    public function updatedSplitDownPayment(): void
    {
        $this->updateSplitPaymentDetails();
    }

    /**
     * Update plan duration and recalculate.
     */
    public function updatedPlanDuration(): void
    {
        $this->calculateTotals();
    }

    /**
     * Recalculate when fee waiver is toggled.
     */
    public function updatedWaivePlanFee(): void
    {
        $this->calculateTotals();
    }

    /**
     * Handle custom down payment toggle.
     * Resets the custom amount and disables split when toggled off.
     */
    public function updatedUseCustomDownPayment(): void
    {
        if (! $this->useCustomDownPayment) {
            $this->customDownPaymentAmount = '';
            $this->splitDownPayment = false;
        }
        $this->calculateTotals();
    }

    /**
     * Recalculate when custom down payment amount changes.
     */
    public function updatedCustomDownPaymentAmount(): void
    {
        $this->calculateTotals();
    }

    /**
     * Recalculate totals when payment method type changes (card vs ACH).
     * NCA fee only applies to credit card payments.
     */
    public function updatedPaymentMethodType(): void
    {
        $this->calculateTotals();
    }

    /**
     * Recalculate schedule when recurring payment day changes.
     */
    public function updatedRecurringDay(): void
    {
        // Clamp to valid day range (1-31) or null for default
        if ($this->recurringDay !== null) {
            $this->recurringDay = max(1, min(31, $this->recurringDay));
        }
    }

    /**
     * Navigate to a specific step.
     */
    public function goToStep(int $step): void
    {
        // Validate we can go to this step
        if ($step < 1 || $step > self::TOTAL_STEPS) {
            return;
        }

        // Can't skip ahead without completing prerequisites
        if ($step > 1 && ! $this->selectedClient) {
            return;
        }

        if ($step > 2 && count($this->selectedInvoices) === 0) {
            return;
        }

        $this->currentStep = $step;
        $this->errorMessage = null;
    }

    /**
     * Go to next step.
     */
    public function nextStep(): void
    {
        $this->errorMessage = null;

        // Validate current step before proceeding
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
                if (count($this->selectedInvoices) === 0) {
                    $this->errorMessage = 'Please select at least one invoice.';

                    return false;
                }
                $this->calculateTotals();
                break;

            case 3:
                if (! $this->validatePaymentMethod()) {
                    return false;
                }
                // Recalculate totals now that payment method is confirmed
                // (NCA fee depends on card vs ACH)
                $this->calculateTotals();
                break;

            case 4:
                if ($this->invoiceTotal <= 0) {
                    $this->errorMessage = 'Invoice total must be greater than zero.';

                    return false;
                }
                break;
        }

        return true;
    }

    /**
     * Get the supported payment method types.
     *
     * Payment plans do not support saved payment methods.
     */
    protected function supportedPaymentTypes(): array
    {
        return [CustomerPaymentMethod::TYPE_CARD, CustomerPaymentMethod::TYPE_ACH];
    }

    /**
     * Create the payment plan with 30% down payment.
     */
    public function createPlan(PaymentService $paymentService): void
    {
        $this->errorMessage = null;
        $this->processing = true;

        try {
            // Get selected invoice details
            $selectedInvoiceDetails = array_filter($this->availableInvoices, function ($inv) {
                return in_array((string) $inv['ledger_entry_KEY'], $this->selectedInvoices, true);
            });

            // Prepare plan data
            $planData = [
                'amount' => $this->invoiceTotal,
                'planFee' => $this->planFee,
                'creditCardFee' => $this->creditCardFee,
                'planDuration' => $this->planDuration,
                'invoices' => array_values($selectedInvoiceDetails),
                'paymentSchedule' => $this->generatePaymentSchedule(),
                'customDownPayment' => $this->useCustomDownPayment && $this->customDownPaymentAmount !== ''
                    ? max(0, (float) $this->customDownPaymentAmount)
                    : null,
                'recurringDay' => $this->recurringDay,
            ];

            // Prepare client info
            $clientInfo = [
                'client_KEY' => $this->selectedClient['client_KEY'],
                'client_id' => $this->selectedClient['client_id'],
                'client_name' => $this->selectedClient['client_name'],
            ];

            // Generate payment token
            $paymentMethodToken = $this->generatePaymentToken();
            $lastFour = $this->getLastFour();

            // Prepare payment method data for down payment processing
            $paymentMethodData = $this->paymentMethodType === CustomerPaymentMethod::TYPE_CARD
                ? [
                    'type' => CustomerPaymentMethod::TYPE_CARD,
                    'number' => preg_replace('/\D/', '', $this->cardNumber),
                    'expiry' => $this->cardExpiry,
                    'cvv' => $this->cardCvv,
                    'name' => $this->cardName,
                ]
                : [
                    'type' => CustomerPaymentMethod::TYPE_ACH,
                    'routing' => preg_replace('/\D/', '', $this->routingNumber),
                    'account' => preg_replace('/\D/', '', $this->accountNumber),
                    'account_type' => $this->accountType,
                    'name' => $this->accountName,
                ];

            // Create the payment plan with down payment
            $result = $paymentService->createPaymentPlan(
                $planData,
                $clientInfo,
                $paymentMethodToken,
                $this->paymentMethodType,
                $lastFour,
                $paymentMethodData,
                $this->splitDownPayment
            );

            if ($result['success']) {
                $this->createdPlanId = $result['plan_id'];
                if ($result['down_payment'] > 0) {
                    $this->successMessage = 'Payment plan created successfully! Down payment of $'.number_format($result['down_payment'], 2).' has been charged.';
                } else {
                    $this->successMessage = 'Payment plan created successfully! No down payment was charged.';
                }
                $this->currentStep = 6; // Success step

                // Log the activity
                AdminActivity::log(
                    AdminActivity::ACTION_CREATED,
                    $result['payment_plan'],
                    description: "Created payment plan {$result['plan_id']} for {$this->selectedClient['client_name']} - \$".number_format($this->totalAmount, 2)." over {$this->planDuration} months",
                    newValues: [
                        'plan_id' => $result['plan_id'],
                        'client_id' => $this->selectedClient['client_id'],
                        'client_name' => $this->selectedClient['client_name'],
                        'invoice_total' => $this->invoiceTotal,
                        'plan_fee' => $this->planFee,
                        'credit_card_fee' => $this->creditCardFee,
                        'fee_waived' => $this->waivePlanFee,
                        'total_amount' => $this->totalAmount,
                        'down_payment' => $result['down_payment'],
                        'custom_down_payment' => $this->useCustomDownPayment,
                        'monthly_payment' => $this->monthlyPayment,
                        'duration_months' => $this->planDuration,
                        'payment_method' => $this->paymentMethodType,
                        'payment_method_last_four' => $lastFour,
                        'split_down_payment' => $this->splitDownPayment,
                        'recurring_day' => $this->recurringDay,
                        'invoice_count' => count($this->selectedInvoices),
                        'invoice_keys' => $this->selectedInvoices,
                    ]
                );
            } else {
                $this->errorMessage = $result['error'] ?? 'Failed to create payment plan.';
            }
        } catch (\Exception $e) {
            Log::error('Failed to create payment plan', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            $this->errorMessage = 'An error occurred while creating the payment plan.';
        } finally {
            $this->processing = false;
        }
    }

    /**
     * Generate payment schedule preview.
     */
    #[Computed]
    public function paymentSchedule(): array
    {
        return $this->generatePaymentSchedule();
    }

    /**
     * Generate the payment schedule including down payment.
     * Passes admin overrides for fee, down payment, recurring day, and credit card fee rate to the calculator.
     */
    protected function generatePaymentSchedule(): array
    {
        $calculator = new PaymentPlanCalculator;

        $customFee = $this->waivePlanFee ? 0.0 : null;
        $customDownPayment = $this->useCustomDownPayment && $this->customDownPaymentAmount !== ''
            ? max(0, (float) $this->customDownPaymentAmount)
            : null;

        return $calculator->calculateSchedule(
            $this->invoiceTotal,
            $this->planDuration,
            null,
            $this->splitDownPayment && ! $this->useCustomDownPayment,
            $customFee,
            $customDownPayment,
            $this->recurringDay,
            $this->getCreditCardFeeRate()
        );
    }

    /**
     * Generate a payment token (placeholder - real implementation would use payment gateway).
     */
    protected function generatePaymentToken(): string
    {
        // In production, this would integrate with MiPaymentChoice to tokenize the card/ACH
        // For now, we'll create a placeholder token
        return 'admin_token_'.bin2hex(random_bytes(16));
    }

    /**
     * Get last 4 digits of payment method.
     */
    protected function getLastFour(): string
    {
        if ($this->paymentMethodType === CustomerPaymentMethod::TYPE_CARD) {
            $cleaned = preg_replace('/\D/', '', $this->cardNumber);

            return substr($cleaned, -4);
        } else {
            return substr($this->accountNumber, -4);
        }
    }

    /**
     * Reset wizard and start over.
     */
    public function startOver(): void
    {
        $this->reset();
        $this->currentStep = 1;
    }

    public function render()
    {
        return view('livewire.admin.payment-plans.create');
    }
}
