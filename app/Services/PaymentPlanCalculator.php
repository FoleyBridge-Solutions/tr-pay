<?php

namespace App\Services;

use Carbon\Carbon;

class PaymentPlanCalculator
{
    /**
     * Calculate payment plan fee based on risk and duration.
     * 
     * @param float $paymentAmount Total invoice amount
     * @param float $downPayment Down payment amount
     * @param int $duration Number of installments
     * @param string $frequency Payment frequency
     * @return array Fee details ['fee_amount' => float, 'rate_used' => float, 'risk_tier' => int, 'duration_tier' => string]
     */
    public function calculateFee(float $paymentAmount, float $downPayment, int $duration, string $frequency): array
    {
        // 1. Calculate Principal Financed
        $principalFinanced = $paymentAmount - $downPayment;
        
        // If down payment covers everything, no fee
        if ($principalFinanced <= 0) {
            return [
                'fee_amount' => 0.00,
                'rate_used' => 0.00,
                'risk_tier' => 0,
                'duration_tier' => 'none',
            ];
        }

        // 2. Calculate Down Payment Percentage
        $downPaymentPercent = ($paymentAmount > 0) ? ($downPayment / $paymentAmount) * 100 : 0;

        // 3. Determine Risk Tier (Column)
        // Tier 1 (High Down Pmt): > 30%
        // Tier 2 (Std Down Pmt): 15-30%
        // Tier 3 (Low Down Pmt): < 15%
        $riskTier = 3; // Default to high risk
        if ($downPaymentPercent > 30) {
            $riskTier = 1;
        } elseif ($downPaymentPercent >= 15) {
            $riskTier = 2;
        }

        // 4. Determine Duration Tier (Row) based on TOTAL DAYS
        $daysPerInstallment = $this->getDaysPerInstallment($frequency);
        $totalDays = $duration * $daysPerInstallment;

        // Short: < 150 days (approx 5 months)
        // Medium: 150 - 240 days (approx 5-8 months)
        // Long: > 240 days (approx 8+ months)
        $durationTier = 'short';
        if ($totalDays >= 150 && $totalDays <= 240) {
            $durationTier = 'medium';
        } elseif ($totalDays > 240) {
            $durationTier = 'long';
        }

        // 5. Fetch Rate from Matrix
        // [Risk Tier][Duration Tier]
        $rates = [
            1 => ['short' => 0.020, 'medium' => 0.035, 'long' => 0.050], // High Down Payment
            2 => ['short' => 0.030, 'medium' => 0.050, 'long' => 0.070], // Standard Down Payment
            3 => ['short' => 0.045, 'medium' => 0.070, 'long' => 0.090], // Low Down Payment
        ];

        $rate = $rates[$riskTier][$durationTier];

        // 6. Calculate Fee
        // Fee = $15.00 Setup + (Principal * Rate)
        $setupFee = 15.00;
        $variableFee = $principalFinanced * $rate;
        $totalFee = round($setupFee + $variableFee, 2);

        return [
            'fee_amount' => $totalFee,
            'rate_used' => $rate,
            'risk_tier' => $riskTier,
            'duration_tier' => $durationTier,
        ];
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
     * Assumes a maximum term of approx 12 months.
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
