<?php

namespace App\Services;

use App\Support\Money;
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
     * @param  float  $creditCardFeeRate  Credit card NCA rate (e.g. 0.04 for 4%), default 0
     * @return array Array of plan options with duration, fee, down payment, and monthly payment
     */
    public function getAvailablePlans(float $invoiceAmount, float $creditCardFeeRate = 0.0): array
    {
        $plans = [];

        foreach (self::PLAN_OPTIONS as $months => $fee) {
            $subtotal = Money::addDollars($invoiceAmount, $fee);
            $creditCardFee = $creditCardFeeRate > 0
                ? Money::round($subtotal * $creditCardFeeRate)
                : 0.0;
            $totalAmount = Money::addDollars($subtotal, $creditCardFee);
            $downPayment = $this->calculateDownPayment($totalAmount);
            $remainingBalance = Money::subtractDollars($totalAmount, $downPayment);
            $monthlyPayment = Money::round($remainingBalance / $months);

            $plans[] = [
                'months' => $months,
                'fee' => $fee,
                'credit_card_fee' => $creditCardFee,
                'total_amount' => Money::round($totalAmount),
                'down_payment' => $downPayment,
                'down_payment_percent' => self::DOWN_PAYMENT_PERCENT * 100,
                'remaining_balance' => Money::round($remainingBalance),
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
        return Money::multiplyDollars($totalAmount, self::DOWN_PAYMENT_PERCENT);
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
        $halfPayment = Money::round($downPayment / 2);
        // Ensure the two halves equal the total (handle rounding)
        $firstPayment = $halfPayment;
        $secondPayment = Money::subtractDollars($downPayment, $firstPayment);

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
     * When a credit card fee rate is provided, the NCA (Non-Cash Adjustment)
     * is calculated on (invoice + plan fee) and baked into the total before
     * splitting into down payment and monthly installments.
     *
     * @param  float  $invoiceAmount  Invoice amount (before fee)
     * @param  int  $duration  Number of months (3, 6, or 9)
     * @param  float|null  $customFee  Override fee amount (null = use standard fee)
     * @param  float|null  $customDownPayment  Override down payment amount (null = use 30%)
     * @param  float  $creditCardFeeRate  Credit card NCA rate (e.g. 0.04 for 4%), default 0
     * @return array Complete plan calculation details
     */
    public function calculatePlanDetails(
        float $invoiceAmount,
        int $duration,
        ?float $customFee = null,
        ?float $customDownPayment = null,
        float $creditCardFeeRate = 0.0
    ): array {
        if (! $this->isValidDuration($duration)) {
            return [];
        }

        $fee = $customFee !== null ? Money::round($customFee) : $this->getFee($duration);
        $subtotal = Money::addDollars($invoiceAmount, $fee);

        // Calculate credit card NCA on (invoice + plan fee)
        $creditCardFee = $creditCardFeeRate > 0
            ? Money::round($subtotal * $creditCardFeeRate)
            : 0.0;

        $totalAmount = Money::addDollars($subtotal, $creditCardFee);

        $downPayment = $customDownPayment !== null
            ? Money::round(min($customDownPayment, $totalAmount))
            : $this->calculateDownPayment($totalAmount);

        $remainingBalance = Money::subtractDollars($totalAmount, $downPayment);
        $monthlyPayment = $duration > 0 && $remainingBalance > 0
            ? Money::round($remainingBalance / $duration)
            : 0;

        // Calculate last payment to handle rounding
        $lastPayment = $duration > 1 && $monthlyPayment > 0
            ? Money::subtractDollars($remainingBalance, Money::multiplyDollars($monthlyPayment, $duration - 1))
            : $remainingBalance;

        return [
            'invoice_amount' => Money::round($invoiceAmount),
            'plan_fee' => $fee,
            'credit_card_fee' => $creditCardFee,
            'total_amount' => Money::round($totalAmount),
            'down_payment' => $downPayment,
            'down_payment_percent' => $customDownPayment !== null ? null : self::DOWN_PAYMENT_PERCENT * 100,
            'remaining_balance' => Money::round($remainingBalance),
            'monthly_payment' => $monthlyPayment,
            'last_payment' => Money::round($lastPayment),
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
     * @param  float|null  $customFee  Override fee amount (null = use standard fee)
     * @param  float|null  $customDownPayment  Override down payment amount (null = use 30%)
     * @param  int|null  $recurringDay  Day of month for recurring payments (1-28, null = same day as start)
     * @param  float  $creditCardFeeRate  Credit card NCA rate (e.g. 0.04 for 4%), default 0
     * @return array Array of scheduled payments including down payment
     */
    public function calculateSchedule(
        float $invoiceAmount,
        int $duration,
        ?string $startDate = null,
        bool $splitDownPayment = false,
        ?float $customFee = null,
        ?float $customDownPayment = null,
        ?int $recurringDay = null,
        float $creditCardFeeRate = 0.0
    ): array {
        if ($invoiceAmount <= 0 || ! $this->isValidDuration($duration)) {
            return [];
        }

        $details = $this->calculatePlanDetails($invoiceAmount, $duration, $customFee, $customDownPayment, $creditCardFeeRate);
        $schedule = [];
        $start = $startDate ? Carbon::parse($startDate) : now();

        // Add down payment(s) first (skip if down payment is $0)
        if ($details['down_payment'] > 0) {
            $isCustomDown = $customDownPayment !== null;
            $downPaymentLabel = $isCustomDown ? 'Down Payment (Custom)' : 'Down Payment (30%)';

            if ($splitDownPayment && ! $isCustomDown) {
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
                    'label' => $downPaymentLabel,
                ];
            }
        }

        // Add monthly payments
        for ($i = 1; $i <= $duration; $i++) {
            $dueDate = $this->calculateRecurringDate($start, $i, $recurringDay);
            $amount = ($i === $duration) ? $details['last_payment'] : $details['monthly_payment'];

            $schedule[] = [
                'payment_number' => $i,
                'type' => 'installment',
                'due_date' => $dueDate->format('M j, Y'),
                'due_date_raw' => $dueDate->format('Y-m-d'),
                'amount' => Money::round($amount),
                'label' => "Payment $i of $duration",
            ];
        }

        return $schedule;
    }

    /**
     * Calculate a recurring payment date.
     *
     * When a custom recurring day is set, payments land on that day each month.
     * If the month has fewer days than requested (e.g. 31st in February),
     * the payment falls on the last day of that month.
     *
     * @param  Carbon  $start  The plan start date
     * @param  int  $monthsAhead  Number of months from the start date
     * @param  int|null  $recurringDay  Custom day of month (1-31), or null for default behavior
     * @return Carbon The calculated due date
     */
    public function calculateRecurringDate(Carbon $start, int $monthsAhead, ?int $recurringDay = null): Carbon
    {
        if ($recurringDay === null) {
            return $start->copy()->addMonths($monthsAhead);
        }

        $day = max(1, min(31, $recurringDay));
        $date = $start->copy()->addMonthsNoOverflow($monthsAhead);
        $daysInMonth = $date->daysInMonth;

        return $date->day(min($day, $daysInMonth));
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
        $totalAmount = Money::addDollars($paymentAmount, $fee);
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
