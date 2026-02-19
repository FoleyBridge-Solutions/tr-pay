<?php

// app/Livewire/PaymentRequestFlow.php

namespace App\Livewire;

use App\Livewire\PaymentFlow\HasCardFormatting;
use App\Models\PaymentRequest;
use App\Repositories\PaymentRepository;
use App\Services\PaymentOrchestrator;
use App\Services\PaymentOrchestrator\ProcessPaymentCommand;
use App\Services\PaymentService;
use App\Support\Money;
use Illuminate\Support\Facades\Log;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * PaymentRequestFlow Component
 *
 * Client-facing payment page for email payment requests.
 * Loaded via a tokenized URL (/pay/{token}). Single-use, expires after 30 days.
 *
 * Flow: Token validation -> Review & Pay -> Confirmation
 */
#[Layout('layouts::app')]
class PaymentRequestFlow extends Component
{
    use HasCardFormatting;

    // ==================== Token & Request ====================
    public string $token = '';

    public ?PaymentRequest $paymentRequest = null;

    // ==================== State ====================
    public string $currentStep = 'review'; // review, confirmation, error

    public string $errorType = ''; // expired, paid, revoked

    // ==================== Payment Method ====================
    public ?string $paymentMethod = null; // credit_card, ach

    public string $cardNumber = '';

    public string $cardExpiry = '';

    public string $cardCvv = '';

    public string $bankName = '';

    public string $routingNumber = '';

    public string $accountNumber = '';

    public string $bankAccountType = 'checking';

    public bool $isBusiness = false;

    public bool $achAuthorization = false;

    // ==================== Save Payment Method ====================
    public bool $savePaymentMethod = true;

    public ?string $paymentMethodNickname = null;

    // ==================== Fee ====================
    public float $creditCardFee = 0;

    // ==================== Confirmation ====================
    public ?string $transactionId = null;

    public bool $paymentProcessed = false;

    public array $additionalInvoices = [];

    // ==================== Services ====================
    protected PaymentRepository $paymentRepo;

    protected PaymentService $paymentService;

    public function boot(PaymentRepository $paymentRepo, PaymentService $paymentService): void
    {
        $this->paymentRepo = $paymentRepo;
        $this->paymentService = $paymentService;
    }

    /**
     * Mount the component with the token from the URL.
     */
    public function mount(string $token): void
    {
        $this->token = $token;
        $this->paymentRequest = PaymentRequest::findByToken($token);

        if (! $this->paymentRequest) {
            $this->currentStep = 'error';
            $this->errorType = 'invalid';

            return;
        }

        if ($this->paymentRequest->isPaid()) {
            $this->currentStep = 'error';
            $this->errorType = 'paid';

            return;
        }

        if ($this->paymentRequest->isRevoked()) {
            $this->currentStep = 'error';
            $this->errorType = 'revoked';

            return;
        }

        if ($this->paymentRequest->isExpired()) {
            $this->currentStep = 'error';
            $this->errorType = 'expired';

            return;
        }

        $this->currentStep = 'review';
    }

    /**
     * Select a payment method (card or ACH).
     */
    public function selectPaymentMethod(string $method): void
    {
        $this->paymentMethod = $method;

        if ($method === 'credit_card') {
            $this->creditCardFee = Money::multiplyDollars(
                $this->paymentRequest->amount,
                config('payment-fees.credit_card_rate')
            );
        } else {
            $this->creditCardFee = 0;
        }
    }

    /**
     * Override trait's updatedPaymentMethod to prevent errors.
     *
     * The HasCardFormatting trait's updatedPaymentMethod references properties
     * ($paymentAmount, $paymentPlanFee, $isPaymentPlan) that don't exist on
     * this component. Fee calculation is handled by selectPaymentMethod() instead.
     */
    public function updatedPaymentMethod(string $value): void
    {
        // Fee calculation handled by selectPaymentMethod()
    }

