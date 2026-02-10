<?php

// app/Support/Money.php

namespace App\Support;

use InvalidArgumentException;

/**
 * Money Utility Class
 *
 * Provides consistent, precision-safe currency handling throughout the application.
 * Addresses floating-point arithmetic issues by working with integer cents internally.
 *
 * Usage:
 *   $cents = Money::toCents(19.99);        // Returns 1999
 *   $dollars = Money::toDollars(1999);     // Returns 19.99
 *   $total = Money::addDollars(10.00, 5.50); // Returns 15.50 (precision-safe)
 *
 * Why this matters:
 *   - Floating-point: 0.1 + 0.2 = 0.30000000000000004
 *   - This class: 10 + 20 = 30 cents = 0.30 dollars
 */
class Money
{
    /**
     * Number of decimal places for currency display.
     */
    public const DECIMAL_PLACES = 2;

    /**
     * Multiplier to convert dollars to cents.
     */
    public const CENTS_MULTIPLIER = 100;

    /**
     * Convert dollars to cents (integer).
     *
     * Uses bcmul for precision, then rounds to avoid floating-point errors.
     * This is the preferred way to prepare amounts for payment gateways.
     *
     * @param  float|string|int  $dollars  Amount in dollars
     * @return int Amount in cents (always positive or zero)
     *
     * @throws InvalidArgumentException If amount is negative
     *
     * @example
     *   Money::toCents(19.99)  // Returns 1999
     *   Money::toCents('19.99') // Returns 1999
     *   Money::toCents(100)    // Returns 10000
     */
    public static function toCents(float|string|int $dollars): int
    {
        // Convert to string for BC Math precision
        $dollarsStr = (string) $dollars;

        // Validate non-negative amount
        if (bccomp($dollarsStr, '0', self::DECIMAL_PLACES) < 0) {
            throw new InvalidArgumentException('Money amount cannot be negative: '.$dollarsStr);
        }

        // Use BC Math for precise multiplication, then round to integer
        // bcmul returns string, so we cast to float first for rounding
        $cents = bcmul($dollarsStr, (string) self::CENTS_MULTIPLIER, 0);

        return (int) $cents;
    }

    /**
     * Convert cents to dollars (float).
     *
     * Returns a float rounded to 2 decimal places for display/storage.
     *
     * @param  int  $cents  Amount in cents
     * @return float Amount in dollars, rounded to 2 decimal places
     *
     * @example
     *   Money::toDollars(1999)  // Returns 19.99
     *   Money::toDollars(100)   // Returns 1.00
     */
    public static function toDollars(int $cents): float
    {
        // Simple division with rounding for display
        return round($cents / self::CENTS_MULTIPLIER, self::DECIMAL_PLACES);
    }

    /**
     * Add two dollar amounts safely (avoids floating-point errors).
     *
     * Converts to cents, adds, then converts back to dollars.
     *
     * @param  float|string|int  $amount1  First amount in dollars
     * @param  float|string|int  $amount2  Second amount in dollars
     * @return float Sum in dollars, rounded to 2 decimal places
     *
     * @example
     *   Money::addDollars(10.00, 5.50)  // Returns 15.50
     *   Money::addDollars(0.1, 0.2)     // Returns 0.30 (not 0.30000000000000004)
     */
    public static function addDollars(float|string|int $amount1, float|string|int $amount2): float
    {
        $cents1 = self::toCents($amount1);
        $cents2 = self::toCents($amount2);

        return self::toDollars($cents1 + $cents2);
    }

    /**
     * Subtract dollar amounts safely (avoids floating-point errors).
     *
     * @param  float|string|int  $amount1  Amount to subtract from (minuend)
     * @param  float|string|int  $amount2  Amount to subtract (subtrahend)
     * @return float Difference in dollars, rounded to 2 decimal places
     *
     * @example
     *   Money::subtractDollars(20.00, 5.50)  // Returns 14.50
     */
    public static function subtractDollars(float|string|int $amount1, float|string|int $amount2): float
    {
        $cents1 = self::toCents($amount1);
        $cents2 = self::toCents($amount2);

        // Note: Result can be negative if amount2 > amount1
        return round(($cents1 - $cents2) / self::CENTS_MULTIPLIER, self::DECIMAL_PLACES);
    }

