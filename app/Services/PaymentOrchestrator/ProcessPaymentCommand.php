<?php

// app/Services/PaymentOrchestrator/ProcessPaymentCommand.php

namespace App\Services\PaymentOrchestrator;

use App\Models\Customer;
use App\Models\CustomerPaymentMethod;

/**
 * Command object representing a payment processing request.
 *
 * Encapsulates all data needed to process a one-time payment through any flow
 * (public, admin, saved method). Immutable after construction — use static
 * factory methods to create instances.
 *
 * Does NOT cover payment plans or scheduled payments (those remain in their
 * existing well-encapsulated services).
 */
class ProcessPaymentCommand
{
    // Charge method constants
    public const CHARGE_CARD = 'card';

    public const CHARGE_ACH = 'ach';

    public const CHARGE_SAVED = 'saved';

    public const CHARGE_CHECK = 'check';

    // Source constants
    public const SOURCE_PUBLIC = 'tr-pay';

    public const SOURCE_ADMIN = 'tr-pay-admin';

    /**
     * @param  string  $chargeMethod  One of: card, ach, saved, check
     * @param  Customer  $customer  The local customer record
     * @param  float  $amount  Base payment amount in dollars (applied to client account)
     * @param  float  $fee  Credit card fee in dollars (0 for ACH/check)
     * @param  bool  $feeIncludedInAmount  True if $amount already includes $fee (admin "fee included" mode)
     * @param  array  $clientInfo  Client data from PracticeCS (client_KEY, client_id, client_name, email, etc.)
     * @param  array  $selectedInvoiceNumbers  Invoice numbers selected by user
     * @param  array  $invoiceDetails  Full invoice data [{invoice_number, description, amount, ledger_entry_KEY, open_amount, client_KEY}, ...]
     * @param  bool  $leaveUnapplied  Admin only: don't apply to invoices (credit balance)
     * @param  array  $openInvoices  All open invoices (for group distribution calculation)
     * @param  string  $source  Source tag: 'tr-pay' or 'tr-pay-admin'
     * @param  bool  $sendReceipt  Whether to send a receipt email
     * @param  bool  $savePaymentMethod  Whether to save the payment method after success
     * @param  ?string  $paymentMethodNickname  Optional nickname for saved method
     * @param  ?string  $transactionId  Pre-generated transaction ID (null = auto-generate)
     * @param  ?array  $cardDetails  Card data: [number, exp_month, exp_year, cvc, name, street, zip_code, email]
     * @param  ?array  $achDetails  ACH data: [routing_number, account_number, account_type, account_name, is_business]
     * @param  ?CustomerPaymentMethod  $savedMethod  Saved payment method to charge
     * @param  array  $engagements  Queued engagement acceptances [{project_engagement_key, client_key, ...}, ...]
     * @param  string  $acceptanceSignature  Signature label: 'Checkbox Accepted' or 'Admin Accepted'
     * @param  array  $selectedEngagementKeys  Admin: selected engagement keys for filtering
     * @param  array  $pendingEngagements  Admin: full engagement data for persistence
     * @param  string  $paymentMethodLabel  Display label: 'credit_card', 'ach', 'check' (for descriptions/logging)
     */
    public function __construct(
        public readonly string $chargeMethod,
        public readonly Customer $customer,
        public readonly float $amount,
        public readonly float $fee,
        public readonly bool $feeIncludedInAmount,
        public readonly array $clientInfo,
        public readonly array $selectedInvoiceNumbers,
        public readonly array $invoiceDetails,
        public readonly bool $leaveUnapplied,
        public readonly array $openInvoices,
        public readonly string $source,
        public readonly bool $sendReceipt,
        public readonly bool $savePaymentMethod,
        public readonly ?string $paymentMethodNickname,
        public readonly ?string $transactionId,
        public readonly ?array $cardDetails,
        public readonly ?array $achDetails,
        public readonly ?CustomerPaymentMethod $savedMethod,
        public readonly array $engagements,
        public readonly string $acceptanceSignature,
        public readonly array $selectedEngagementKeys,
        public readonly array $pendingEngagements,
        public readonly string $paymentMethodLabel,
    ) {}

