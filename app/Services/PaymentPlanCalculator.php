<?php

namespace App\Services;

use Carbon\Carbon;

/**
 * Payment plan calculator with 30% down payment requirement.
 *
 * Supports 3 plan options:
 * - 3 months: $150 fee
 * - 6 months: $300 fee
 * - 9 months: $450 fee
 *
 * All plans require a 30% down payment of the total amount (invoice + fee).
 * The remaining 70% is split into equal monthly payments.
 *
 * Admin users can split the down payment into two payments within the same month.
 */
class PaymentPlanCalculator
{
    /**
     * Down payment percentage (30%).
     */
    public const DOWN_PAYMENT_PERCENT = 0.30;

    /**
     * Available plan durations and their fees.
     */
    public const PLAN_OPTIONS = [
        3 => 150.00,
        6 => 300.00,
        9 => 450.00,
    ];

    /**
     * Get available plan options with calculated payment amounts including down payment.
     *
     * @param  float  $invoiceAmount  The invoice total to be financed
     * @return array Array of plan options with duration, fee, down payment, and monthly payment
     */
    public function getAvailablePlans(float $invoiceAmount): array
    {
        $plans = [];

        foreach (self::PLAN_OPTIONS as $months => $fee) {
            $totalAmount = $invoiceAmount + $fee;
            $downPayment = $this->calculateDownPayment($totalAmount);
            $remainingBalance = $totalAmount - $downPayment;
            $monthlyPayment = round($remainingBalance / $months, 2);

            $plans[] = [
                'months' => $months,
                'fee' => $fee,
                'total_amount' => round($totalAmount, 2),
                'down_payment' => $downPayment,
                'down_payment_percent' => self::DOWN_PAYMENT_PERCENT * 100,
                'remaining_balance' => round($remainingBalance, 2),
                'monthly_payment' => $monthlyPayment,
            ];
        }

        return $plans;
    }

    /**
     * Calculate the 30% down payment amount.
     *
     * @param  float  $totalAmount  Total amount (invoice + fee)
     * @return float The down payment amount
     */
    public function calculateDownPayment(float $totalAmount): float
    {
        return round($totalAmount * self::DOWN_PAYMENT_PERCENT, 2);
    }

    /**
     * Calculate split down payment amounts for admin use.
     *
     * Splits the 30% down payment into two equal payments.
     * If created after the 20th of the month, second payment is 15 days later.
     * Otherwise, second payment is at the end of the month.
     *
     * @param  float  $totalAmount  Total amount (invoice + fee)
     * @param  Carbon|null  $startDate  Start date (defaults to now)
     * @return array Array with first and second payment details
     */
    public function calculateSplitDownPayment(float $totalAmount, ?Carbon $startDate = null): array
    {
        $downPayment = $this->calculateDownPayment($totalAmount);
        $halfPayment = round($downPayment / 2, 2);
        // Ensure the two halves equal the total (handle rounding)
        $firstPayment = $halfPayment;
        $secondPayment = $downPayment - $firstPayment;

        $start = $startDate ?? now();
        $dayOfMonth = $start->day;

        // Calculate second payment date
        if ($dayOfMonth > 20) {
            // After the 20th: second payment is 15 days later
            $secondPaymentDate = $start->copy()->addDays(15);
        } else {
            // Before or on the 20th: second payment at end of month (or 15 days, whichever is sooner in the same month)
            $endOfMonth = $start->copy()->endOfMonth();
            $fifteenDaysLater = $start->copy()->addDays(15);

            // Use whichever keeps it in the same month, preferring 15 days
            if ($fifteenDaysLater->month === $start->month) {
                $secondPaymentDate = $fifteenDaysLater;
            } else {
                $secondPaymentDate = $endOfMonth;
            }
        }

        return [
            'total_down_payment' => $downPayment,
            'first_payment' => [
                'amount' => $firstPayment,
                'date' => $start->copy(),
                'date_formatted' => $start->format('M j, Y'),
            ],
            'second_payment' => [
                'amount' => $secondPayment,
                'date' => $secondPaymentDate,
                'date_formatted' => $secondPaymentDate->format('M j, Y'),
            ],
        ];
    }

    /**
     * Get the fee for a specific plan duration.
     *
     * @param  int  $months  Plan duration in months (3, 6, or 9)
     * @return float The fee amount, or 0 if invalid duration
     */
    public function getFee(int $months): float
    {
        return self::PLAN_OPTIONS[$months] ?? 0.00;
    }

    /**
     * Check if a plan duration is valid.
     *
     * @param  int  $months  Plan duration in months
     * @return bool True if valid duration
     */
    public function isValidDuration(int $months): bool
    {
        return array_key_exists($months, self::PLAN_OPTIONS);
    }

