<?php

// app/Livewire/PaymentFlow/HasPaymentPlans.php

namespace App\Livewire\PaymentFlow;

/**
 * Trait for payment plan functionality.
 *
 * This trait handles:
 * - Plan duration selection (3, 6, or 9 months)
 * - Payment plan fee calculation
 * - Down payment and schedule calculation
 * - Plan confirmation and authorization
 */
trait HasPaymentPlans
{
    // ==================== Payment Plan Properties ====================
    // Note: These are declared in the main component, not here
    // $isPaymentPlan, $planDuration, $paymentSchedule, $paymentPlanFee,
    // $availablePlans, $downPayment, $monthlyPayment, $agreeToTerms

    /**
     * Select a payment plan duration (3, 6, or 9 months)
     */
    public function selectPlanDuration(int $months): void
    {
        if (! $this->planCalculator->isValidDuration($months)) {
            $this->addError('planDuration', 'Please select a valid plan duration.');

            return;
        }

        $this->planDuration = $months;
        $this->calculatePaymentPlanFee();
        $this->calculateDownPaymentAndSchedule();
    }

    /**
     * Calculate payment plan fee (simple flat fee based on duration)
     */
    public function calculatePaymentPlanFee(): void
    {
        if (! $this->isPaymentPlan) {
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
     * Calculate down payment (30%) and payment schedule
     */
    public function calculateDownPaymentAndSchedule(): void
    {
        if (! $this->isPaymentPlan) {
            $this->downPayment = 0;
            $this->monthlyPayment = 0;
            $this->paymentSchedule = [];

            return;
        }

        // Get plan details with down payment calculation
        $planDetails = $this->planCalculator->calculatePlanDetails(
            $this->paymentAmount,
            $this->planDuration
        );

        // Handle invalid duration (empty plan details)
        if (empty($planDetails)) {
            $this->downPayment = 0;
            $this->monthlyPayment = 0;
            $this->paymentSchedule = [];

            return;
        }

        $this->downPayment = $planDetails['down_payment'];
        $this->monthlyPayment = $planDetails['monthly_payment'];

        // Calculate schedule with down payment
        $this->paymentSchedule = $this->planCalculator->calculateSchedule(
            $this->paymentAmount,
            $this->planDuration,
            null,
            false // Not split (public flow)
        );
    }

    /**
     * Confirm payment plan and proceed to payment details
     */
    public function confirmPaymentPlan(): void
    {
        // Validate plan duration is valid (3, 6, or 9 months)
        if (! $this->planCalculator->isValidDuration($this->planDuration)) {
            $this->addError('planDuration', 'Please select a valid payment plan (3, 6, or 9 months).');

            return;
        }

        $this->calculateDownPaymentAndSchedule();
        $this->goToStep(Steps::PAYMENT_PLAN_AUTH);
    }

    /**
     * Calculate payment schedule (kept for backwards compatibility)
     */
    public function calculatePaymentSchedule(): void
    {
        $this->calculateDownPaymentAndSchedule();
    }

    /**
     * Update when plan duration changes
     */
    public function updatedPlanDuration(): void
    {
        $this->calculatePaymentPlanFee();
        $this->calculatePaymentSchedule();
    }

    /**
     * Step 5: Authorize payment plan
     */
    public function authorizePaymentPlan(): void
    {
        // Validate terms agreement
        $this->validate([
            'agreeToTerms' => 'accepted',
        ], [
            'agreeToTerms.accepted' => 'You must agree to the terms and conditions to continue.',
        ]);

        // Skip card/ACH field validation when using a saved payment method
        if (! $this->selectedSavedMethodId) {
            if ($this->paymentMethod === 'credit_card') {
                $this->validate([
                    'cardNumber' => ['required', 'string', 'regex:/^[0-9\s]{13,19}$/'],
                    'cardExpiry' => ['required', 'string', 'regex:/^(0[1-9]|1[0-2])\/\d{2}$/'],
                    'cardCvv' => ['required', 'string', 'regex:/^\d{3,4}$/'],
                ], [
                    'cardNumber.required' => 'Credit card number is required',
                    'cardNumber.regex' => 'Please enter a valid credit card number',
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
                    'achAuthorization' => ['accepted'],
                ], [
                    'bankName.required' => 'Bank name is required',
                    'accountNumber.required' => 'Account number is required',
                    'accountNumber.regex' => 'Please enter a valid account number (8-17 digits)',
                    'routingNumber.required' => 'Routing number is required',
                    'routingNumber.regex' => 'Please enter a valid routing number (9 digits)',
                    'achAuthorization.accepted' => 'You must authorize the recurring ACH debits to continue',
                ]);
            }
        }

        // For payment plans, create setup intent for saving payment method (MiPaymentChoice)
        if ($this->isPaymentPlan) {
            // When using a saved method, populate details from the saved method
            if ($this->selectedSavedMethodId) {
                $method = $this->savedPaymentMethods->firstWhere('id', $this->selectedSavedMethodId);

                if (! $method) {
                    $this->addError('payment_method', 'Selected payment method not found.');

                    return;
                }

                $this->paymentMethodDetails = [
                    'type' => $method->type === \App\Models\CustomerPaymentMethod::TYPE_CARD ? 'card_token' : 'ach_token',
                    'saved_method_id' => $method->id,
                    'mpc_token' => $method->mpc_token,
                    'last_four' => $method->last_four,
                    'ready_for_tokenization' => false, // Already tokenized
                ];
            } else {
                $setupIntentResult = $this->paymentService->createSetupIntent($this->clientInfo);

                if (! $setupIntentResult['success']) {
                    $this->addError('payment_method', 'Failed to initialize payment plan setup: '.$setupIntentResult['error']);

                    return;
                }

                $this->paymentMethodDetails = [
                    'type' => $this->paymentMethod === 'credit_card' ? 'card_token' : 'ach_token',
                    'customer_id' => $setupIntentResult['customer_id'],
                    'ready_for_tokenization' => true,
                ];
            }
        } else {
            // Store payment method details (will be tokenized with MiPaymentChoice)
            $this->paymentMethodDetails = [
                'method' => $this->paymentMethod,
                'card_number' => $this->paymentMethod === 'credit_card' ? '**** **** **** '.substr(str_replace(' ', '', $this->cardNumber), -4) : null,
                'card_expiry' => $this->cardExpiry,
                'bank_name' => $this->bankName,
                'account_number' => $this->paymentMethod === 'ach' ? '****'.substr($this->accountNumber, -4) : null,
                'routing_number' => $this->paymentMethod === 'ach' ? '****'.substr($this->routingNumber, -4) : null,
            ];
        }

        // Proceed to confirmation
        $this->goToStep(Steps::CONFIRMATION);
        $this->transactionId = 'mpc_plan_'.bin2hex(random_bytes(16));
    }
}