    /**
     * Create a command for a new card payment (public flow).
     */
    public static function cardPayment(
        Customer $customer,
        float $amount,
        float $fee,
        array $clientInfo,
        array $selectedInvoiceNumbers,
        array $invoiceDetails,
        array $openInvoices,
        array $cardDetails,
        array $engagements = [],
        bool $sendReceipt = true,
        bool $savePaymentMethod = false,
        ?string $paymentMethodNickname = null,
    ): self {
        return new self(
            chargeMethod: self::CHARGE_CARD,
            customer: $customer,
            amount: $amount,
            fee: $fee,
            feeIncludedInAmount: false,
            clientInfo: $clientInfo,
            selectedInvoiceNumbers: $selectedInvoiceNumbers,
            invoiceDetails: $invoiceDetails,
            leaveUnapplied: false,
            openInvoices: $openInvoices,
            source: self::SOURCE_PUBLIC,
            sendReceipt: $sendReceipt,
            savePaymentMethod: $savePaymentMethod,
            paymentMethodNickname: $paymentMethodNickname,
            transactionId: null,
            cardDetails: $cardDetails,
            achDetails: null,
            savedMethod: null,
            engagements: $engagements,
            acceptanceSignature: 'Checkbox Accepted',
            selectedEngagementKeys: [],
            pendingEngagements: [],
            paymentMethodLabel: 'credit_card',
        );
    }

    /**
     * Create a command for a new ACH payment (public flow).
     */
    public static function achPayment(
        Customer $customer,
        float $amount,
        array $clientInfo,
        array $selectedInvoiceNumbers,
        array $invoiceDetails,
        array $openInvoices,
        array $achDetails,
        array $engagements = [],
        bool $sendReceipt = true,
        bool $savePaymentMethod = false,
        ?string $paymentMethodNickname = null,
    ): self {
        return new self(
            chargeMethod: self::CHARGE_ACH,
            customer: $customer,
            amount: $amount,
            fee: 0,
            feeIncludedInAmount: false,
            clientInfo: $clientInfo,
            selectedInvoiceNumbers: $selectedInvoiceNumbers,
            invoiceDetails: $invoiceDetails,
            leaveUnapplied: false,
            openInvoices: $openInvoices,
            source: self::SOURCE_PUBLIC,
            sendReceipt: $sendReceipt,
            savePaymentMethod: $savePaymentMethod,
            paymentMethodNickname: $paymentMethodNickname,
            transactionId: null,
            cardDetails: null,
            achDetails: $achDetails,
            savedMethod: null,
            engagements: $engagements,
            acceptanceSignature: 'Checkbox Accepted',
            selectedEngagementKeys: [],
            pendingEngagements: [],
            paymentMethodLabel: 'ach',
        );
    }

    /**
     * Create a command for a saved payment method (public flow).
     */
    public static function savedMethodPayment(
        Customer $customer,
        float $amount,
        float $fee,
        array $clientInfo,
        array $selectedInvoiceNumbers,
        array $invoiceDetails,
        array $openInvoices,
        CustomerPaymentMethod $savedMethod,
        array $engagements = [],
        bool $sendReceipt = true,
    ): self {
        $isCard = $savedMethod->type === CustomerPaymentMethod::TYPE_CARD;

        return new self(
            chargeMethod: self::CHARGE_SAVED,
            customer: $customer,
            amount: $amount,
            fee: $isCard ? $fee : 0,
            feeIncludedInAmount: false,
            clientInfo: $clientInfo,
            selectedInvoiceNumbers: $selectedInvoiceNumbers,
            invoiceDetails: $invoiceDetails,
            leaveUnapplied: false,
            openInvoices: $openInvoices,
            source: self::SOURCE_PUBLIC,
            sendReceipt: $sendReceipt,
            savePaymentMethod: false,
            paymentMethodNickname: null,
            transactionId: null,
            cardDetails: null,
            achDetails: null,
            savedMethod: $savedMethod,
            engagements: $engagements,
            acceptanceSignature: 'Checkbox Accepted',
            selectedEngagementKeys: [],
            pendingEngagements: [],
            paymentMethodLabel: $isCard ? 'credit_card' : 'ach',
        );
    }