    /**
     * Process the payment.
     */
    public function confirmPayment(): void
    {
        if (! $this->paymentRequest || ! $this->paymentRequest->isUsable()) {
            $this->addError('payment', 'This payment link is no longer valid.');

            return;
        }

        // Validate payment method selection
        if (! $this->paymentMethod) {
            $this->addError('payment', 'Please select a payment method.');

            return;
        }

        // Validate payment fields
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

        try {
            // Build client info from the payment request
            $clientInfo = [
                'client_KEY' => $this->paymentRequest->client_key,
                'client_id' => $this->paymentRequest->client_id,
                'client_name' => $this->paymentRequest->client_name,
                'email' => $this->paymentRequest->email,
            ];

            // Get or create customer
            $customer = $this->paymentService->getOrCreateCustomer($clientInfo);

            // Build invoice details from stored invoices
            $invoices = $this->paymentRequest->invoices ?? [];
            $selectedInvoiceNumbers = array_column($invoices, 'invoice_number');
            $invoiceDetails = array_map(function ($inv) {
                return [
                    'invoice_number' => $inv['invoice_number'],
                    'description' => $inv['description'] ?? '',
                    'amount' => $inv['open_amount'] ?? 0,
                    'ledger_entry_KEY' => $inv['ledger_entry_KEY'] ?? null,
                    'open_amount' => $inv['open_amount'] ?? 0,
                    'client_KEY' => $inv['client_KEY'] ?? $this->paymentRequest->client_key,
                ];
            }, $invoices);

            // Get current open invoices for distribution
            $openInvoices = [];
            if ($this->paymentRequest->client_key) {
                $openInvoices = $this->paymentRepo->getClientOpenInvoices($this->paymentRequest->client_key);
            }

            // Build the orchestrator command
            $orchestrator = app(PaymentOrchestrator::class);

            if ($this->paymentMethod === 'credit_card') {
                $command = ProcessPaymentCommand::emailRequestCardPayment(
                    customer: $customer,
                    amount: $this->paymentRequest->amount,
                    fee: $this->creditCardFee,
                    clientInfo: $clientInfo,
                    selectedInvoiceNumbers: $selectedInvoiceNumbers,
                    invoiceDetails: $invoiceDetails,
                    openInvoices: $openInvoices,
                    cardDetails: [
                        'number' => str_replace(' ', '', $this->cardNumber),
                        'exp_month' => (int) substr($this->cardExpiry, 0, 2),
                        'exp_year' => (int) ('20'.substr($this->cardExpiry, 3, 2)),
                        'cvc' => $this->cardCvv,
                        'name' => $this->paymentRequest->client_name,
                        'street' => '',
                        'zip_code' => '',
                        'email' => $this->paymentRequest->email,
                    ],
                    sendReceipt: true,
                    savePaymentMethod: $this->savePaymentMethod,
                    paymentMethodNickname: $this->paymentMethodNickname,
                );
            } else {
                $command = ProcessPaymentCommand::emailRequestAchPayment(
                    customer: $customer,
                    amount: $this->paymentRequest->amount,
                    clientInfo: $clientInfo,
                    selectedInvoiceNumbers: $selectedInvoiceNumbers,
                    invoiceDetails: $invoiceDetails,
                    openInvoices: $openInvoices,
                    achDetails: [
                        'routing_number' => $this->routingNumber,
                        'account_number' => $this->accountNumber,
                        'account_type' => $this->bankAccountType,
                        'account_name' => $this->paymentRequest->client_name,
                        'is_business' => (bool) $this->isBusiness,
                        'bank_name' => $this->bankName,
                    ],
                    sendReceipt: true,
                    savePaymentMethod: $this->savePaymentMethod,
                    paymentMethodNickname: $this->paymentMethodNickname,
                );
            }

            $result = $orchestrator->processPayment($command);

            if (! $result->success) {
                $this->addError('payment', $result->error ?? 'Payment processing failed');

                return;
            }

            // Mark the payment request as paid
            $this->paymentRequest->markPaid($result->payment->id);

            $this->transactionId = $result->transactionId;
            $this->paymentProcessed = true;

            // Check for additional open invoices
            $this->loadAdditionalInvoices($openInvoices, $selectedInvoiceNumbers);

            $this->currentStep = 'confirmation';

        } catch (\Exception $e) {
            Log::error('Payment request processing failed', [
                'token' => $this->token,
                'error' => $e->getMessage(),
                'client_id' => $this->paymentRequest->client_id,
            ]);

            $this->addError('payment', 'Payment processing failed: '.$e->getMessage());
        }
    }

    /**
     * Check if the client has other open invoices not included in this request.
     *
     * @param  array  $allOpenInvoices  All current open invoices
     * @param  array  $paidInvoiceNumbers  Invoice numbers paid in this request
     */
    protected function loadAdditionalInvoices(array $allOpenInvoices, array $paidInvoiceNumbers): void
    {
        $this->additionalInvoices = array_values(array_filter(
            $allOpenInvoices,
            fn ($inv) => ! in_array($inv['invoice_number'], $paidInvoiceNumbers)
        ));
    }

    /**
     * Get the total amount of additional unpaid invoices.
     */
    public function getAdditionalInvoicesTotalProperty(): float
    {
        return array_sum(array_column($this->additionalInvoices, 'open_amount'));
    }

    public function render()
    {
        return view('livewire.payment-request-flow');
    }
}
