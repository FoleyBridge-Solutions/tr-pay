<?php

return [
    
    /*
    |--------------------------------------------------------------------------
    | PracticeCS Payment Integration
    |--------------------------------------------------------------------------
    |
    | Configuration for writing payment data back to PracticeCS SQL Server
    |
    */

    'payment_integration' => [
        
        // Master switch - must be explicitly enabled
        'enabled' => env('PRACTICECS_WRITE_ENABLED', false),
        
        // Database connection to use (always 'sqlsrv')
        // Currently points to CSP_345844_TestDoNotUse for safe development
        'connection' => env('PRACTICECS_CONNECTION', 'sqlsrv'),
        
        // Staff KEY to use for automated payment entries
        // Default: 1552 (ADMINSA)
        // Recommendation: Create dedicated "ONLINE PAYMENTS" staff account
        'staff_key' => env('PRACTICECS_STAFF_KEY', 1552),
        
        // Bank account KEY for online payments
        // Must match actual bank account in PracticeCS
        'bank_account_key' => env('PRACTICECS_BANK_ACCOUNT_KEY', 2),
        
        // Ledger entry type mappings
        'ledger_types' => [
            'credit_card' => 9,  // Credit Card
            'ach' => 11,         // Electronic Funds Transfer
            'check' => 8,        // Cash (for online check payments)
            'cash' => 8,         // Cash
        ],
        
        // Default subtype for payments
        // Type 8 (Cash) -> Subtype 9 (Cash)
        // Type 9 (Credit Card) -> Subtype 10 (Credit Card)
        // Type 11 (EFT) -> Subtype 12 (EFT)
        'payment_subtypes' => [
            'credit_card' => 10,
            'ach' => 12,
            'check' => 9,
            'cash' => 9,
        ],
        
        // Whether to auto-post payments (vs manual approval required)
        // true = payment is immediately posted and approved
        // false = payment requires manual staff approval in PracticeCS
        'auto_post' => env('PRACTICECS_AUTO_POST', true),
        
        // Whether to track in Online_Payment table
        'track_online_payments' => env('PRACTICECS_TRACK_ONLINE', false),
        
        // Memo types for client group payment distribution
        'memo_types' => [
            'debit' => 3,   // Debit Memo - used when primary client overpays (owes to group)
            'credit' => 5,  // Credit Memo - used to transfer payment to other group clients
        ],
        
        // Memo subtypes
        'memo_subtypes' => [
            'debit' => 4,   // Debit Memo subtype
            'credit' => 6,  // Credit Memo subtype
        ],
        
    ],

];
