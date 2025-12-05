<?php

namespace App\Services;

use Carbon\Carbon;

class PaymentPlanCalculator
{
    /**
     * Calculate payment plan fee based on total amount, duration, and down payment.
     * 
     * @param float $paymentAmount Total invoice amount
     * @param float $downPayment Down payment amount
     * @param int $duration Number of installments
     * @param string $frequency Payment frequency
     * @return array Fee details ['fee_amount' => float, 'months' => int, 'duration_multiplier' => float, 'down_payment_multiplier' => float, 'down_payment_percent' => float]
     */
    public function calculateFee(float $paymentAmount, float $downPayment, int $duration, string $frequency): array
    {
        $feeRanges = config('payment-fees.payment_plan_fees');
        
        $baseFee = 0.00;
        
        // Find the base fee based on payment amount
        foreach ($feeRanges as $range) {
            if ($paymentAmount >= $range['min'] && $paymentAmount < $range['max']) {
                $baseFee = $range['fee'];
                break;
            }
        }

        // Calculate total duration in months
        $months = $this->calculateMonthsFromDuration($duration, $frequency);
        
        // Determine duration multiplier
        $durationMultiplier = $this->getDurationMultiplier($months);
        
        // Calculate down payment percentage
        $downPaymentPercent = $paymentAmount > 0 ? ($downPayment / $paymentAmount) : 0;
        
        // Down payment multiplier: 1 - (down payment %)
        // e.g., 25% down = 0.75x, 50% down = 0.50x, 75% down = 0.25x
        $downPaymentMultiplier = 1 - $downPaymentPercent;
        
        // Apply both multipliers to base fee
        $finalFee = round($baseFee * $durationMultiplier * $downPaymentMultiplier, 2);

        return [
            'fee_amount' => $finalFee,
            'months' => $months,
            'duration_multiplier' => $durationMultiplier,
            'down_payment_multiplier' => $downPaymentMultiplier,
            'down_payment_percent' => round($downPaymentPercent * 100, 2),
        ];
    }
    
    /**
     * Calculate approximate months from duration and frequency.
     */
    public function calculateMonthsFromDuration(int $duration, string $frequency): int
    {
        $daysPerInstallment = $this->getDaysPerInstallment($frequency);
        $totalDays = $duration * $daysPerInstallment;
        
        // Convert days to months (using 30 days per month)
        return (int) round($totalDays / 30);
    }
    
    /**
     * Get duration multiplier based on total months.
     * 0-3 months: 1.0
     * 4-7 months: 1.75
     * 8-11 months: 2.5
     * Maximum allowed plan duration is 11 months
     */
    public function getDurationMultiplier(int $months): float
    {
        if ($months <= 3) {
            return 1.0;
        } elseif ($months <= 7) {
            return 1.75;
        } elseif ($months <= 11) {
            return 2.5;
        } else {
            // Plans over 11 months are not allowed, but return 0 to indicate invalid
            return 0;
        }
    }

    /**
     * Calculate the payment schedule dates and amounts.
     *
     * @param float $totalAmountToFinance Total amount including fees
     * @param float $downPayment Down payment amount
     * @param int $duration Number of installments
     * @param string $frequency Payment frequency
     * @param string|null $startDate Start date for installments
     * @param array $customInstallments Optional custom installment amounts
     * @return array Array of schedule items
     */
    public function calculateSchedule(
        float $totalAmountToFinance,
        float $downPayment,
        int $duration,
        string $frequency,
        ?string $startDate = null,
        array $customInstallments = []
    ): array {
        $schedule = [];
        $remainingBalance = $totalAmountToFinance - $downPayment;

        if ($remainingBalance <= 0 || $duration < 1) {
            return [];
        }

        $frequencyDays = $this->getDaysPerInstallment($frequency);
        $start = $startDate ? Carbon::parse($startDate) : now();

        // Add down payment as first payment (due today)
        if ($downPayment > 0) {
            $schedule[] = [
                'payment_number' => 0,
                'due_date' => now()->format('M d, Y'),
                'amount' => $downPayment,
                'label' => 'Down Payment (Today)',
            ];
        }

        // Generate installments
        if (!empty($customInstallments)) {
            // Use custom amounts logic
            $totalCustomAmount = array_sum($customInstallments);
            
            // If custom amounts don't match balance, adjust the last payment
            if (abs($totalCustomAmount - $remainingBalance) > 0.01) {
                $lastIndex = count($customInstallments) - 1;
                if ($lastIndex >= 0) {
                    $diff = $remainingBalance - $totalCustomAmount;
                    $customInstallments[$lastIndex] += $diff;
                }
            }

            for ($i = 0; $i < min($duration, count($customInstallments)); $i++) {
                $dueDate = $start->copy()->addDays($frequencyDays * ($i + 1));
                $schedule[] = [
                    'payment_number' => $i + 1,
                    'due_date' => $dueDate->format('M d, Y'),
                    'amount' => $customInstallments[$i],
                    'label' => 'Payment ' . ($i + 1) . ' of ' . $duration,
                ];
            }
        } else {
            // Equal installments
            $installmentAmount = round($remainingBalance / $duration, 2);
            $lastPaymentAmount = $remainingBalance - ($installmentAmount * ($duration - 1));

            for ($i = 1; $i <= $duration; $i++) {
                $dueDate = $start->copy()->addDays($frequencyDays * $i);
                $amount = ($i === $duration) ? $lastPaymentAmount : $installmentAmount;

                $schedule[] = [
                    'payment_number' => $i,
                    'due_date' => $dueDate->format('M d, Y'),
                    'amount' => $amount,
                    'label' => 'Payment ' . $i . ' of ' . $duration,
                ];
            }
        }

        return $schedule;
    }

    /**
     * Get the maximum allowed installments for a given frequency.
     * Note: Only weekly, biweekly, and monthly are available to users.
     * Maximum term is 11 months.
     */
    public function getMaxInstallments(string $frequency): int
    {
        return match($frequency) {
            'weekly' => 52,
            'biweekly' => 26,
            'monthly' => 12,
            'quarterly' => 4,
            'semiannually' => 2,
            'annually' => 1,
            default => 12,
        };
    }

    /**
     * Get days per installment for frequency.
     */
    public function getDaysPerInstallment(string $frequency): int
    {
        return match($frequency) {
            'weekly' => 7,
            'biweekly' => 14,
            'monthly' => 30,
            'quarterly' => 90,
            'semiannually' => 180,
            'annually' => 365,
            default => 30,
        };
    }
    
    /**
     * Initialize equal custom amounts.
     */
    public function getEqualInstallments(float $totalAmount, int $count): array
    {
        if ($count <= 0) return [];
        
        $equalAmount = round($totalAmount / $count, 2);
        $amounts = array_fill(0, $count, $equalAmount);
        
        // Adjust last
        $amounts[$count - 1] = $totalAmount - ($equalAmount * ($count - 1));
        
        return $amounts;
    }
}
