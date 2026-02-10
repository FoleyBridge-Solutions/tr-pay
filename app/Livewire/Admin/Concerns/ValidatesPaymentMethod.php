<?php

// app/Livewire/Admin/Concerns/ValidatesPaymentMethod.php

namespace App\Livewire\Admin\Concerns;

/**
 * Trait for validating payment method fields in admin components.
 *
 * Provides consistent validation for card and ACH payment method inputs.
 * Components can customize behavior by overriding configuration methods.
 *
 * Required component properties:
 * - string $cardNumber, $cardExpiry, $cardCvv, $cardName
 * - string $routingNumber, $accountNumber, $accountName
 * - string|null $errorMessage
 *
 * The property name for payment method type defaults to '$paymentMethodType'
 * but can be overridden via paymentTypeProperty().
 */
trait ValidatesPaymentMethod
{
    /**
     * Get the property name that holds the payment method type.
     *
     * Override for components that use a different property (e.g., '$paymentType').
     */
    protected function paymentTypeProperty(): string
    {
        return 'paymentMethodType';
    }

    /**
     * Get the supported payment method types.
     *
     * Override to add 'none' or remove 'saved' as needed.
     * Supported values: 'none', 'saved', 'card', 'ach'
     */
    protected function supportedPaymentTypes(): array
    {
        return ['saved', 'card', 'ach'];
    }

    /**
     * Whether card validation requires CVV and cardholder name.
     *
     * Override to return false for recurring payments where
     * these fields are not collected.
     */
    protected function requireCardCvvAndName(): bool
    {
        return true;
    }

    /**
     * Whether ACH validation requires account holder name.
     *
     * Override to return false when name is not collected.
     */
    protected function requireAccountName(): bool
    {
        return true;
    }

    /**
     * Whether to check expiry on saved payment methods.
     *
     * Override to return true for recurring payments.
     */
    protected function checkSavedMethodExpiry(): bool
    {
        return false;
    }

    /**
     * Validate payment method fields.
     *
     * Sets $this->errorMessage on failure and returns false.
     * Returns true if validation passes.
     */
    protected function validatePaymentMethod(): bool
    {
        $typeProp = $this->paymentTypeProperty();
        $type = $this->{$typeProp};

        // Handle 'none' type (e.g., pending recurring payments)
        if ($type === 'none' && in_array('none', $this->supportedPaymentTypes())) {
            return true;
        }

        // Validate saved payment method
        if ($type === 'saved') {
            return $this->validateSavedPaymentMethod();
        }

        // Validate card
        if ($type === 'card') {
            return $this->validateCardFields();
        }

        // Validate ACH
        return $this->validateAchFields();
    }

    /**
     * Validate a saved payment method selection.
     */
    protected function validateSavedPaymentMethod(): bool
    {
        if (! $this->savedPaymentMethodId) {
            $this->errorMessage = 'Please select a saved payment method.';

            return false;
        }

        $method = $this->savedPaymentMethods->firstWhere('id', $this->savedPaymentMethodId);
        if (! $method) {
            $this->errorMessage = 'Selected payment method not found.';

            return false;
        }

        if ($this->checkSavedMethodExpiry() && $method->isExpired()) {
            $this->errorMessage = 'The selected card has expired. Please choose a different payment method.';

            return false;
        }

        return true;
    }

    /**
     * Validate card payment fields.
     */
    protected function validateCardFields(): bool
    {
        $cardNumber = preg_replace('/\D/', '', $this->cardNumber);
        if (strlen($cardNumber) < 13 || strlen($cardNumber) > 19) {
            $this->errorMessage = 'Please enter a valid card number.';

            return false;
        }

        if (empty($this->cardExpiry) || ! preg_match('/^\d{2}\/\d{2}$/', $this->cardExpiry)) {
            $this->errorMessage = 'Please enter a valid expiry date (MM/YY).';

            return false;
        }

        if ($this->requireCardCvvAndName()) {
            if (empty($this->cardCvv) || strlen($this->cardCvv) < 3) {
                $this->errorMessage = 'Please enter a valid CVV.';

                return false;
            }

            if (empty($this->cardName)) {
                $this->errorMessage = 'Please enter the name on card.';

                return false;
            }
        }

        return true;
    }

    /**
     * Validate ACH payment fields.
     */
    protected function validateAchFields(): bool
    {
        $routing = preg_replace('/\D/', '', $this->routingNumber);
        if (strlen($routing) !== 9) {
            $this->errorMessage = 'Please enter a valid 9-digit routing number.';

            return false;
        }

        $accountNumber = preg_replace('/\D/', '', $this->accountNumber);
        if (strlen($accountNumber) < 4) {
            $this->errorMessage = 'Please enter a valid account number.';

            return false;
        }

        if ($this->requireAccountName() && empty($this->accountName)) {
            $this->errorMessage = 'Please enter the account holder name.';

            return false;
        }

        return true;
    }
}
