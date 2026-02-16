<?php

namespace App\Services;

use App\Mail\PaymentReceipt;
use App\Models\Customer;
use App\Models\CustomerPaymentMethod;
use App\Models\Payment;
use App\Models\ProjectAcceptance;
use App\Notifications\EngagementSyncFailed;
use App\Notifications\PracticeCsWriteFailed;
use App\Services\PaymentOrchestrator\PaymentResult;
use App\Services\PaymentOrchestrator\PaymentResultBuilder;
use App\Services\PaymentOrchestrator\ProcessPaymentCommand;
use App\Support\Money;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Unified payment processing orchestrator.
 *
 * Single entry point for all one-time payment flows (public card/ACH/check/saved,
 * admin card/ACH/saved). Coordinates: charge → record → save method → PracticeCS → engagements → receipt.
 *
 * Does NOT handle payment plans or scheduled payments.
 */
class PaymentOrchestrator
{
    public function __construct(
        private readonly PaymentService $paymentService,
        private readonly PracticeCsPaymentWriter $practiceWriter,
        private readonly EngagementAcceptanceService $engagementService,
    ) {}

    /**
     * Process a one-time payment end-to-end.
     *
     * @param  ProcessPaymentCommand  $command  Immutable command with all payment data
     * @return PaymentResult Structured result with outcome of every step
     */
    public function processPayment(ProcessPaymentCommand $command): PaymentResult
    {
        // Step 1: Generate transaction ID
        $transactionId = $command->transactionId ?? 'txn_'.bin2hex(random_bytes(16));

        // Step 2: Route to charge method
        try {
            $chargeResult = $this->charge($command, $transactionId);
        } catch (\Exception $e) {
            Log::error('Payment charge failed', [
                'transaction_id' => $transactionId,
                'charge_method' => $command->chargeMethod,
                'error' => $e->getMessage(),
            ]);

            return PaymentResult::failed($e->getMessage(), $transactionId);
        }

        // Step 3: Validate charge result
        if (! $chargeResult['success']) {
            return PaymentResult::failed(
                $chargeResult['error'] ?? 'Payment processing failed',
                $transactionId
            );
        }

        $gatewayTransactionId = $chargeResult['transaction_id'] ?? null;

        Log::info('Payment charged successfully', [
            'transaction_id' => $transactionId,
            'gateway_transaction_id' => $gatewayTransactionId,
            'amount' => $command->totalCharge(),
            'charge_method' => $command->chargeMethod,
            'source' => $command->source,
        ]);

        // Step 4: Record payment to DB
        $payment = $this->recordPayment($command, $transactionId, $gatewayTransactionId, $chargeResult);

        $builder = PaymentResult::builder(
            $payment,
            $transactionId,
            $gatewayTransactionId,
            $chargeResult['response'] ?? $chargeResult,
        );

        // Step 5: Save payment method (if requested — public flow only)
        if ($command->savePaymentMethod) {
            $saved = $this->trySavePaymentMethod($command, $command->customer, $chargeResult);
            $builder->paymentMethodWasSaved($saved);
        }

        // Step 6: PracticeCS write/defer
        $this->handlePracticeCs($command, $payment, $chargeResult, $builder);

        // Step 7: Persist engagements
        $engagementResults = $this->persistEngagements($command, $transactionId);
        $builder->withEngagementResults($engagementResults);

        // Step 8: Send receipt email (if requested — public flow only)
        if ($command->sendReceipt) {
            $sent = $this->trySendReceipt($command, $transactionId);
            $builder->receiptWasSent($sent);
        }

        return $builder->build();
    }

    // =========================================================================
    // Step 2: Charge routing
    // =========================================================================

    /**
     * Route the charge to the appropriate gateway.
     *
     * @param  ProcessPaymentCommand  $command  The payment command
     * @param  string  $transactionId  Generated transaction ID
     * @return array Gateway result array with 'success', 'transaction_id', 'response', etc.
     */
    private function charge(ProcessPaymentCommand $command, string $transactionId): array
    {
        return match ($command->chargeMethod) {
            ProcessPaymentCommand::CHARGE_CARD => $this->chargeNewCard($command, $transactionId),
            ProcessPaymentCommand::CHARGE_ACH => $this->chargeNewAch($command),
            ProcessPaymentCommand::CHARGE_SAVED => $this->chargeSavedMethod($command),
            ProcessPaymentCommand::CHARGE_CHECK => $this->stubCheckPayment($command, $transactionId),
            default => throw new \InvalidArgumentException("Unknown charge method: {$command->chargeMethod}"),
        };
    }

