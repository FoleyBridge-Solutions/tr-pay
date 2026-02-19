<?php

return [

    /*
    |--------------------------------------------------------------------------
    | PracticeCS API Client (practicecs-pi package)
    |--------------------------------------------------------------------------
    |
    | Configuration for the PracticeCS Programming Interface client package.
    | This replaces direct DB::connection('sqlsrv') queries with API calls
    | to the PracticeCS API microservice.
    |
    */

    // Master switch - must be explicitly enabled
    'enabled' => env('PRACTICECS_API_ENABLED', false),

    // Base URL of the PracticeCS API microservice
    'base_url' => env('PRACTICECS_API_BASE_URL', 'http://localhost:8001'),

    // API key for authentication
    'api_key' => env('PRACTICECS_API_KEY', ''),

    /*
    |--------------------------------------------------------------------------
    | Rate Limiting
    |--------------------------------------------------------------------------
    */

    'rate_limit' => [
        'enabled' => env('PRACTICECS_RATE_LIMIT_ENABLED', true),
        'max_requests' => env('PRACTICECS_RATE_LIMIT_MAX', 1000),
        'per_seconds' => env('PRACTICECS_RATE_LIMIT_PERIOD', 3600),
    ],

    /*
    |--------------------------------------------------------------------------
    | Retry Configuration
    |--------------------------------------------------------------------------
    */

    'retry' => [
        'max_attempts' => env('PRACTICECS_RETRY_MAX', 3),
        'base_delay_ms' => env('PRACTICECS_RETRY_DELAY', 100),
        'multiplier' => env('PRACTICECS_RETRY_MULTIPLIER', 2),
    ],

    /*
    |--------------------------------------------------------------------------
    | Timeouts
    |--------------------------------------------------------------------------
    */

    'timeout' => [
        'connect' => env('PRACTICECS_TIMEOUT_CONNECT', 5),
        'request' => env('PRACTICECS_TIMEOUT_REQUEST', 30),
    ],

    /*
    |--------------------------------------------------------------------------
    | Local Payment Integration Settings
    |--------------------------------------------------------------------------
    |
    | These settings are used by TR-Pay locally for payment plan tracking,
    | invoice allocation, and other operations that don't go through the API.
    | The actual PracticeCS write config (staff_key, bank_account_key,
    | ledger_types, etc.) now lives in the API microservice's config.
    |
    */

    'payment_integration' => [

        // Master switch for local write operations (plan tracking, allocation)
        'enabled' => env('PRACTICECS_WRITE_ENABLED', false),

        // Staff KEY — still needed locally for payment plan tracking records
        'staff_key' => env('PRACTICECS_STAFF_KEY', 1552),

        // Bank account KEY — still needed locally for payment plan records
        'bank_account_key' => env('PRACTICECS_BANK_ACCOUNT_KEY', 2),

        // Ledger entry type mappings — needed for local display/categorization
        'ledger_types' => [
            'credit_card' => 9,
            'ach' => 11,
            'check' => 8,
            'cash' => 8,
        ],

        // Payment subtypes — needed for local display/categorization
        'payment_subtypes' => [
            'credit_card' => 10,
            'ach' => 12,
            'check' => 9,
            'cash' => 9,
        ],

        // Whether to auto-post payments
        'auto_post' => env('PRACTICECS_AUTO_POST', true),

        // Whether to track in Online_Payment table
        'track_online_payments' => env('PRACTICECS_TRACK_ONLINE', false),

        // Memo types for client group payment distribution
        'memo_types' => [
            'debit' => 3,
            'credit' => 5,
        ],

        // Memo subtypes
        'memo_subtypes' => [
            'debit' => 4,
            'credit' => 6,
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Engagement Template Maps (Local Fallback)
    |--------------------------------------------------------------------------
    |
    | These template-to-type mappings are used as a local fallback when the
    | API is unreachable for getTargetTypeKey() and isExpansionTemplate().
    | The authoritative copy lives in the API microservice's config.
    |
    */

    'static_template_map' => [
        'EXPAUDIT' => 25,
        'EXPCONSULT' => 22,
        'EXPEXAM' => 24,
        'EXPFIN' => 14,
        'EXPPLANNING' => 23,
        'EXPREP' => 4,
        'EXPSTARTUP' => 5,
        'EXPTAX' => 16,
        'EXPVAL' => 15,
        'TAXFEEREQ' => 16,
        'GAMEPLAN' => 2,
    ],

    'year_based_template_suffix' => [
        'EXPADVISORY' => 'ADVISOR',
        'EXPAYROLL' => 'PAYROLL',
        'EXPBOOK' => 'BOOKS',
        'EXPSALES' => 'SALES',
    ],

    'legacy_year_type_map' => [
        'EXPADVISORY' => 21,
        'EXPAYROLL' => 13,
        'EXPBOOK' => 12,
        'EXPSALES' => 12,
    ],

];