    /**
     * Multiply a dollar amount by a factor (e.g., for percentage calculations).
     *
     * @param  float|string|int  $dollars  Base amount in dollars
     * @param  float|string  $factor  Multiplication factor
     * @return float Result in dollars, rounded to 2 decimal places
     *
     * @example
     *   Money::multiplyDollars(100.00, 0.04)  // Returns 4.00 (4% of 100)
     *   Money::multiplyDollars(150.00, 1.5)   // Returns 225.00
     */
    public static function multiplyDollars(float|string|int $dollars, float|string $factor): float
    {
        $dollarsStr = (string) $dollars;
        $factorStr = (string) $factor;

        // Use BC Math for precise multiplication
        $result = bcmul($dollarsStr, $factorStr, self::DECIMAL_PLACES + 2);

        return round((float) $result, self::DECIMAL_PLACES);
    }

    /**
     * Calculate a percentage of a dollar amount.
     *
     * @param  float|string|int  $dollars  Base amount in dollars
     * @param  float|string  $percent  Percentage (e.g., 4.0 for 4%, 30.0 for 30%)
     * @return float Percentage amount in dollars, rounded to 2 decimal places
     *
     * @example
     *   Money::percentOf(100.00, 4.0)   // Returns 4.00 (4% of 100)
     *   Money::percentOf(1000.00, 30.0) // Returns 300.00 (30% of 1000)
     */
    public static function percentOf(float|string|int $dollars, float|string $percent): float
    {
        return self::multiplyDollars($dollars, (float) $percent / 100);
    }

    /**
     * Round a dollar amount to 2 decimal places.
     *
     * Useful for ensuring consistent precision after external calculations.
     *
     * @param  float|string|int  $dollars  Amount to round
     * @return float Rounded amount
     */
    public static function round(float|string|int $dollars): float
    {
        return round((float) $dollars, self::DECIMAL_PLACES);
    }

    /**
     * Format dollars for display with currency symbol.
     *
     * @param  float|string|int  $dollars  Amount in dollars
     * @param  string  $symbol  Currency symbol (default: $)
     * @return string Formatted string (e.g., "$19.99")
     *
     * @example
     *   Money::format(19.99)      // Returns "$19.99"
     *   Money::format(1000)       // Returns "$1,000.00"
     *   Money::format(19.99, '€') // Returns "€19.99"
     */
    public static function format(float|string|int $dollars, string $symbol = '$'): string
    {
        $amount = (float) $dollars;

        return $symbol.number_format($amount, self::DECIMAL_PLACES, '.', ',');
    }

    /**
     * Check if two dollar amounts are equal (handles floating-point comparison).
     *
     * @param  float|string|int  $amount1  First amount
     * @param  float|string|int  $amount2  Second amount
     * @return bool True if amounts are equal within precision tolerance
     *
     * @example
     *   Money::equals(10.00, 10.0)   // Returns true
     *   Money::equals(10.00, 10.01)  // Returns false
     */
    public static function equals(float|string|int $amount1, float|string|int $amount2): bool
    {
        return self::toCents($amount1) === self::toCents($amount2);
    }

    /**
     * Check if amount is zero.
     *
     * @param  float|string|int  $dollars  Amount to check
     * @return bool True if amount is zero
     */
    public static function isZero(float|string|int $dollars): bool
    {
        return self::toCents($dollars) === 0;
    }

    /**
     * Check if amount is positive (greater than zero).
     *
     * @param  float|string|int  $dollars  Amount to check
     * @return bool True if amount is positive
     */
    public static function isPositive(float|string|int $dollars): bool
    {
        return self::toCents($dollars) > 0;
    }
}