    /**
     * Create a command for a check payment (public flow stub).
     */
    public static function checkPayment(
        Customer $customer,
        float $amount,
        array $clientInfo,
        array $selectedInvoiceNumbers,
        array $invoiceDetails,
        array $openInvoices,
        array $engagements = [],
        bool $sendReceipt = true,
    ): self {
        return new self(
            chargeMethod: self::CHARGE_CHECK,
            customer: $customer,
            amount: $amount,
            fee: 0,
            feeIncludedInAmount: false,
            clientInfo: $clientInfo,
            selectedInvoiceNumbers: $selectedInvoiceNumbers,
            invoiceDetails: $invoiceDetails,
            leaveUnapplied: false,
            openInvoices: $openInvoices,
            source: self::SOURCE_PUBLIC,
            sendReceipt: $sendReceipt,
            savePaymentMethod: false,
            paymentMethodNickname: null,
            transactionId: null,
            cardDetails: null,
            achDetails: null,
            savedMethod: null,
            engagements: $engagements,
            acceptanceSignature: 'Checkbox Accepted',
            selectedEngagementKeys: [],
            pendingEngagements: [],
            paymentMethodLabel: 'check',
        );
    }

    /**
     * Create a command for an admin card payment (manual entry).
     */
    public static function adminCardPayment(
        Customer $customer,
        float $amount,
        float $fee,
        bool $feeIncludedInAmount,
        array $clientInfo,
        array $selectedInvoiceNumbers,
        array $invoiceDetails,
        array $cardDetails,
        bool $leaveUnapplied = false,
        array $selectedEngagementKeys = [],
        array $pendingEngagements = [],
    ): self {
        return new self(
            chargeMethod: self::CHARGE_CARD,
            customer: $customer,
            amount: $amount,
            fee: $fee,
            feeIncludedInAmount: $feeIncludedInAmount,
            clientInfo: $clientInfo,
            selectedInvoiceNumbers: $selectedInvoiceNumbers,
            invoiceDetails: $invoiceDetails,
            leaveUnapplied: $leaveUnapplied,
            openInvoices: [],
            source: self::SOURCE_ADMIN,
            sendReceipt: false,
            savePaymentMethod: false,
            paymentMethodNickname: null,
            transactionId: null,
            cardDetails: $cardDetails,
            achDetails: null,
            savedMethod: null,
            engagements: [],
            acceptanceSignature: 'Admin Accepted',
            selectedEngagementKeys: $selectedEngagementKeys,
            pendingEngagements: $pendingEngagements,
            paymentMethodLabel: 'credit_card',
        );
    }

    /**
     * Create a command for an admin ACH payment (manual entry).
     */
    public static function adminAchPayment(
        Customer $customer,
        float $amount,
        array $clientInfo,
        array $selectedInvoiceNumbers,
        array $invoiceDetails,
        array $achDetails,
        bool $leaveUnapplied = false,
        array $selectedEngagementKeys = [],
        array $pendingEngagements = [],
    ): self {
        return new self(
            chargeMethod: self::CHARGE_ACH,
            customer: $customer,
            amount: $amount,
            fee: 0,
            feeIncludedInAmount: false,
            clientInfo: $clientInfo,
            selectedInvoiceNumbers: $selectedInvoiceNumbers,
            invoiceDetails: $invoiceDetails,
            leaveUnapplied: $leaveUnapplied,
            openInvoices: [],
            source: self::SOURCE_ADMIN,
            sendReceipt: false,
            savePaymentMethod: false,
            paymentMethodNickname: null,
            transactionId: null,
            cardDetails: null,
            achDetails: $achDetails,
            savedMethod: null,
            engagements: [],
            acceptanceSignature: 'Admin Accepted',
            selectedEngagementKeys: $selectedEngagementKeys,
            pendingEngagements: $pendingEngagements,
            paymentMethodLabel: 'ach',
        );
    }

    /**
     * Create a command for an admin payment using a saved method.
     */
    public static function adminSavedMethodPayment(
        Customer $customer,
        float $amount,
        float $fee,
        bool $feeIncludedInAmount,
        array $clientInfo,
        array $selectedInvoiceNumbers,
        array $invoiceDetails,
        CustomerPaymentMethod $savedMethod,
        bool $leaveUnapplied = false,
        array $selectedEngagementKeys = [],
        array $pendingEngagements = [],
    ): self {
        $isCard = $savedMethod->type === CustomerPaymentMethod::TYPE_CARD;

        return new self(
            chargeMethod: self::CHARGE_SAVED,
            customer: $customer,
            amount: $amount,
            fee: $isCard ? $fee : 0,
            feeIncludedInAmount: $feeIncludedInAmount,
            clientInfo: $clientInfo,
            selectedInvoiceNumbers: $selectedInvoiceNumbers,
            invoiceDetails: $invoiceDetails,
            leaveUnapplied: $leaveUnapplied,
            openInvoices: [],
            source: self::SOURCE_ADMIN,
            sendReceipt: false,
            savePaymentMethod: false,
            paymentMethodNickname: null,
            transactionId: null,
            cardDetails: null,
            achDetails: null,
            savedMethod: $savedMethod,
            engagements: [],
            acceptanceSignature: 'Admin Accepted',
            selectedEngagementKeys: $selectedEngagementKeys,
            pendingEngagements: $pendingEngagements,
            paymentMethodLabel: $isCard ? 'credit_card' : 'ach',
        );
    }

