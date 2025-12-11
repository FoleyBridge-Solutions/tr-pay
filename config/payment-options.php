<?php

// config/payment-options.php

return [

    /*
    |--------------------------------------------------------------------------
    | Payment Method Availability
    |--------------------------------------------------------------------------
    |
    | Enable or disable specific payment methods. Set to false to hide
    | a payment method from users.
    |
    */

    'enabled_methods' => [
        'credit_card' => env('ENABLE_CREDIT_CARD', true),
        'ach' => env('ENABLE_ACH', true),
        'check' => env('ENABLE_CHECK', true),
        'payment_plan' => env('ENABLE_PAYMENT_PLANS', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Payment Plan Options
    |--------------------------------------------------------------------------
    |
    | Simple payment plan configuration.
    | Plans available: 3, 6, or 9 months (always monthly payments)
    |
    */

    'payment_plan_durations' => [3, 6, 9], // Allowed plan durations in months

    /*
    |--------------------------------------------------------------------------
    | Payment Flow Options
    |--------------------------------------------------------------------------
    |
    | General settings for the payment flow process.
    |
    */

    'require_project_acceptance' => env('REQUIRE_PROJECT_ACCEPTANCE', true),

    'allow_partial_payments' => env('ALLOW_PARTIAL_PAYMENTS', false),

    'minimum_payment_amount' => (float) env('MINIMUM_PAYMENT_AMOUNT', 10.00),

    /*
    |--------------------------------------------------------------------------
    | Account Type Options
    |--------------------------------------------------------------------------
    |
    | Available account types for ACH payments.
    |
    */

    'account_types' => [
        'business' => env('ENABLE_BUSINESS_ACCOUNTS', true),
        'personal' => env('ENABLE_PERSONAL_ACCOUNTS', true),
    ],

];
