<?php

// config/payment-fees.php

return [

    /*
    |--------------------------------------------------------------------------
    | Payment Processing Fees
    |--------------------------------------------------------------------------
    |
    | Configure the fees and rates for various payment methods and
    | payment plan options. These values affect payment calculations
    | throughout the application.
    |
    */

    /*
    |--------------------------------------------------------------------------
    | Credit Card Processing Fee
    |--------------------------------------------------------------------------
    |
    | The percentage fee applied to credit card transactions.
    | Default: 0.03 (3%)
    |
    */

    'credit_card_rate' => (float) env('PAYMENT_CREDIT_CARD_FEE', 0.03),

    /*
    |--------------------------------------------------------------------------
    | Payment Plan Fees (Fixed Dollar Amounts)
    |--------------------------------------------------------------------------
    |
    | Fixed fees based on total payment amount ranges.
    | Fee is applied based on the invoice total.
    |
    */

    'payment_plan_fees' => [
        ['min' => 0,     'max' => 500,    'fee' => 25.00],
        ['min' => 500,   'max' => 1000,   'fee' => 50.00],
        ['min' => 1000,  'max' => 2500,   'fee' => 75.00],
        ['min' => 2500,  'max' => 5000,   'fee' => 125.00],
        ['min' => 5000,  'max' => 10000,  'fee' => 200.00],
        ['min' => 10000, 'max' => PHP_FLOAT_MAX, 'fee' => 350.00],
    ],

];
