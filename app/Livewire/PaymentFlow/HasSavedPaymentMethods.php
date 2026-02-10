<?php

// app/Livewire/PaymentFlow/HasSavedPaymentMethods.php

namespace App\Livewire\PaymentFlow;

use App\Models\CustomerPaymentMethod;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Trait for managing saved payment methods.
 *
 * This trait handles:
 * - Loading saved payment methods for a customer
 * - Filtering methods by type (card/ACH)
 * - Selecting saved methods for payment
 * - Setting default payment methods
 * - Deleting methods with linked plan reassignment
 * - Processing payments with saved methods
 */
trait HasSavedPaymentMethods
{
    // ==================== Saved Payment Methods Properties ====================
    // Note: These are declared in the main component, not here
    // $savedPaymentMethods, $selectedSavedMethodId, $savePaymentMethod,
    // $hasSavedMethods, $paymentMethodNickname, $methodToDelete,
    // $linkedPlansToReassign, $linkedRecurringToReassign, $reassignToMethodId,
    // $showReassignmentModal

    /**
     * Load saved payment methods for the current customer.
     */
    public function loadSavedPaymentMethods(): void
    {
        if (! $this->clientInfo || ! isset($this->clientInfo['client_id'])) {
            $this->savedPaymentMethods = collect();
            $this->hasSavedMethods = false;

            return;
        }

        try {
            $customer = $this->paymentService->getOrCreateCustomer($this->clientInfo);
            $this->savedPaymentMethods = $customer->customerPaymentMethods;
            $this->hasSavedMethods = $this->savedPaymentMethods->isNotEmpty();
        } catch (\Exception $e) {
            Log::error('Failed to load saved payment methods', [
                'client_id' => $this->clientInfo['client_id'],
                'error' => $e->getMessage(),
            ]);
            $this->savedPaymentMethods = collect();
            $this->hasSavedMethods = false;
        }
    }

    /**
     * Get saved payment methods filtered by current payment type.
     */
    public function getSavedMethodsForCurrentType(): Collection
    {
        $type = $this->paymentMethod === 'credit_card'
            ? CustomerPaymentMethod::TYPE_CARD
            : CustomerPaymentMethod::TYPE_ACH;

        return $this->savedPaymentMethods->filter(fn ($m) => $m->type === $type);
    }

    /**
     * Select a saved payment method for use.
     */
    public function selectSavedPaymentMethod(int $methodId): void
    {
        $method = $this->savedPaymentMethods->firstWhere('id', $methodId);

        if (! $method) {
            $this->addError('savedMethod', 'Selected payment method not found.');

            return;
        }

        // Check if method is expired
        if ($method->isExpired()) {
            $this->addError('savedMethod', 'This card has expired. Please use a different payment method.');

            return;
        }

        $this->selectedSavedMethodId = $methodId;

        // For payment plans, go to plan auth step
        if ($this->isPaymentPlan) {
            $this->goToStep(Steps::PAYMENT_PLAN_AUTH);
        } else {
            // Process payment immediately with saved method
            $this->processPaymentWithSavedMethod();
        }
    }

    /**
     * Proceed to enter new payment details (skip saved methods).
     */
    public function proceedWithNewPaymentMethod(): void
    {
        $this->selectedSavedMethodId = null;
        $this->goToStep(Steps::PAYMENT_DETAILS);
    }

