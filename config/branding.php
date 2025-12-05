<?php

// config/branding.php

return [

    /*
    |--------------------------------------------------------------------------
    | Company Information
    |--------------------------------------------------------------------------
    |
    | Configure your company's branding and contact information here.
    | These values will be used throughout the application including
    | payment forms, emails, and authorization text.
    |
    */

    'company_name' => env('COMPANY_NAME', 'Your Company'),

    'company_legal_name' => env('COMPANY_LEGAL_NAME', env('COMPANY_NAME', 'Your Company')),

    'support_email' => env('SUPPORT_EMAIL', 'support@example.com'),

    'support_phone' => env('SUPPORT_PHONE', '1-800-555-0100'),

    'website_url' => env('COMPANY_WEBSITE', 'https://example.com'),

    /*
    |--------------------------------------------------------------------------
    | Payment Notification Emails
    |--------------------------------------------------------------------------
    |
    | Email addresses that should receive payment notifications.
    |
    */

    'payment_notification_email' => env('PAYMENT_NOTIFICATION_EMAIL', env('SUPPORT_EMAIL', 'payments@example.com')),

    'accounting_email' => env('ACCOUNTING_EMAIL', env('SUPPORT_EMAIL', 'accounting@example.com')),

];
