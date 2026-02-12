<?php

namespace App\Services\PaymentOrchestrator;

use App\Models\Payment;

/**
 * Structured result object returned by PaymentOrchestrator::processPayment().
 *
 * Captures the outcome of every orchestration step so callers can inspect
 * what happened without parsing log messages or catching exceptions.
 */
class PaymentResult
{
    /**
     * @param  bool  $success  Whether the payment charge succeeded
     * @param  ?string  $error  Error message if charge failed
     * @param  ?Payment  $payment  The recorded Payment model (null if charge failed)
     * @param  string  $transactionId  Internal transaction ID (always set)
     * @param  ?string  $gatewayTransactionId  Gateway-assigned transaction ID (PnRef, Kotapay ID, etc.)
     * @param  string  $practiceCsStatus  One of: written, deferred, skipped, failed
     * @param  ?string  $practiceCsWarning  Warning message if PracticeCS write partially failed
     * @param  array  $engagementResults  Per-engagement outcomes [{key, success, error?, new_type_KEY?}, ...]
     * @param  array  $chargeResponse  Raw gateway response array (for PnRef extraction, etc.)
     * @param  bool  $receiptSent  Whether a receipt email was sent
     * @param  bool  $paymentMethodSaved  Whether the payment method was saved for future use
     */
    public function __construct(
        public readonly bool $success,
        public readonly ?string $error,
        public readonly ?Payment $payment,
        public readonly string $transactionId,
        public readonly ?string $gatewayTransactionId,
        public readonly string $practiceCsStatus,
        public readonly ?string $practiceCsWarning,
        public readonly array $engagementResults,
        public readonly array $chargeResponse,
        public readonly bool $receiptSent,
        public readonly bool $paymentMethodSaved,
    ) {}

    /**
     * Create a failed result (charge did not succeed).
     *
     * @param  string  $error  Human-readable error message
     * @param  string  $transactionId  The attempted transaction ID
     */
    public static function failed(string $error, string $transactionId): self
    {
        return new self(
            success: false,
            error: $error,
            payment: null,
            transactionId: $transactionId,
            gatewayTransactionId: null,
            practiceCsStatus: 'skipped',
            practiceCsWarning: null,
            engagementResults: [],
            chargeResponse: [],
            receiptSent: false,
            paymentMethodSaved: false,
        );
    }

    /**
     * Create a successful result builder. Returns a mutable builder
     * so orchestrator steps can progressively fill in results.
     *
     * @param  Payment  $payment  The recorded payment
     * @param  string  $transactionId  Internal transaction ID
     * @param  ?string  $gatewayTransactionId  Gateway transaction ID
     * @param  array  $chargeResponse  Raw gateway response
     */
    public static function builder(
        Payment $payment,
        string $transactionId,
        ?string $gatewayTransactionId,
        array $chargeResponse = [],
    ): PaymentResultBuilder {
        return new PaymentResultBuilder(
            payment: $payment,
            transactionId: $transactionId,
            gatewayTransactionId: $gatewayTransactionId,
            chargeResponse: $chargeResponse,
        );
    }

    /**
     * Whether PracticeCS was written successfully (immediately or deferred).
     */
    public function practiceCsOk(): bool
    {
        return in_array($this->practiceCsStatus, ['written', 'deferred'], true);
    }

    /**
     * Whether any engagement updates failed.
     */
    public function hasEngagementFailures(): bool
    {
        foreach ($this->engagementResults as $result) {
            if (! ($result['success'] ?? false)) {
                return true;
            }
        }

        return false;
    }
}
