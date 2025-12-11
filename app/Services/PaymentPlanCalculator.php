<?php

namespace App\Services;

use Carbon\Carbon;

/**
 * Simple payment plan calculator.
 * 
 * Supports only 3 plan options:
 * - 3 months: $150 fee
 * - 6 months: $300 fee
 * - 9 months: $450 fee
 * 
 * All plans are monthly payments with no down payment required.
 */
class PaymentPlanCalculator
{
    /**
     * Available plan durations and their fees.
     */
    public const PLAN_OPTIONS = [
        3 => 150.00,
        6 => 300.00,
        9 => 450.00,
    ];

    /**
     * Get available plan options with calculated payment amounts.
     *
     * @param float $invoiceAmount The invoice total to be financed
     * @return array Array of plan options with duration, fee, and monthly payment
     */
    public function getAvailablePlans(float $invoiceAmount): array
    {
        $plans = [];

        foreach (self::PLAN_OPTIONS as $months => $fee) {
            $totalAmount = $invoiceAmount + $fee;
            $monthlyPayment = round($totalAmount / $months, 2);

            $plans[] = [
                'months' => $months,
                'fee' => $fee,
                'total_amount' => round($totalAmount, 2),
                'monthly_payment' => $monthlyPayment,
            ];
        }

        return $plans;
    }

    /**
     * Get the fee for a specific plan duration.
     *
     * @param int $months Plan duration in months (3, 6, or 9)
     * @return float The fee amount, or 0 if invalid duration
     */
    public function getFee(int $months): float
    {
        return self::PLAN_OPTIONS[$months] ?? 0.00;
    }

    /**
     * Check if a plan duration is valid.
     *
     * @param int $months Plan duration in months
     * @return bool True if valid duration
     */
    public function isValidDuration(int $months): bool
    {
        return array_key_exists($months, self::PLAN_OPTIONS);
    }

    /**
     * Calculate payment plan fee.
     * 
     * Simplified version - just returns the flat fee for the duration.
     *
     * @param float $paymentAmount Total invoice amount (unused in new implementation)
     * @param float $downPayment Down payment amount (unused - no longer supported)
     * @param int $duration Number of months (3, 6, or 9)
     * @param string $frequency Payment frequency (unused - always monthly now)
     * @return array Fee details for backwards compatibility
     */
    public function calculateFee(float $paymentAmount, float $downPayment, int $duration, string $frequency): array
    {
        $fee = $this->getFee($duration);

        return [
            'fee_amount' => $fee,
            'months' => $duration,
            'duration_multiplier' => 1.0,
            'down_payment_multiplier' => 1.0,
            'down_payment_percent' => 0,
        ];
    }

    /**
     * Calculate the payment schedule.
     *
     * @param float $totalAmountToFinance Total amount including fees
     * @param float $downPayment Down payment amount (unused - always 0)
     * @param int $duration Number of monthly payments (3, 6, or 9)
     * @param string $frequency Payment frequency (unused - always monthly)
     * @param string|null $startDate Start date for first payment
     * @param array $customInstallments Custom amounts (unused - always equal payments)
     * @return array Array of scheduled payments
     */
    public function calculateSchedule(
        float $totalAmountToFinance,
        float $downPayment,
        int $duration,
        string $frequency,
        ?string $startDate = null,
        array $customInstallments = []
    ): array {
        if ($totalAmountToFinance <= 0 || !$this->isValidDuration($duration)) {
            return [];
        }

        $schedule = [];
        $monthlyPayment = round($totalAmountToFinance / $duration, 2);
        $lastPayment = $totalAmountToFinance - ($monthlyPayment * ($duration - 1));
        
        $start = $startDate ? Carbon::parse($startDate) : now();

        for ($i = 1; $i <= $duration; $i++) {
            $dueDate = $start->copy()->addMonths($i);
            $amount = ($i === $duration) ? $lastPayment : $monthlyPayment;

            $schedule[] = [
                'payment_number' => $i,
                'due_date' => $dueDate->format('M d, Y'),
                'amount' => round($amount, 2),
                'label' => "Payment $i of $duration",
            ];
        }

        return $schedule;
    }

    /**
     * Get valid plan durations.
     *
     * @return array Array of valid month durations
     */
    public function getValidDurations(): array
    {
        return array_keys(self::PLAN_OPTIONS);
    }

    // -------------------------------------------------------------------------
    // Legacy methods kept for backwards compatibility
    // -------------------------------------------------------------------------

    /**
     * @deprecated No longer used - kept for backwards compatibility
     */
    public function calculateMonthsFromDuration(int $duration, string $frequency): int
    {
        return $duration; // Now duration IS months
    }

    /**
     * @deprecated No longer used - kept for backwards compatibility
     */
    public function getDurationMultiplier(int $months): float
    {
        return 1.0;
    }

    /**
     * @deprecated No longer used - kept for backwards compatibility
     */
    public function getMaxInstallments(string $frequency): int
    {
        return 9; // Max is now 9 months
    }

    /**
     * @deprecated No longer used - kept for backwards compatibility
     */
    public function getDaysPerInstallment(string $frequency): int
    {
        return 30; // Always monthly
    }

    /**
     * @deprecated No longer used - kept for backwards compatibility
     */
    public function getEqualInstallments(float $totalAmount, int $count): array
    {
        if ($count <= 0) return [];
        
        $equalAmount = round($totalAmount / $count, 2);
        $amounts = array_fill(0, $count, $equalAmount);
        $amounts[$count - 1] = $totalAmount - ($equalAmount * ($count - 1));
        
        return $amounts;
    }
}
