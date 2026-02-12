<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Kotapay ACH Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for Kotapay ACH payments and file generation.
    | Kotapay is a division of First International Bank & Trust.
    |
    | NOTE: API credential keys (enabled, client_id, client_secret, etc.)
    | are at the top level to match the kotapay-cashier package's expected
    | config structure. Do NOT nest them under an 'api' sub-array.
    |
    */

    'environment' => env('KOTAPAY_ENV', 'sandbox'),

    /*
    |--------------------------------------------------------------------------
    | API Credentials (Top-Level)
    |--------------------------------------------------------------------------
    |
    | These keys are read directly by the kotapay-cashier package as
    | config('kotapay.enabled'), config('kotapay.client_id'), etc.
    |
    | API Base URL: https://api.kotapay.com
    |
    | Endpoints:
    |   POST /v1/auth/token                    - Get access token
    |   POST /v1/Ach/{CompanyId}/payment       - Create ACH payment
    |   POST /v1/Ach/{CompanyId}/payment/recurring - Create recurring payment
    |   GET  /v1/Ach/{CompanyId}/payment/{id}  - Get payment status
    |   DELETE /v1/Ach/{CompanyId}/payment/void/{id} - Void payment
    |   POST /v1/file/ach                      - Upload NACHA file
    |   POST /v1/reports/far                   - File Acknowledgement Report
    |
    */

    'enabled' => env('KOTAPAY_API_ENABLED', true),

    'base_url' => env('KOTAPAY_API_BASE_URL', 'https://api.kotapay.com'),

    // OAuth2 credentials (provided by Kotapay)
    'client_id' => env('KOTAPAY_API_CLIENT_ID'),
    'client_secret' => env('KOTAPAY_API_CLIENT_SECRET'),
    'username' => env('KOTAPAY_API_USERNAME'),
    'password' => env('KOTAPAY_API_PASSWORD'),

    // Company ID for API requests (provided by Kotapay)
    'company_id' => env('KOTAPAY_API_COMPANY_ID'),

    // Application IDs for ACH payments (from /v1/Companies/{CompanyId}/applications)
    // Personal (PPD) and Business (CCD) accounts require different application IDs
    'application_id' => [
        'personal' => env('KOTAPAY_APPLICATION_ID_PERSONAL', '463591717'),  // PPD-Debit
        'business' => env('KOTAPAY_APPLICATION_ID_BUSINESS', '463591718'),  // CCD-Debit
    ],

    /*
    |--------------------------------------------------------------------------
    | Token Cache
    |--------------------------------------------------------------------------
    |
    | The access token expires after 300 seconds (5 minutes).
    | We cache it with a buffer to prevent using expired tokens.
    |
    */

    'token_cache_key' => 'kotapay_access_token',
    'token_cache_ttl' => 270, // 4.5 minutes (300 - 30 second buffer)

    /*
    |--------------------------------------------------------------------------
    | Request Timeout
    |--------------------------------------------------------------------------
    */

    'timeout' => env('KOTAPAY_TIMEOUT', 30),

    /*
    |--------------------------------------------------------------------------
    | API Rate Limiting
    |--------------------------------------------------------------------------
    */

    'rate_limit' => [
        'enabled' => env('KOTAPAY_RATE_LIMIT_ENABLED', true),
        'max_requests_per_hour' => env('KOTAPAY_RATE_LIMIT_PER_HOUR', 1000),
    ],

    /*
    |--------------------------------------------------------------------------
    | Retry Settings
    |--------------------------------------------------------------------------
    */

    'retry' => [
        'enabled' => env('KOTAPAY_RETRY_ENABLED', true),
        'max_attempts' => env('KOTAPAY_RETRY_MAX_ATTEMPTS', 3),
        'delay_ms' => env('KOTAPAY_RETRY_DELAY_MS', 100),
    ],

    /*
    |--------------------------------------------------------------------------
    | Customer Model
    |--------------------------------------------------------------------------
    */

    'model' => env('KOTAPAY_CUSTOMER_MODEL', 'App\\Models\\Customer'),

    /*
    |--------------------------------------------------------------------------
    | Originator Information
    |--------------------------------------------------------------------------
    |
    | Your company's ACH originator details. These appear in the NACHA file
    | header and batch headers.
    |
    */

    'originator' => [
        // Immediate Destination: Kotapay's routing number
        'immediate_destination' => env('KOTAPAY_IMMEDIATE_DESTINATION'),
        'immediate_destination_name' => env('KOTAPAY_DESTINATION_NAME', 'KOTAPAY'),

        // Immediate Origin: Your company ID (usually tax ID with prefix)
        'immediate_origin' => env('KOTAPAY_IMMEDIATE_ORIGIN'),
        'immediate_origin_name' => env('KOTAPAY_ORIGIN_NAME'),

        // Company identification for batch headers
        'company_name' => env('KOTAPAY_COMPANY_NAME'),
        'company_id' => env('KOTAPAY_COMPANY_ID'), // Usually 1 + EIN

        // ODFI (Originating Depository Financial Institution)
        'odfi_routing' => env('KOTAPAY_ODFI_ROUTING'),

        // Settlement account (where debits are deposited)
        'settlement_routing' => env('KOTAPAY_SETTLEMENT_ROUTING'),
        'settlement_account' => env('KOTAPAY_SETTLEMENT_ACCOUNT'),
    ],

    /*
    |--------------------------------------------------------------------------
    | SEC Codes (Standard Entry Class)
    |--------------------------------------------------------------------------
    |
    | The SEC code determines the type of ACH transaction.
    | WEB = Internet-initiated (most common for online payments)
    | PPD = Prearranged Payment and Deposit
    | CCD = Corporate Credit or Debit
    |
    */

    'default_sec_code' => env('KOTAPAY_SEC_CODE', 'WEB'),

    'sec_codes' => [
        'web' => 'WEB', // Consumer internet-initiated
        'ppd' => 'PPD', // Consumer recurring/pre-authorized
        'ccd' => 'CCD', // Business-to-business
        'tel' => 'TEL', // Telephone-initiated
    ],

    /*
    |--------------------------------------------------------------------------
    | Processing Settings
    |--------------------------------------------------------------------------
    */

    'processing' => [
        // Generate balanced files (offset entries to settlement account)
        'balanced_files' => env('KOTAPAY_BALANCED_FILES', true),

        // Default entry description (max 10 chars)
        'entry_description' => env('KOTAPAY_ENTRY_DESC', 'PAYMENT'),

        // Days in advance to set effective date (0 = same day if before cutoff)
        'effective_date_offset' => env('KOTAPAY_EFFECTIVE_DATE_OFFSET', 1),

        // Daily cutoff time for same-day processing (24hr format)
        'daily_cutoff' => env('KOTAPAY_DAILY_CUTOFF', '14:00'),

        // Maximum entries per batch
        'max_entries_per_batch' => 10000,

        // Maximum batches per file
        'max_batches_per_file' => 100,
    ],

    /*
    |--------------------------------------------------------------------------
    | Notifications
    |--------------------------------------------------------------------------
    */

    'notifications' => [
        // Email to notify on returns
        'returns_email' => env('KOTAPAY_RETURNS_EMAIL'),

        // Slack webhook for alerts
        'slack_webhook' => env('KOTAPAY_SLACK_WEBHOOK'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Return Processing
    |--------------------------------------------------------------------------
    */

    'returns' => [
        // Auto-apply NOC corrections
        'auto_apply_noc' => env('KOTAPAY_AUTO_APPLY_NOC', true),

        // Auto-retry soft returns (R01, R09)
        'auto_retry_soft_returns' => env('KOTAPAY_AUTO_RETRY', false),

        // Maximum retry attempts for soft returns
        'max_retry_attempts' => env('KOTAPAY_MAX_RETRIES', 2),

        // Days between retry attempts
        'retry_delay_days' => env('KOTAPAY_RETRY_DELAY', 3),
    ],

];
