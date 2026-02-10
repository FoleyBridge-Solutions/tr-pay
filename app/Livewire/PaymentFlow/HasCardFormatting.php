<?php

// app/Livewire/PaymentFlow/HasCardFormatting.php

namespace App\Livewire\PaymentFlow;

/**
 * Trait for payment card/bank input formatting.
 *
 * This trait handles:
 * - Card number formatting (spaces every 4 digits)
 * - Expiry date formatting (MM/YY)
 * - CVV formatting (numbers only, max 4 digits)
 * - Credit card fee calculation on payment method change
 */
trait HasCardFormatting
{
    // ==================== Card/Bank Properties ====================
    // Note: These are declared in the main component, not here
    // $cardNumber, $cardExpiry, $cardCvv, $bankName, $accountNumber,
    // $routingNumber, $bankAccountType, $isBusiness, $achAuthorization

    /**
     * Format card number as user types
     */
    public function updatedCardNumber(): void
    {
        // Remove all non-digits
        $number = preg_replace('/\D/', '', $this->cardNumber);

        // Add spaces every 4 digits
        $formatted = '';
        for ($i = 0; $i < strlen($number); $i++) {
            if ($i > 0 && $i % 4 === 0) {
                $formatted .= ' ';
            }
            $formatted .= $number[$i];
        }

        $this->cardNumber = $formatted;
    }

    /**
     * Format expiry date as user types
     */
    public function updatedCardExpiry(): void
    {
        // Remove all non-digits
        $expiry = preg_replace('/\D/', '', $this->cardExpiry);

        // Add slash after month
        if (strlen($expiry) >= 2) {
            $expiry = substr($expiry, 0, 2).'/'.substr($expiry, 2, 2);
        }

        $this->cardExpiry = $expiry;
    }

    /**
     * Format CVV as user types (numbers only)
     */
    public function updatedCardCvv(): void
    {
        // Remove all non-digits and limit to 4 characters
        $cvv = preg_replace('/\D/', '', $this->cardCvv);
        $this->cardCvv = substr($cvv, 0, 4);
    }

    /**
     * Handle payment method changes and calculate fees
     */
    public function updatedPaymentMethod(string $value): void
    {
        if ($value === 'credit_card') {
            // Calculate fee on total amount (Invoice + Plan Fee)
            $amountToTax = $this->paymentAmount + $this->paymentPlanFee;
            $this->creditCardFee = round($amountToTax * config('payment-fees.credit_card_rate'), 2);
        } else {
            $this->creditCardFee = 0;
        }

        // Recalculate schedule if we are in a plan
        if ($this->isPaymentPlan) {
            $this->calculatePaymentSchedule();
        }
    }
}
