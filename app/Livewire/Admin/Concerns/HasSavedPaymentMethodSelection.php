<?php

// app/Livewire/Admin/Concerns/HasSavedPaymentMethodSelection.php

namespace App\Livewire\Admin\Concerns;

use App\Models\Customer;
use App\Models\CustomerPaymentMethod;

/**
 * Trait for loading and selecting saved payment methods in admin components.
 *
 * Provides saved payment method lookup, selection, and last-four extraction
 * for components that allow admins to choose from a client's stored methods.
 *
 * Required component properties:
 * - ?int $savedPaymentMethodId
 * - Collection $savedPaymentMethods
 * - ?array $selectedClient (must contain 'client_id')
 * - string $paymentMethodType ('none', 'saved', 'card', or 'ach')
 * - string $cardNumber
 * - string $accountNumber
 */
trait HasSavedPaymentMethodSelection
{
    /**
     * Get the selected saved payment method.
     *
     * @return CustomerPaymentMethod|null The selected method, or null if none selected.
     */
    public function getSelectedSavedMethod(): ?CustomerPaymentMethod
    {
        if (! $this->savedPaymentMethodId) {
            return null;
        }

        return $this->savedPaymentMethods->firstWhere('id', $this->savedPaymentMethodId);
    }

    /**
     * Load saved payment methods for the selected client.
     *
     * Populates $savedPaymentMethods from the customer's stored methods.
     * Resets to an empty collection if no client is selected or no customer record exists.
     */
    protected function loadSavedPaymentMethods(): void
    {
        if (! $this->selectedClient) {
            $this->savedPaymentMethods = collect();

            return;
        }

        $customer = Customer::where('client_id', $this->selectedClient['client_id'])->first();

        if ($customer) {
            $this->savedPaymentMethods = $customer->customerPaymentMethods;
        } else {
            $this->savedPaymentMethods = collect();
        }
    }

    /**
     * Get last 4 digits of the current payment method.
     *
     * Handles all payment method types:
     * - 'none': returns null
     * - 'saved': returns the saved method's last_four or '****' as fallback
     * - 'card': extracts last 4 digits from $cardNumber
     * - 'ach': extracts last 4 digits from $accountNumber
     *
     * @return string|null The last four digits, or null for 'none' type.
     */
    protected function getLastFour(): ?string
    {
        if ($this->paymentMethodType === 'none') {
            return null;
        }

        if ($this->paymentMethodType === 'saved') {
            $method = $this->getSelectedSavedMethod();

            return $method?->last_four ?? '****';
        }

        if ($this->paymentMethodType === 'card') {
            $number = preg_replace('/\D/', '', $this->cardNumber);

            return substr($number, -4);
        }

        return substr($this->accountNumber, -4);
    }
}
