<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Kotapay ACH Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for Kotapay ACH file generation and API submission.
    | Kotapay is a division of First International Bank & Trust.
    |
    */

    'environment' => env('KOTAPAY_ENV', 'sandbox'),

    /*
    |--------------------------------------------------------------------------
    | API Configuration (Recommended)
    |--------------------------------------------------------------------------
    |
    | Kotapay API credentials for ACH payments and file upload.
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

    'api' => [
        'enabled' => env('KOTAPAY_API_ENABLED', true),

        // OAuth2 credentials (provided by Kotapay)
        'client_id' => env('KOTAPAY_API_CLIENT_ID'),
        'client_secret' => env('KOTAPAY_API_CLIENT_SECRET'),
        'username' => env('KOTAPAY_API_USERNAME'),
        'password' => env('KOTAPAY_API_PASSWORD'),

        // Company ID for API requests (provided by Kotapay)
        'company_id' => env('KOTAPAY_API_COMPANY_ID'),
    ],

    /*
    |--------------------------------------------------------------------------
    | SFTP Connection Settings (Legacy)
    |--------------------------------------------------------------------------
    |
    | Kotapay also accepts ACH files via SFTP. Configure your
    | connection details here. These will be provided by Kotapay.
    |
    */

    'sftp' => [
        'host' => env('KOTAPAY_SFTP_HOST', 'sftp.kotapay.com'),
        'port' => env('KOTAPAY_SFTP_PORT', 22),
        'username' => env('KOTAPAY_SFTP_USER'),
        'password' => env('KOTAPAY_SFTP_PASS'),
        'private_key' => env('KOTAPAY_SFTP_KEY_PATH'),
        'private_key_passphrase' => env('KOTAPAY_SFTP_KEY_PASS'),

        // Remote directories
        'upload_path' => env('KOTAPAY_SFTP_UPLOAD_PATH', '/inbound'),
        'download_path' => env('KOTAPAY_SFTP_DOWNLOAD_PATH', '/outbound'),
        'returns_path' => env('KOTAPAY_SFTP_RETURNS_PATH', '/returns'),
    ],

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
    | File Storage
    |--------------------------------------------------------------------------
    |
    | Where to store generated NACHA files locally.
    |
    */

    'storage' => [
        'disk' => env('KOTAPAY_STORAGE_DISK', 'local'),
        'path' => env('KOTAPAY_STORAGE_PATH', 'ach-files'),
        'archive_path' => env('KOTAPAY_ARCHIVE_PATH', 'ach-files/archive'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Notifications
    |--------------------------------------------------------------------------
    */

    'notifications' => [
        // Email to notify on file submission
        'submission_email' => env('KOTAPAY_NOTIFICATION_EMAIL'),

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