    /**
     * Charge a new card via QuickPayments token.
     */
    private function chargeNewCard(ProcessPaymentCommand $command, string $transactionId): array
    {
        $card = $command->cardDetails;
        $customer = $command->customer;

        // Admin flow uses QuickPaymentsService directly; public uses customer->createQuickPaymentsToken
        // Both ultimately go through the same gateway — unify via PaymentService
        $qpToken = $customer->createQuickPaymentsToken([
            'number' => preg_replace('/\D/', '', $card['number'] ?? ''),
            'exp_month' => (int) ($card['exp_month'] ?? 0),
            'exp_year' => (int) ($card['exp_year'] ?? 0),
            'cvc' => $card['cvc'] ?? '',
            'name' => $card['name'] ?? $command->clientName(),
            'street' => $card['street'] ?? '',
            'postal_code' => $card['zip_code'] ?? '',
        ]);

        return $this->paymentService->chargeWithQuickPayments(
            $customer,
            $qpToken,
            $command->totalCharge(),
            [
                'description' => $command->description(),
                'invoice_number' => $transactionId,
                'force_duplicate' => $command->isAdmin(),
            ]
        );
    }

    /**
     * Charge new ACH via Kotapay.
     */
    private function chargeNewAch(ProcessPaymentCommand $command): array
    {
        $ach = $command->achDetails;

        // Generate a unique AccountNameId for Kotapay report matching.
        // This appears as EntryID in returns/corrections reports and persists
        // after Kotapay purges the transaction record post-batching.
        $accountNameId = 'TP-'.bin2hex(random_bytes(4));

        return $this->paymentService->chargeAchWithKotapay(
            $command->customer,
            [
                'routing_number' => preg_replace('/\D/', '', $ach['routing_number'] ?? ''),
                'account_number' => preg_replace('/\D/', '', $ach['account_number'] ?? ''),
                'account_type' => ucfirst($ach['account_type'] ?? 'checking'),
                'account_name' => $ach['account_name'] ?? $command->clientName(),
                'is_business' => $ach['is_business'] ?? false,
            ],
            $command->baseAmount(), // ACH charges base amount (no fee)
            [
                'description' => $command->description(),
                'account_name_id' => $accountNameId,
            ]
        );
    }

    /**
     * Charge a saved payment method.
     */
    private function chargeSavedMethod(ProcessPaymentCommand $command): array
    {
        $savedMethod = $command->savedMethod;
        if (! $savedMethod) {
            return ['success' => false, 'error' => 'No saved payment method provided'];
        }

        return $this->paymentService->chargeWithSavedMethod(
            $command->customer,
            $savedMethod,
            $command->totalCharge(),
            [
                'description' => $command->description(),
            ]
        );
    }

    /**
     * Stub for check payments (no gateway charge).
     */
    private function stubCheckPayment(ProcessPaymentCommand $command, string $transactionId): array
    {
        return [
            'success' => true,
            'transaction_id' => $transactionId,
            'amount' => $command->amount,
            'status' => 'pending_check',
            'message' => 'Check payment logged for manual processing',
            'response' => [],
        ];
    }

    // =========================================================================
    // Step 4: Record payment
    // =========================================================================

    /**
     * Record the payment to the local database.
     *
     * Unifies the divergent recording logic: public used PaymentService::recordPayment()
     * with {invoices, client_name} metadata; admin used Payment::create() directly with
     * different metadata. Now both go through a single path with superset metadata.
     */
    private function recordPayment(
        ProcessPaymentCommand $command,
        string $transactionId,
        ?string $gatewayTransactionId,
        array $chargeResult,
    ): Payment {
        $isAch = $command->isAch();
        $baseAmount = $command->baseAmount();
        $fee = $command->fee;

        return Payment::create([
            'customer_id' => $command->customer->id,
            'client_id' => $command->clientInfo['client_id'] ?? null,
            'transaction_id' => $transactionId,
            'amount' => Money::round($baseAmount),
            'fee' => Money::round($fee),
            'total_amount' => Money::addDollars($baseAmount, $fee),
            'payment_method' => $command->paymentMethodLabel,
            'payment_method_last_four' => $command->lastFour(),
            'status' => $isAch ? Payment::STATUS_PROCESSING : Payment::STATUS_COMPLETED,
            'processed_at' => $isAch ? null : now(),
            'payment_vendor' => $isAch ? ($chargeResult['payment_vendor'] ?? 'kotapay') : null,
            'vendor_transaction_id' => $isAch
                ? ($gatewayTransactionId ?? $transactionId)
                : null,
            'description' => $command->description(),
            'metadata' => array_filter([
                'source' => $command->source,
                'client_id' => $command->clientInfo['client_id'] ?? null,
                'client_name' => $command->clientName(),
                'gateway_transaction_id' => $gatewayTransactionId,
                'invoice_keys' => $command->leaveUnapplied ? [] : $command->selectedInvoiceNumbers,
                'engagement_keys' => $command->selectedEngagementKeys,
                'pending_engagements' => $command->pendingEngagements ?: null,
                'unapplied' => $command->leaveUnapplied ?: null,
                'fee_included_in_amount' => $command->feeIncludedInAmount ?: null,
                'is_business' => $command->isAch() ? ($command->achDetails['is_business'] ?? null) : null,
                'kotapay_account_name_id' => $isAch ? ($chargeResult['account_name_id'] ?? null) : null,
                'kotapay_effective_date' => $isAch ? ($chargeResult['effective_date'] ?? now()->format('Y-m-d')) : null,
            ], fn ($v) => $v !== null),
        ]);
    }

