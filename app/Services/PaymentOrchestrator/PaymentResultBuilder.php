<?php

namespace App\Services\PaymentOrchestrator;

use App\Models\Payment;

/**
 * Mutable builder for PaymentResult.
 *
 * The orchestrator creates this after a successful charge, then each
 * subsequent step (record, PracticeCS, engagements, receipt, save method)
 * updates the builder. Finally, build() produces an immutable PaymentResult.
 */
class PaymentResultBuilder
{
    private string $practiceCsStatus = 'skipped';

    private ?string $practiceCsWarning = null;

    private array $engagementResults = [];

    private bool $receiptSent = false;

    private bool $paymentMethodSaved = false;

    public function __construct(
        private readonly Payment $payment,
        private readonly string $transactionId,
        private readonly ?string $gatewayTransactionId,
        private readonly array $chargeResponse = [],
    ) {}

    /**
     * @return $this
     */
    public function practiceCsWritten(): self
    {
        $this->practiceCsStatus = 'written';

        return $this;
    }

    /**
     * @return $this
     */
    public function practiceCsDeferred(): self
    {
        $this->practiceCsStatus = 'deferred';

        return $this;
    }

    /**
     * @return $this
     */
    public function practiceCsFailed(string $warning): self
    {
        $this->practiceCsStatus = 'failed';
        $this->practiceCsWarning = $warning;

        return $this;
    }

    /**
     * @return $this
     */
    public function practiceCsSkipped(): self
    {
        $this->practiceCsStatus = 'skipped';

        return $this;
    }

    /**
     * @param  array  $result  [{key, success, error?, new_type_KEY?}, ...]
     * @return $this
     */
    public function withEngagementResults(array $result): self
    {
        $this->engagementResults = $result;

        return $this;
    }

    /**
     * @return $this
     */
    public function receiptWasSent(bool $sent = true): self
    {
        $this->receiptSent = $sent;

        return $this;
    }

    /**
     * @return $this
     */
    public function paymentMethodWasSaved(bool $saved = true): self
    {
        $this->paymentMethodSaved = $saved;

        return $this;
    }

    /**
     * Get the payment model (needed by orchestrator steps before build).
     */
    public function getPayment(): Payment
    {
        return $this->payment;
    }

    /**
     * Get the transaction ID (needed by orchestrator steps before build).
     */
    public function getTransactionId(): string
    {
        return $this->transactionId;
    }

    /**
     * Get the charge response (needed for PnRef extraction, etc.).
     */
    public function getChargeResponse(): array
    {
        return $this->chargeResponse;
    }

    /**
     * Get the gateway transaction ID.
     */
    public function getGatewayTransactionId(): ?string
    {
        return $this->gatewayTransactionId;
    }

    /**
     * Build the immutable PaymentResult.
     */
    public function build(): PaymentResult
    {
        return new PaymentResult(
            success: true,
            error: null,
            payment: $this->payment,
            transactionId: $this->transactionId,
            gatewayTransactionId: $this->gatewayTransactionId,
            practiceCsStatus: $this->practiceCsStatus,
            practiceCsWarning: $this->practiceCsWarning,
            engagementResults: $this->engagementResults,
            chargeResponse: $this->chargeResponse,
            receiptSent: $this->receiptSent,
            paymentMethodSaved: $this->paymentMethodSaved,
        );
    }
}
