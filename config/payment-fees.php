<?php

// config/payment-fees.php

return [

    /*
    |--------------------------------------------------------------------------
    | Payment Processing Fees
    |--------------------------------------------------------------------------
    |
    | Configure the fees and rates for various payment methods and
    | payment plan options.
    |
    */

    /*
    |--------------------------------------------------------------------------
    | Credit Card Non-Cash Adjustment
    |--------------------------------------------------------------------------
    |
    | The percentage non-cash adjustment applied to credit card transactions.
    | Default: 0.04 (4%)
    |
    */

    'credit_card_rate' => (float) env('PAYMENT_CREDIT_CARD_FEE', 0.04),

    /*
    |--------------------------------------------------------------------------
    | Payment Plan Fees (Simple Flat Fees)
    |--------------------------------------------------------------------------
    |
    | Flat fees based on plan duration. Simple and straightforward:
    | - 3 months: $150
    | - 6 months: $300
    | - 9 months: $450
    |
    */

    'payment_plan_fees' => [
        3 => 150.00,
        6 => 300.00,
        9 => 450.00,
    ],

];
