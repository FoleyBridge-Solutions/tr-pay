<?php

return [

    /*
    |--------------------------------------------------------------------------
    | MiPaymentChoice API Credentials
    |--------------------------------------------------------------------------
    |
    | Your MiPaymentChoice API credentials. These can be found in your
    | MiPaymentChoice merchant dashboard.
    |
    */

    'username' => env('MIPAYMENTCHOICE_USERNAME'),
    'password' => env('MIPAYMENTCHOICE_PASSWORD'),
    'merchant_key' => env('MIPAYMENTCHOICE_MERCHANT_KEY'),

    /*
    |--------------------------------------------------------------------------
    | MiPaymentChoice API URL
    |--------------------------------------------------------------------------
    |
    | The base URL for the MiPaymentChoice API. Use the test URL for development
    | and the production URL for live transactions.
    |
    */

    'base_url' => env('MIPAYMENTCHOICE_BASE_URL', 'https://gateway.mipaymentchoice.com'),

    /*
    |--------------------------------------------------------------------------
    | Currency
    |--------------------------------------------------------------------------
    |
    | The default currency for all transactions. This should be a valid
    | ISO 4217 currency code.
    |
    */

    'currency' => env('CASHIER_CURRENCY', 'usd'),

    /*
    |--------------------------------------------------------------------------
    | Cashier Model
    |--------------------------------------------------------------------------
    |
    | This is the model in your application that includes the Billable trait
    | provided by Cashier. It will be used for all subscription operations.
    |
    */

    'model' => env('CASHIER_MODEL', App\Models\User::class),

    /*
    |--------------------------------------------------------------------------
    | Customer Columns
    |--------------------------------------------------------------------------
    |
    | Define which columns on your billable entity are used for storing
    | MiPaymentChoice customer identifiers.
    |
    */

    'customer_columns' => [
        'customer_id' => 'mpc_customer_id',
    ],

];
