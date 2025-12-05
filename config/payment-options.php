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
    | Payment Plan Defaults
    |--------------------------------------------------------------------------
    |
    | Default values for payment plan calculations.
    |
    */

    'default_down_payment_percent' => (float) env('DEFAULT_DOWN_PAYMENT', 0.20), // 20%

    'minimum_down_payment_percent' => (float) env('MINIMUM_DOWN_PAYMENT', 0.10), // 10%

    'maximum_down_payment_percent' => (float) env('MAXIMUM_DOWN_PAYMENT', 0.90), // 90%

    /*
    |--------------------------------------------------------------------------
    | Payment Plan Limits
    |--------------------------------------------------------------------------
    |
    | Maximum number of installments allowed for different payment frequencies.
    |
    */

    'max_installments' => [
        'weekly' => (int) env('MAX_INSTALLMENTS_WEEKLY', 52),
        'biweekly' => (int) env('MAX_INSTALLMENTS_BIWEEKLY', 26),
        'monthly' => (int) env('MAX_INSTALLMENTS_MONTHLY', 24),
    ],

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