    // =========================================================================
    // Step 5: Save payment method
    // =========================================================================

    /**
     * Try to save the payment method for future use. Log-and-continue on failure.
     *
     * @return bool Whether the method was saved
     */
    private function trySavePaymentMethod(ProcessPaymentCommand $command, Customer $customer, array $chargeResult): bool
    {
        try {
            if ($command->isCard()) {
                return $this->saveCardMethod($command, $customer, $chargeResult);
            }

            if ($command->isAch()) {
                return $this->saveAchMethod($command, $customer);
            }

            return false;
        } catch (\Exception $e) {
            Log::warning('Failed to save payment method after payment', [
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Save card payment method by tokenizing from the successful transaction's PnRef.
     */
    private function saveCardMethod(ProcessPaymentCommand $command, Customer $customer, array $chargeResult): bool
    {
        $response = $chargeResult['response'] ?? [];
        $pnRef = $response['PnRef'] ?? null;

        if (! $pnRef) {
            Log::warning('No PnRef in payment result, cannot save card payment method');

            return false;
        }

        $tokenResponse = $customer->tokenizeFromTransaction((int) $pnRef);
        $token = $tokenResponse['CardToken']['Token'] ?? null;

        if (! $token) {
            Log::warning('Could not extract card token from transaction response', [
                'pnRef' => $pnRef,
            ]);

            return false;
        }

        $card = $command->cardDetails ?? [];
        $cardNumber = preg_replace('/\D/', '', $card['number'] ?? '');

        $paymentMethodService = app(CustomerPaymentMethodService::class);
        $savedMethod = $paymentMethodService->create($customer, [
            'mpc_token' => $token,
            'type' => CustomerPaymentMethod::TYPE_CARD,
            'nickname' => $command->paymentMethodNickname,
            'last_four' => substr($cardNumber, -4),
            'brand' => CustomerPaymentMethod::detectCardBrand($cardNumber),
            'exp_month' => (int) ($card['exp_month'] ?? 0),
            'exp_year' => (int) ($card['exp_year'] ?? 0),
        ], false);

        Log::info('Card payment method saved from transaction', ['pnRef' => $pnRef]);

        return (bool) $savedMethod;
    }

    /**
     * Save ACH payment method with local pseudo-token and encrypted bank details.
     */
    private function saveAchMethod(ProcessPaymentCommand $command, Customer $customer): bool
    {
        $ach = $command->achDetails ?? [];

        $tokenResult = $this->paymentService->tokenizeCheck([
            'routing_number' => $ach['routing_number'] ?? '',
            'account_number' => $ach['account_number'] ?? '',
            'account_type' => ucfirst($ach['account_type'] ?? 'checking'),
        ]);

        $token = $tokenResult['token'] ?? null;
        if (! $token) {
            return false;
        }

        $paymentMethodService = app(CustomerPaymentMethodService::class);
        $savedMethod = $paymentMethodService->create($customer, [
            'mpc_token' => $token,
            'type' => CustomerPaymentMethod::TYPE_ACH,
            'nickname' => $command->paymentMethodNickname,
            'last_four' => substr($ach['account_number'] ?? '', -4),
            'bank_name' => $ach['bank_name'] ?? null,
            'account_type' => $ach['account_type'] ?? 'checking',
            'is_business' => (bool) ($ach['is_business'] ?? false),
        ], false);

        // Store encrypted bank details for scheduled payments
        if ($savedMethod) {
            $savedMethod->setBankDetails(
                $ach['routing_number'] ?? '',
                $ach['account_number'] ?? ''
            );
        }

        Log::info('ACH payment method saved with local pseudo-token', [
            'last_four' => $tokenResult['last_four'] ?? '****',
        ]);

        return (bool) $savedMethod;
    }

    // =========================================================================
    // Step 6: PracticeCS write/defer
    // =========================================================================

    /**
     * Handle PracticeCS integration: write immediately for cards, defer for ACH.
     */
    private function handlePracticeCs(
        ProcessPaymentCommand $command,
        Payment $payment,
        array $chargeResult,
        PaymentResultBuilder $builder,
    ): void {
        if (! config('practicecs.payment_integration.enabled')) {
            $builder->practiceCsSkipped();

            return;
        }

        try {
            $payload = $this->buildPracticeCsPayload($command, $chargeResult);

            if ($command->isAch()) {
                // ACH: Defer write until settlement — store payload in payment metadata
                $metadata = $payment->metadata ?? [];
                $metadata['practicecs_data'] = $payload;
                $payment->update(['metadata' => $metadata]);
                $builder->practiceCsDeferred();

                Log::info('ACH payment: PracticeCS write deferred until settlement', [
                    'transaction_id' => $payment->transaction_id,
                    'payment_id' => $payment->id,
                ]);
            } else {
                // Card/check: Write immediately
                $result = $this->practiceWriter->writeDeferredPayment($payload);

                if (! $result['success']) {
                    $builder->practiceCsFailed($result['error'] ?? 'Unknown PracticeCS error');
                    Log::error('PracticeCS write failed', [
                        'transaction_id' => $payment->transaction_id,
                        'error' => $result['error'] ?? 'Unknown',
                    ]);

                    try {
                        AdminAlertService::notifyAll(new PracticeCsWriteFailed(
                            $payment->transaction_id,
                            $payment->client_id ?? 'unknown',
                            $command->baseAmount(),
                            $result['error'] ?? 'Unknown PracticeCS error',
                            'one_time'
                        ));
                    } catch (\Exception $notifyEx) {
                        Log::warning('Failed to send admin notification', ['error' => $notifyEx->getMessage()]);
                    }
                } else {
                    // Record that PracticeCS write succeeded
                    $metadata = $payment->metadata ?? [];
                    $metadata['practicecs_written_at'] = now()->toIso8601String();
                    $metadata['practicecs_ledger_entry_KEY'] = $result['ledger_entry_KEY'] ?? null;
                    $payment->update(['metadata' => $metadata]);

                    $builder->practiceCsWritten();
                    Log::info('Payment written to PracticeCS', [
                        'transaction_id' => $payment->transaction_id,
                        'ledger_entry_KEY' => $result['ledger_entry_KEY'] ?? null,
                        'has_group_distribution' => isset($payload['group_distribution']),
                    ]);

                    if (isset($result['warning'])) {
                        $builder->practiceCsFailed($result['warning']);
                    }
                }
            }
        } catch (\Exception $e) {
            $builder->practiceCsFailed($e->getMessage());
            Log::error('PracticeCS write exception', [
                'transaction_id' => $payment->transaction_id,
                'error' => $e->getMessage(),
            ]);

            try {
                AdminAlertService::notifyAll(new PracticeCsWriteFailed(
                    $payment->transaction_id,
                    $payment->client_id ?? 'unknown',
                    $command->baseAmount(),
                    $e->getMessage(),
                    'one_time'
                ));
            } catch (\Exception $notifyEx) {
                Log::warning('Failed to send admin notification', ['error' => $notifyEx->getMessage()]);
            }
        }
    }

    /**
     * Build the PracticeCS payload for this payment.
     *
     * Handles both admin (single-client, cents-based allocation) and public
     * (multi-client group distribution, dollar-based allocation) flows.
     *
     * @return array Structured payload with 'payment' key and optional 'group_distribution'
     */
    private function buildPracticeCsPayload(ProcessPaymentCommand $command, array $chargeResult): array
    {
        $methodType = match (true) {
            $command->isCard() => 'credit_card',
            $command->isAch() => 'ach',
            $command->chargeMethod === ProcessPaymentCommand::CHARGE_CHECK => 'check',
            default => 'cash',
        };

        $baseAmount = $command->baseAmount();
        $transactionId = $chargeResult['transaction_id'] ?? '';
        $primaryClientKey = $command->primaryClientKey();
        $primaryClientName = $command->clientName();

        // Build invoice applications
        if ($command->leaveUnapplied) {
            $invoicesToApply = [];
            $remainingAmount = 0;
        } elseif ($command->isAdmin()) {
            // Admin: cents-based allocation (precision-safe)
            [$invoicesToApply, $remainingAmount] = $this->allocateInvoicesCents($command, $baseAmount);
        } else {
            // Public: dollar-based allocation, separating primary vs other clients
            [$primaryInvoices, $otherClientsInvoices] = $this->separateInvoicesByClient(
                $command, $primaryClientKey
            );
            [$invoicesToApply, $remainingAmount] = $this->allocateInvoicesDollars($primaryInvoices, $baseAmount);
        }

        // Build comments
        $invoiceCount = $command->leaveUnapplied ? 0 : count($command->selectedInvoiceNumbers);
        $engagementCount = count($command->selectedEngagementKeys);

        if ($command->leaveUnapplied) {
            $comments = "Online payment - {$methodType} - Unapplied (credit balance)";
        } elseif ($engagementCount > 0 && $invoiceCount > 0) {
            $comments = "Online payment - {$methodType} - {$invoiceCount} invoice(s), {$engagementCount} fee request(s)";
        } elseif ($engagementCount > 0) {
            $comments = "Online payment - {$methodType} - {$engagementCount} fee request(s)";
        } else {
            $comments = "Online payment - {$methodType} - {$invoiceCount} invoice(s)";
        }

        // Build internal comments
        $internalComments = [
            'source' => $command->source,
            'transaction_id' => $transactionId,
            'payment_method' => $methodType,
            'fee' => $command->fee,
            'processed_at' => now()->toIso8601String(),
        ];

        if ($command->isAdmin()) {
            $internalComments['unapplied'] = $command->leaveUnapplied;
            $internalComments['fee_included_in_amount'] = $command->feeIncludedInAmount;
            $internalComments['engagement_keys'] = $command->selectedEngagementKeys;
        } else {
            $internalComments['is_payment_plan'] = false;
            $internalComments['has_group_distribution'] = ! empty($otherClientsInvoices ?? []);
        }

        $payload = [
            'payment' => [
                'client_KEY' => $primaryClientKey,
                'amount' => $baseAmount,
                'reference' => $transactionId,
                'comments' => $comments,
                'internal_comments' => json_encode($internalComments),
                'staff_KEY' => config('practicecs.payment_integration.staff_key'),
                'bank_account_KEY' => config('practicecs.payment_integration.bank_account_key'),
                'ledger_type_KEY' => config("practicecs.payment_integration.ledger_types.{$methodType}"),
                'subtype_KEY' => config("practicecs.payment_integration.payment_subtypes.{$methodType}"),
                'invoices' => $invoicesToApply,
            ],
        ];

        // Public flow: group distribution for multi-client payments
        if (! $command->isAdmin() && ($remainingAmount ?? 0) > 0.01 && ! empty($otherClientsInvoices ?? [])) {
            $payload['group_distribution'] = $this->buildGroupDistribution(
                $otherClientsInvoices,
                $remainingAmount,
                $primaryClientKey,
                $primaryClientName,
                $transactionId,
            );
        }

        return $payload;
    }

    /**
     * Admin: allocate payment to invoices using cents-based precision.
     *
     * @return array{0: array, 1: float} [invoicesToApply, remainingDollars]
     */
    private function allocateInvoicesCents(ProcessPaymentCommand $command, float $baseAmount): array
    {
        $invoicesToApply = [];
        $remainingCents = Money::toCents($baseAmount);

        // Sort by ledger_entry_KEY for deterministic ordering
        $details = $command->invoiceDetails;
        usort($details, fn ($a, $b) => ($a['ledger_entry_KEY'] ?? 0) <=> ($b['ledger_entry_KEY'] ?? 0));

        foreach ($details as $invoice) {
            if ($remainingCents <= 0) {
                break;
            }

            $invoiceOpenCents = Money::toCents((float) ($invoice['open_amount'] ?? 0));
            $applyCents = min($remainingCents, $invoiceOpenCents);

            $invoicesToApply[] = [
                'ledger_entry_KEY' => $invoice['ledger_entry_KEY'],
                'amount' => Money::toDollars($applyCents),
            ];
            $remainingCents -= $applyCents;
        }

        return [$invoicesToApply, Money::toDollars($remainingCents)];
    }

    /**
     * Public: separate invoices into primary client vs other clients.
     *
     * @return array{0: array, 1: array} [primaryInvoices, otherClientsInvoices grouped by client_KEY]
     */
    private function separateInvoicesByClient(ProcessPaymentCommand $command, int|string $primaryClientKey): array
    {
        $primaryInvoices = [];
        $otherClientsInvoices = [];

        foreach ($command->invoiceDetails as $invoice) {
            if (isset($invoice['is_placeholder']) && $invoice['is_placeholder']) {
                continue;
            }

            if (($invoice['client_KEY'] ?? null) == $primaryClientKey) {
                $primaryInvoices[] = $invoice;
            } else {
                $clientKey = $invoice['client_KEY'] ?? 0;
                if (! isset($otherClientsInvoices[$clientKey])) {
                    $otherClientsInvoices[$clientKey] = [
                        'client_KEY' => $clientKey,
                        'client_name' => $invoice['client_name'] ?? 'group member',
                        'client_id' => $invoice['client_id'] ?? null,
                        'invoices' => [],
                    ];
                }
                $otherClientsInvoices[$clientKey]['invoices'][] = $invoice;
            }
        }

        return [$primaryInvoices, $otherClientsInvoices];
    }

    /**
     * Public: allocate payment to invoices using dollar-based amounts.
     *
     * @return array{0: array, 1: float} [invoicesToApply, remainingDollars]
     */
    private function allocateInvoicesDollars(array $invoices, float $baseAmount): array
    {
        $invoicesToApply = [];
        $remaining = $baseAmount;

        foreach ($invoices as $invoice) {
            if ($remaining <= 0) {
                break;
            }

            $applyAmount = min($remaining, (float) ($invoice['open_amount'] ?? 0));
            $invoicesToApply[] = [
                'ledger_entry_KEY' => $invoice['ledger_entry_KEY'],
                'amount' => $applyAmount,
            ];
            $remaining -= $applyAmount;
        }

        return [$invoicesToApply, $remaining];
    }

    /**
     * Build group distribution data for multi-client payments (public flow only).
     *
     * Creates credit memos on other clients and debit memos on primary client.
     * Bug fix: uses `continue` instead of `break` when client allocation <= $0.01.
     */
    private function buildGroupDistribution(
        array $otherClientsInvoices,
        float $remainingAmount,
        int|string $primaryClientKey,
        string $primaryClientName,
        string $transactionId,
    ): array {
        $staffKey = config('practicecs.payment_integration.staff_key');
        $distributedAmount = 0;
        $groupDistribution = [];

        foreach ($otherClientsInvoices as $clientData) {
            $clientKey = $clientData['client_KEY'];
            $clientInvoices = $clientData['invoices'];

            $clientTotal = array_sum(array_column($clientInvoices, 'open_amount'));
            $clientAmount = min($remainingAmount - $distributedAmount, $clientTotal);

            // FIX: continue (not break) — skipping one client shouldn't abandon all remaining
            if ($clientAmount <= 0.01) {
                continue;
            }

            // Build invoice applications for this client
            $invoicesToApply = [];
            $applyRemaining = $clientAmount;

            foreach ($clientInvoices as $invoice) {
                if ($applyRemaining <= 0.01) {
                    break;
                }

                $applyAmount = min($applyRemaining, (float) ($invoice['open_amount'] ?? 0));
                $invoicesToApply[] = [
                    'ledger_entry_KEY' => $invoice['ledger_entry_KEY'],
                    'amount' => $applyAmount,
                ];
                $applyRemaining -= $applyAmount;
            }

            $groupDistribution[] = [
                'client_KEY' => $clientKey,
                'client_name' => $clientData['client_name'] ?? 'group member',
                'amount' => $clientAmount,
                'invoices' => $invoicesToApply,
                'credit_memo' => [
                    'client_KEY' => $clientKey,
                    'amount' => $clientAmount,
                    'comments' => 'Credit memo - payment from '.$primaryClientName,
                    'internal_comments_data' => [
                        'source' => 'tr-pay',
                        'transaction_id' => $transactionId,
                        'memo_type' => 'credit',
                        'from_client_KEY' => $primaryClientKey,
                        'from_client_name' => $primaryClientName,
                        'group_distribution' => true,
                    ],
                    'staff_KEY' => $staffKey,
                    'bank_account_KEY' => config('practicecs.payment_integration.bank_account_key'),
                    'ledger_type_KEY' => config('practicecs.payment_integration.memo_types.credit'),
                    'subtype_KEY' => config('practicecs.payment_integration.memo_subtypes.credit'),
                ],
                'debit_memo' => [
                    'client_KEY' => $primaryClientKey,
                    'amount' => $clientAmount,
                    'comments' => 'Debit memo - payment to '.($clientData['client_name'] ?? 'group member'),
                    'internal_comments_data' => [
                        'source' => 'tr-pay',
                        'transaction_id' => $transactionId,
                        'memo_type' => 'debit',
                        'to_client_KEY' => $clientKey,
                        'to_client_name' => $clientData['client_name'] ?? '',
                        'group_distribution' => true,
                    ],
                    'staff_KEY' => $staffKey,
                    'bank_account_KEY' => config('practicecs.payment_integration.bank_account_key'),
                    'ledger_type_KEY' => config('practicecs.payment_integration.memo_types.debit'),
                    'subtype_KEY' => config('practicecs.payment_integration.memo_subtypes.debit'),
                ],
            ];

            $distributedAmount += $clientAmount;
        }

        return $groupDistribution;
    }

    // =========================================================================
    // Step 7: Persist engagements
    // =========================================================================

    /**
     * Persist engagement acceptances. Handles both public (engagementsToPersist array)
     * and admin (selectedEngagements + pendingEngagements) formats.
     *
     * @return array Per-engagement outcomes [{key, success, error?, new_type_KEY?}, ...]
     */
    private function persistEngagements(ProcessPaymentCommand $command, string $transactionId): array
    {
        $results = [];
        $staffKey = config('practicecs.payment_integration.staff_key', 1552);

        if ($command->isAdmin()) {
            $results = $this->persistAdminEngagements($command, $transactionId, $staffKey);
        } else {
            $results = $this->persistPublicEngagements($command, $transactionId, $staffKey);
        }

        return $results;
    }

    /**
     * Admin: persist selected engagements from pendingEngagements array.
     */
    private function persistAdminEngagements(ProcessPaymentCommand $command, string $transactionId, int $staffKey): array
    {
        if (empty($command->selectedEngagementKeys)) {
            return [];
        }

        $results = [];

        foreach ($command->pendingEngagements as $engagement) {
            $engagementKey = (int) ($engagement['engagement_KEY'] ?? 0);

            if (! in_array($engagementKey, $command->selectedEngagementKeys, true)) {
                continue;
            }

            $result = $this->persistSingleEngagement(
                engagementKey: $engagementKey,
                clientKey: $engagement['client_KEY'] ?? null,
                engagementId: $engagement['engagement_type_id'] ?? null,
                engagementName: $engagement['engagement_name'] ?? null,
                groupName: $engagement['group_name'] ?? null,
                budgetAmount: $engagement['total_budget'] ?? null,
                signature: $command->acceptanceSignature,
                transactionId: $transactionId,
                staffKey: $staffKey,
                projectDescription: ! empty($engagement['projects'])
                    ? ($engagement['projects'][0]['notes'] ?? null)
                    : null,
            );
            $results[] = $result;
        }

        return $results;
    }

    /**
     * Public: persist engagements from the engagements array.
     */
    private function persistPublicEngagements(ProcessPaymentCommand $command, string $transactionId, int $staffKey): array
    {
        if (empty($command->engagements)) {
            return [];
        }

        $results = [];

        foreach ($command->engagements as $engagement) {
            $engagementKey = (int) ($engagement['project_engagement_key'] ?? 0);

            $result = $this->persistSingleEngagement(
                engagementKey: $engagementKey,
                clientKey: $engagement['client_key'] ?? null,
                engagementId: $engagement['engagement_id'] ?? null,
                engagementName: $engagement['project_name'] ?? null,
                groupName: $engagement['client_group_name'] ?? null,
                budgetAmount: $engagement['budget_amount'] ?? null,
                signature: $command->acceptanceSignature,
                transactionId: $transactionId,
                staffKey: $staffKey,
                projectDescription: $engagement['project_description'] ?? null,
            );
            $results[] = $result;
        }

        return $results;
    }

    /**
     * Persist a single engagement acceptance and update PracticeCS.
     *
     * @return array {key, success, error?, new_type_KEY?}
     */
    private function persistSingleEngagement(
        int $engagementKey,
        mixed $clientKey,
        mixed $engagementId,
        ?string $engagementName,
        ?string $groupName,
        mixed $budgetAmount,
        string $signature,
        string $transactionId,
        int $staffKey,
        ?string $projectDescription,
    ): array {
        try {
            $existing = ProjectAcceptance::where('project_engagement_key', $engagementKey)->first();

            if ($existing) {
                // Already exists — update with payment info if not already paid
                if (! $existing->paid) {
                    $existing->update([
                        'paid' => true,
                        'paid_at' => now(),
                        'payment_transaction_id' => $transactionId,
                    ]);
                }

                return ['key' => $engagementKey, 'success' => true, 'already_existed' => true];
            }

            // Create new acceptance record
            $acceptance = ProjectAcceptance::create([
                'project_engagement_key' => $engagementKey,
                'client_key' => $clientKey,
                'client_group_name' => $groupName,
                'engagement_id' => $engagementId,
                'project_name' => $engagementName,
                'budget_amount' => $budgetAmount,
                'accepted' => true,
                'accepted_at' => now(),
                'accepted_by_ip' => request()->ip(),
                'acceptance_signature' => $signature,
                'paid' => true,
                'paid_at' => now(),
                'payment_transaction_id' => $transactionId,
            ]);

            Log::info('Engagement acceptance persisted', [
                'engagement_KEY' => $engagementKey,
                'client_key' => $clientKey,
                'signature' => $signature,
            ]);

            // Update PracticeCS engagement type (year-aware)
            $pcResult = $this->engagementService->acceptEngagement(
                $engagementKey,
                $staffKey,
                $projectDescription
            );

            if ($pcResult['success']) {
                $acceptance->update([
                    'practicecs_updated' => true,
                    'new_engagement_type_key' => $pcResult['new_type_KEY'] ?? null,
                    'practicecs_updated_at' => now(),
                ]);

                Log::info('PracticeCS engagement type updated', [
                    'engagement_KEY' => $engagementKey,
                    'new_type_KEY' => $pcResult['new_type_KEY'] ?? null,
                ]);

                return [
                    'key' => $engagementKey,
                    'success' => true,
                    'new_type_KEY' => $pcResult['new_type_KEY'] ?? null,
                ];
            }

            // PracticeCS update failed — local record is saved
            $acceptance->update([
                'practicecs_updated' => false,
                'practicecs_error' => $pcResult['error'] ?? 'Unknown error',
            ]);

            Log::error('Failed to update PracticeCS engagement type', [
                'engagement_KEY' => $engagementKey,
                'error' => $pcResult['error'] ?? 'Unknown error',
            ]);

            try {
                AdminAlertService::notifyAll(new EngagementSyncFailed(
                    $engagementName ?? 'Unknown',
                    (string) $engagementKey,
                    $pcResult['error'] ?? 'Unknown error',
                    $transactionId
                ));
            } catch (\Exception $notifyEx) {
                Log::warning('Failed to send admin notification', ['error' => $notifyEx->getMessage()]);
            }

            return [
                'key' => $engagementKey,
                'success' => false,
                'error' => $pcResult['error'] ?? 'PracticeCS update failed',
            ];
        } catch (\Exception $e) {
            Log::error('Engagement persistence failed', [
                'engagement_KEY' => $engagementKey,
                'error' => $e->getMessage(),
            ]);

            try {
                AdminAlertService::notifyAll(new EngagementSyncFailed(
                    $engagementName ?? 'Unknown',
                    (string) $engagementKey,
                    $e->getMessage(),
                    $transactionId
                ));
            } catch (\Exception $notifyEx) {
                Log::warning('Failed to send admin notification', ['error' => $notifyEx->getMessage()]);
            }

            return [
                'key' => $engagementKey,
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    // =========================================================================
    // Step 8: Receipt email
    // =========================================================================

    /**
     * Try to send a payment receipt email. Log-and-continue on failure.
     *
     * @return bool Whether the email was sent
     */
    private function trySendReceipt(ProcessPaymentCommand $command, string $transactionId): bool
    {
        $clientEmail = $command->clientEmail();
        if (! $clientEmail) {
            Log::warning('Payment receipt email not sent - no client email on file', [
                'transaction_id' => $transactionId,
                'client_id' => $command->clientInfo['client_id'] ?? null,
            ]);

            return false;
        }

        try {
            $paymentData = [
                'amount' => $command->amount,
                'paymentMethod' => $command->paymentMethodLabel,
                'fee' => $command->fee,
                'invoices' => collect($command->invoiceDetails)->map(fn ($inv) => [
                    'invoice_number' => $inv['invoice_number'] ?? null,
                    'description' => $inv['description'] ?? null,
                    'amount' => $inv['open_amount'] ?? $inv['amount'] ?? null,
                ])->values()->toArray(),
            ];

            Mail::to($clientEmail)
                ->send(new PaymentReceipt($paymentData, $command->clientInfo, $transactionId));

            Log::info('Payment receipt email sent', [
                'transaction_id' => $transactionId,
                'client_id' => $command->clientInfo['client_id'] ?? null,
            ]);

            return true;
        } catch (\Exception $e) {
            Log::error('Failed to send payment receipt email', [
                'transaction_id' => $transactionId,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }
}