    /**
     * Set a saved payment method as default.
     */
    public function setDefaultPaymentMethod(int $methodId): void
    {
        $method = $this->savedPaymentMethods->firstWhere('id', $methodId);

        if (! $method) {
            return;
        }

        try {
            $this->paymentMethodService->setAsDefault($method);
            $this->loadSavedPaymentMethods(); // Refresh list
        } catch (\Exception $e) {
            Log::error('Failed to set default payment method', [
                'method_id' => $methodId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Initiate deletion of a saved payment method.
     */
    public function deleteSavedPaymentMethod(int $methodId): void
    {
        $method = $this->savedPaymentMethods->firstWhere('id', $methodId);

        if (! $method) {
            return;
        }

        // Check if method is linked to active plans
        $canDeleteResult = $this->paymentMethodService->canDelete($method);

        if (! $canDeleteResult['can_delete']) {
            // Need to show reassignment modal
            $this->methodToDelete = $methodId;
            $this->linkedPlansToReassign = $canDeleteResult['payment_plans']->toArray();
            $this->linkedRecurringToReassign = $canDeleteResult['recurring_payments']->toArray();
            $this->showReassignmentModal = true;

            return;
        }

        // Can delete directly
        try {
            $this->paymentMethodService->delete($method);
            $this->loadSavedPaymentMethods(); // Refresh list

            // If no more saved methods, redirect to payment details
            if (! $this->hasSavedMethods || $this->getSavedMethodsForCurrentType()->isEmpty()) {
                $this->goToStep(Steps::PAYMENT_DETAILS);
            }
        } catch (\Exception $e) {
            $this->addError('savedMethod', 'Failed to delete payment method: '.$e->getMessage());
        }
    }

    /**
     * Close the reassignment modal.
     */
    public function closeReassignmentModal(): void
    {
        $this->showReassignmentModal = false;
        $this->methodToDelete = null;
        $this->linkedPlansToReassign = [];
        $this->linkedRecurringToReassign = [];
        $this->reassignToMethodId = null;
    }

    /**
     * Reassign linked plans to another method and delete the old one.
     */
    public function reassignAndDelete(): void
    {
        if (! $this->methodToDelete || ! $this->reassignToMethodId) {
            $this->addError('reassignment', 'Please select a payment method to reassign to.');

            return;
        }

        $oldMethod = $this->savedPaymentMethods->firstWhere('id', $this->methodToDelete);
        $newMethod = $this->savedPaymentMethods->firstWhere('id', $this->reassignToMethodId);

        if (! $oldMethod || ! $newMethod) {
            $this->addError('reassignment', 'Payment method not found.');

            return;
        }

        try {
            // Reassign all linked plans to new method
            $this->paymentMethodService->reassignLinkedPlans($oldMethod, $newMethod);

            // Now delete the old method
            $this->paymentMethodService->delete($oldMethod);

            // Close modal and refresh
            $this->closeReassignmentModal();
            $this->loadSavedPaymentMethods();

        } catch (\Exception $e) {
            $this->addError('reassignment', 'Failed to reassign and delete: '.$e->getMessage());
        }
    }

    /**
     * Find the currently selected saved payment method.
     */
    protected function findSelectedSavedMethod(): ?\App\Models\CustomerPaymentMethod
    {
        if (! $this->selectedSavedMethodId) {
            return null;
        }

        return $this->savedPaymentMethods->firstWhere('id', $this->selectedSavedMethodId);
    }

    /**
     * Process payment using a saved payment method.
     */
    protected function processPaymentWithSavedMethod(): void
    {
        if (! $this->selectedSavedMethodId) {
            $this->addError('payment', 'No payment method selected.');

            return;
        }

        $method = $this->findSelectedSavedMethod();

        if (! $method) {
            $this->addError('payment', 'Selected payment method not found.');

            return;
        }

        try {
            $customer = $this->paymentService->getOrCreateCustomer($this->clientInfo);

            // Calculate total amount
            $totalAmount = $this->paymentAmount;
            if ($this->paymentMethod === 'credit_card') {
                $totalAmount += $this->creditCardFee;
            }

            $description = "Payment for {$this->clientInfo['client_name']} - ".count($this->selectedInvoices).' invoice(s)';

            // Charge using saved method
            $paymentResult = $this->paymentService->chargeWithSavedMethod(
                $customer,
                $method,
                $totalAmount,
                [
                    'description' => $description,
                ]
            );

            if (! $paymentResult['success']) {
                $this->addError('payment', $paymentResult['error'] ?? 'Payment failed.');

                return;
            }

            $this->transactionId = $paymentResult['transaction_id'];

            // Log successful payment
            Log::info('Payment processed successfully', [
                'transaction_id' => $this->transactionId,
                'client_id' => $this->clientInfo['client_id'],
                'amount' => $this->paymentAmount,
                'payment_method' => $this->paymentMethod,
                'saved_method_id' => $this->selectedSavedMethodId,
            ]);

            // Record the payment with description and vendor metadata
            $this->paymentService->recordPayment([
                'amount' => $this->paymentAmount,
                'fee' => $this->creditCardFee,
                'paymentMethod' => $this->paymentMethod,
                'lastFour' => $method->last_four,
                'invoices' => $this->selectedInvoices,
                'description' => $description,
            ], $this->clientInfo, $this->transactionId, [
                'payment_vendor' => $paymentResult['payment_vendor'] ?? null,
                'vendor_transaction_id' => $paymentResult['transaction_id'] ?? null,
            ]);

            // Write to PracticeCS if enabled
            if (config('practicecs.payment_integration.enabled')) {
                try {
                    $this->writeToPracticeCs($paymentResult);
                } catch (\Exception $e) {
                    Log::error('Failed to write payment to PracticeCS', [
                        'transaction_id' => $this->transactionId,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            // Persist accepted engagements
            $this->persistAcceptedEngagements();

            // Mark as completed
            $this->paymentProcessed = true;
            $this->goToStep(Steps::CONFIRMATION);

        } catch (\Exception $e) {
            $this->addError('payment', 'Payment processing failed: '.$e->getMessage());
            Log::error('Payment with saved method failed', [
                'method_id' => $this->selectedSavedMethodId,
                'error' => $e->getMessage(),
            ]);

            return;
        }

        // Send payment receipt email
        try {
            $clientEmail = $this->clientInfo['email'] ?? null;

            if ($clientEmail) {
                $paymentData = [
                    'amount' => $this->paymentAmount,
                    'paymentMethod' => $this->paymentMethod,
                    'fee' => $this->creditCardFee,
                    'invoices' => collect($this->selectedInvoices)->map(function ($invoiceNumber) {
                        $invoice = collect($this->openInvoices)->firstWhere('invoice_number', $invoiceNumber);

                        return $invoice ? [
                            'invoice_number' => $invoice['invoice_number'],
                            'description' => $invoice['description'],
                            'amount' => $invoice['open_amount'],
                        ] : null;
                    })->filter()->values()->toArray(),
                ];

                \Illuminate\Support\Facades\Mail::to($clientEmail)
                    ->send(new \App\Mail\PaymentReceipt($paymentData, $this->clientInfo, $this->transactionId));

                Log::info('Payment receipt email sent', [
                    'transaction_id' => $this->transactionId,
                    'client_id' => $this->clientInfo['client_id'],
                    'amount' => $this->paymentAmount,
                ]);
            } else {
                Log::warning('Payment receipt email not sent - no client email on file', [
                    'transaction_id' => $this->transactionId,
                    'client_id' => $this->clientInfo['client_id'],
                ]);
            }
        } catch (\Exception $e) {
            // Log email failure but don't block payment completion
            Log::error('Failed to send payment receipt email', [
                'transaction_id' => $this->transactionId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Save the current payment method after successful payment.
     */
    protected function saveCurrentPaymentMethod(string $token, string $type): void
    {
        if (! $this->savePaymentMethod) {
            return;
        }

        try {
            $customer = $this->paymentService->getOrCreateCustomer($this->clientInfo);

            $data = [
                'mpc_token' => $token,
                'type' => $type,
                'nickname' => $this->paymentMethodNickname,
            ];

            if ($type === CustomerPaymentMethod::TYPE_CARD) {
                $data['last_four'] = substr(str_replace(' ', '', $this->cardNumber), -4);
                $data['brand'] = CustomerPaymentMethod::detectCardBrand($this->cardNumber);
                $data['exp_month'] = (int) substr($this->cardExpiry, 0, 2);
                $data['exp_year'] = (int) ('20'.substr($this->cardExpiry, 3, 2));
            } else {
                $data['last_four'] = substr($this->accountNumber, -4);
                $data['bank_name'] = $this->bankName;
                $data['account_type'] = $this->bankAccountType;
                $data['is_business'] = (bool) $this->isBusiness;
            }

            $savedMethod = $this->paymentMethodService->create($customer, $data, false);

            // Store encrypted bank details for ACH methods (enables scheduled payments)
            if ($type === CustomerPaymentMethod::TYPE_ACH) {
                $savedMethod->setBankDetails($this->routingNumber, $this->accountNumber);
            }

            Log::info('Payment method saved after successful payment', [
                'customer_id' => $customer->id,
                'type' => $type,
            ]);
        } catch (\Exception $e) {
            // Log but don't fail - payment already succeeded
            Log::error('Failed to save payment method after payment', [
                'error' => $e->getMessage(),
            ]);
        }
    }
}