    /**
     * Total amount to charge the gateway (amount + fee, or just amount if fee included).
     */
    public function totalCharge(): float
    {
        if ($this->feeIncludedInAmount) {
            return \App\Support\Money::round($this->amount);
        }

        return \App\Support\Money::addDollars($this->amount, $this->fee);
    }

    /**
     * Base amount applied to client account (excludes fee).
     *
     * When feeIncludedInAmount is true, this is the amount minus the back-calculated fee.
     */
    public function baseAmount(): float
    {
        if ($this->feeIncludedInAmount && $this->fee > 0) {
            return \App\Support\Money::subtractDollars($this->amount, $this->fee);
        }

        return $this->amount;
    }

    /**
     * Whether this is an ACH payment (new or saved ACH method).
     */
    public function isAch(): bool
    {
        if ($this->chargeMethod === self::CHARGE_ACH) {
            return true;
        }

        if ($this->chargeMethod === self::CHARGE_SAVED && $this->savedMethod) {
            return $this->savedMethod->isAch();
        }

        return false;
    }

    /**
     * Whether this is a card payment (new or saved card method).
     */
    public function isCard(): bool
    {
        if ($this->chargeMethod === self::CHARGE_CARD) {
            return true;
        }

        if ($this->chargeMethod === self::CHARGE_SAVED && $this->savedMethod) {
            return $this->savedMethod->isCard();
        }

        return false;
    }

    /**
     * Whether this is from the admin flow.
     */
    public function isAdmin(): bool
    {
        return $this->source === self::SOURCE_ADMIN;
    }

    /**
     * Get the client name from clientInfo.
     */
    public function clientName(): string
    {
        return $this->clientInfo['client_name'] ?? 'Unknown';
    }

    /**
     * Get the client email from clientInfo.
     */
    public function clientEmail(): ?string
    {
        return $this->clientInfo['email'] ?? null;
    }

    /**
     * Get the primary client KEY from clientInfo.
     */
    public function primaryClientKey(): int|string
    {
        return $this->clientInfo['client_KEY']
            ?? $this->clientInfo['clients'][0]['client_KEY']
            ?? 0;
    }

    /**
     * Get the last four digits of the payment method.
     */
    public function lastFour(): string
    {
        if ($this->savedMethod) {
            return $this->savedMethod->last_four;
        }

        if ($this->chargeMethod === self::CHARGE_CARD && $this->cardDetails) {
            return substr(preg_replace('/\D/', '', $this->cardDetails['number'] ?? ''), -4);
        }

        if ($this->chargeMethod === self::CHARGE_ACH && $this->achDetails) {
            return substr($this->achDetails['account_number'] ?? '', -4);
        }

        return '****';
    }

    /**
     * Build a human-readable description for the payment.
     */
    public function description(): string
    {
        $prefix = $this->isAdmin() ? 'Admin payment' : 'Payment';
        $invoiceCount = $this->leaveUnapplied ? 0 : count($this->selectedInvoiceNumbers);
        $engagementCount = count($this->selectedEngagementKeys);

        if ($this->leaveUnapplied) {
            return "{$prefix} - Unapplied (credit balance)";
        }

        if ($engagementCount > 0 && $invoiceCount > 0) {
            return "{$prefix} for {$this->clientName()} - {$invoiceCount} invoice(s), {$engagementCount} fee request(s)";
        }

        if ($engagementCount > 0) {
            return "{$prefix} for {$this->clientName()} - {$engagementCount} fee request(s)";
        }

        return "{$prefix} for {$this->clientName()} - {$invoiceCount} invoice(s)";
    }
}