    /**
     * Calculate payment plan details including down payment.
     *
     * @param  float  $invoiceAmount  Invoice amount (before fee)
     * @param  int  $duration  Number of months (3, 6, or 9)
     * @return array Complete plan calculation details
     */
    public function calculatePlanDetails(float $invoiceAmount, int $duration): array
    {
        if (! $this->isValidDuration($duration)) {
            return [];
        }

        $fee = $this->getFee($duration);
        $totalAmount = $invoiceAmount + $fee;
        $downPayment = $this->calculateDownPayment($totalAmount);
        $remainingBalance = $totalAmount - $downPayment;
        $monthlyPayment = round($remainingBalance / $duration, 2);

        // Calculate last payment to handle rounding
        $lastPayment = $remainingBalance - ($monthlyPayment * ($duration - 1));

        return [
            'invoice_amount' => round($invoiceAmount, 2),
            'plan_fee' => $fee,
            'total_amount' => round($totalAmount, 2),
            'down_payment' => $downPayment,
            'down_payment_percent' => self::DOWN_PAYMENT_PERCENT * 100,
            'remaining_balance' => round($remainingBalance, 2),
            'monthly_payment' => $monthlyPayment,
            'last_payment' => round($lastPayment, 2),
            'duration_months' => $duration,
        ];
    }

    /**
     * Calculate the payment schedule with down payment.
     *
     * @param  float  $invoiceAmount  Invoice amount (before fee)
     * @param  int  $duration  Number of monthly payments (3, 6, or 9)
     * @param  string|null  $startDate  Start date for first monthly payment (after down payment)
     * @param  bool  $splitDownPayment  Whether to split down payment (admin only)
     * @return array Array of scheduled payments including down payment
     */
    public function calculateSchedule(
        float $invoiceAmount,
        int $duration,
        ?string $startDate = null,
        bool $splitDownPayment = false
    ): array {
        if ($invoiceAmount <= 0 || ! $this->isValidDuration($duration)) {
            return [];
        }

        $details = $this->calculatePlanDetails($invoiceAmount, $duration);
        $schedule = [];
        $start = $startDate ? Carbon::parse($startDate) : now();

        // Add down payment(s) first
        if ($splitDownPayment) {
            $splitInfo = $this->calculateSplitDownPayment($details['total_amount'], $start);

            $schedule[] = [
                'payment_number' => 0,
                'type' => 'down_payment',
                'due_date' => $splitInfo['first_payment']['date_formatted'],
                'due_date_raw' => $splitInfo['first_payment']['date']->format('Y-m-d'),
                'amount' => $splitInfo['first_payment']['amount'],
                'label' => 'Down Payment (1 of 2)',
            ];

            $schedule[] = [
                'payment_number' => 0,
                'type' => 'down_payment',
                'due_date' => $splitInfo['second_payment']['date_formatted'],
                'due_date_raw' => $splitInfo['second_payment']['date']->format('Y-m-d'),
                'amount' => $splitInfo['second_payment']['amount'],
                'label' => 'Down Payment (2 of 2)',
            ];
        } else {
            $schedule[] = [
                'payment_number' => 0,
                'type' => 'down_payment',
                'due_date' => $start->format('M j, Y'),
                'due_date_raw' => $start->format('Y-m-d'),
                'amount' => $details['down_payment'],
                'label' => 'Down Payment (30%)',
            ];
        }

        // Add monthly payments
        for ($i = 1; $i <= $duration; $i++) {
            $dueDate = $start->copy()->addMonths($i);
            $amount = ($i === $duration) ? $details['last_payment'] : $details['monthly_payment'];

            $schedule[] = [
                'payment_number' => $i,
                'type' => 'installment',
                'due_date' => $dueDate->format('M j, Y'),
                'due_date_raw' => $dueDate->format('Y-m-d'),
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

    /**
     * Calculate fee (backwards compatible method).
     *
     * @param  float  $paymentAmount  Total invoice amount
     * @param  float  $downPayment  Down payment amount (unused - calculated automatically)
     * @param  int  $duration  Number of months (3, 6, or 9)
     * @param  string  $frequency  Payment frequency (unused - always monthly now)
     * @return array Fee details for backwards compatibility
     */
    public function calculateFee(float $paymentAmount, float $downPayment, int $duration, string $frequency): array
    {
        $fee = $this->getFee($duration);
        $totalAmount = $paymentAmount + $fee;
        $calculatedDownPayment = $this->calculateDownPayment($totalAmount);

        return [
            'fee_amount' => $fee,
            'months' => $duration,
            'duration_multiplier' => 1.0,
            'down_payment_multiplier' => 1.0,
            'down_payment_percent' => self::DOWN_PAYMENT_PERCENT * 100,
            'down_payment_amount' => $calculatedDownPayment,
        ];
    }
}
