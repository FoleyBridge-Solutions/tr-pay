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

            $invoiceDetails = collect($this->selectedInvoices)->map(function ($invoiceNumber) {
                $invoice = collect($this->openInvoices)->firstWhere('invoice_number', $invoiceNumber);

                return $invoice ? [
                    'invoice_number' => $invoice['invoice_number'],
                    'description' => $invoice['description'],
                    'amount' => $invoice['open_amount'],
                    'ledger_entry_KEY' => $invoice['ledger_entry_KEY'] ?? null,
                    'open_amount' => $invoice['open_amount'],
                    'client_KEY' => $invoice['client_KEY'] ?? null,
                    'client_name' => $invoice['client_name'] ?? null,
                    'client_id' => $invoice['client_id'] ?? null,
                ] : null;
            })->filter()->values()->toArray();

            $command = \App\Services\PaymentOrchestrator\ProcessPaymentCommand::savedMethodPayment(
                customer: $customer,
                amount: $this->paymentAmount,
                fee: $this->creditCardFee,
                clientInfo: $this->clientInfo,
                selectedInvoiceNumbers: $this->selectedInvoices,
                invoiceDetails: $invoiceDetails,
                openInvoices: $this->openInvoices,
                savedMethod: $method,
                engagements: $this->engagementsToPersist ?? [],
                sendReceipt: true,
            );

            $orchestrator = app(\App\Services\PaymentOrchestrator::class);
            $result = $orchestrator->processPayment($command);

            if (! $result->success) {
                $this->addError('payment', $result->error ?? 'Payment failed.');

                return;
            }

            $this->transactionId = $result->transactionId;
            $this->paymentProcessed = true;
            $this->goToStep(Steps::CONFIRMATION);

        } catch (\Exception $e) {
            $this->addError('payment', 'Payment processing failed: '.$e->getMessage());
            Log::error('Payment with saved method failed', [
                'method_id' => $this->selectedSavedMethodId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    // saveCurrentPaymentMethod() has been moved to PaymentOrchestrator.
    // Payment method saving is now handled by PaymentOrchestrator::trySavePaymentMethod()
    // for one-time payments (both public card and ACH flows).
}
