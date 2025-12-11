<?php

// app/Livewire/PaymentFlow/Steps.php

namespace App\Livewire\PaymentFlow;

/**
 * Payment Flow Step Definitions
 * 
 * Named constants for all steps in the payment flow.
 * Each step has a unique string identifier for clarity and maintainability.
 */
class Steps
{
    // Main flow steps
    public const ACCOUNT_TYPE = 'account-type';
    public const VERIFY_ACCOUNT = 'verify-account';
    public const PROJECT_ACCEPTANCE = 'project-acceptance';
    public const INVOICE_SELECTION = 'invoice-selection';
    public const PAYMENT_METHOD = 'payment-method';
    public const PAYMENT_DETAILS = 'payment-details';
    public const PAYMENT_PLAN_AUTH = 'payment-plan-auth';
    public const CONFIRMATION = 'confirmation';
    
    // Loading/skeleton steps (shown between transitions)
    public const LOADING_VERIFICATION = 'loading-verification';
    public const LOADING_INVOICES = 'loading-invoices';
    public const LOADING_PAYMENT = 'loading-payment';
    public const PROCESSING_PAYMENT = 'processing-payment';

    /**
     * Get step metadata (title, description, icon, etc.)
     */
    public static function getMeta(string $step): array
    {
        return match($step) {
            self::ACCOUNT_TYPE => [
                'title' => 'Account Type',
                'description' => 'Select your account type',
                'icon' => 'user-circle',
                'showInProgress' => true,
            ],
            self::VERIFY_ACCOUNT => [
                'title' => 'Verify Account',
                'description' => 'Verify your identity',
                'icon' => 'shield-check',
                'showInProgress' => true,
            ],
            self::PROJECT_ACCEPTANCE => [
                'title' => 'Project Acceptance',
                'description' => 'Review and accept projects',
                'icon' => 'document-check',
                'showInProgress' => true,
            ],
            self::INVOICE_SELECTION => [
                'title' => 'Select Invoices',
                'description' => 'Choose invoices to pay',
                'icon' => 'document-text',
                'showInProgress' => true,
            ],
            self::PAYMENT_METHOD => [
                'title' => 'Payment Method',
                'description' => 'Choose how to pay',
                'icon' => 'credit-card',
                'showInProgress' => true,
            ],
            self::PAYMENT_DETAILS => [
                'title' => 'Payment Details',
                'description' => 'Enter payment information',
                'icon' => 'lock-closed',
                'showInProgress' => true,
            ],
            self::PAYMENT_PLAN_AUTH => [
                'title' => 'Plan Authorization',
                'description' => 'Authorize payment plan',
                'icon' => 'calendar',
                'showInProgress' => true,
            ],
            self::CONFIRMATION => [
                'title' => 'Confirmation',
                'description' => 'Payment complete',
                'icon' => 'check-circle',
                'showInProgress' => true,
            ],
            // Loading steps (not shown in progress indicator)
            self::LOADING_VERIFICATION => [
                'title' => 'Verifying...',
                'description' => 'Verifying your account',
                'icon' => 'arrow-path',
                'showInProgress' => false,
                'isLoading' => true,
            ],
            self::LOADING_INVOICES => [
                'title' => 'Loading...',
                'description' => 'Loading your invoices',
                'icon' => 'arrow-path',
                'showInProgress' => false,
                'isLoading' => true,
            ],
            self::LOADING_PAYMENT => [
                'title' => 'Loading...',
                'description' => 'Preparing payment options',
                'icon' => 'arrow-path',
                'showInProgress' => false,
                'isLoading' => true,
            ],
            self::PROCESSING_PAYMENT => [
                'title' => 'Processing...',
                'description' => 'Processing your payment',
                'icon' => 'arrow-path',
                'showInProgress' => false,
                'isLoading' => true,
            ],
            default => [
                'title' => 'Unknown',
                'description' => '',
                'icon' => 'question-mark-circle',
                'showInProgress' => false,
            ],
        };
    }

    /**
     * Get all steps that should show in progress indicator
     */
    public static function getProgressSteps(): array
    {
        return [
            self::ACCOUNT_TYPE,
            self::VERIFY_ACCOUNT,
            self::INVOICE_SELECTION,
            self::PAYMENT_METHOD,
            self::PAYMENT_DETAILS,
            self::CONFIRMATION,
        ];
    }

    /**
     * Check if step is a loading step
     */
    public static function isLoadingStep(string $step): bool
    {
        return in_array($step, [
            self::LOADING_VERIFICATION,
            self::LOADING_INVOICES,
            self::LOADING_PAYMENT,
            self::PROCESSING_PAYMENT,
        ]);
    }
}
