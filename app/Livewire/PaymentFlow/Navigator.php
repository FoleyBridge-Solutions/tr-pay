<?php

// app/Livewire/PaymentFlow/Navigator.php

namespace App\Livewire\PaymentFlow;

/**
 * Payment Flow Navigator
 * 
 * Handles step transitions, history tracking, and navigation logic.
 */
class Navigator
{
    /**
     * Get the next step based on current step and context
     */
    public static function getNextStep(string $currentStep, array $context = []): ?string
    {
        return match($currentStep) {
            Steps::ACCOUNT_TYPE => Steps::LOADING_VERIFICATION,
            Steps::LOADING_VERIFICATION => Steps::VERIFY_ACCOUNT,
            Steps::VERIFY_ACCOUNT => self::getNextAfterVerification($context),
            Steps::PROJECT_ACCEPTANCE => self::getNextAfterProjectAcceptance($context),
            Steps::LOADING_INVOICES => Steps::INVOICE_SELECTION,
            Steps::INVOICE_SELECTION => Steps::LOADING_PAYMENT,
            Steps::LOADING_PAYMENT => Steps::PAYMENT_METHOD,
            Steps::PAYMENT_METHOD => self::getNextAfterPaymentMethod($context),
            Steps::PAYMENT_DETAILS => Steps::PROCESSING_PAYMENT,
            Steps::PAYMENT_PLAN_AUTH => Steps::PROCESSING_PAYMENT,
            Steps::PROCESSING_PAYMENT => Steps::CONFIRMATION,
            Steps::CONFIRMATION => null, // End of flow
            default => null,
        };
    }

    /**
     * Get the previous step based on current step
     * Uses history stack in the component, but this provides defaults
     */
    public static function getPreviousStep(string $currentStep, array $context = []): ?string
    {
        return match($currentStep) {
            Steps::ACCOUNT_TYPE => null, // First step
            Steps::LOADING_VERIFICATION => Steps::ACCOUNT_TYPE,
            Steps::VERIFY_ACCOUNT => Steps::ACCOUNT_TYPE,
            Steps::PROJECT_ACCEPTANCE => Steps::VERIFY_ACCOUNT,
            Steps::LOADING_INVOICES => self::getPreviousBeforeInvoices($context),
            Steps::INVOICE_SELECTION => self::getPreviousBeforeInvoices($context),
            Steps::LOADING_PAYMENT => Steps::INVOICE_SELECTION,
            Steps::PAYMENT_METHOD => Steps::INVOICE_SELECTION,
            Steps::PAYMENT_DETAILS => Steps::PAYMENT_METHOD,
            Steps::PAYMENT_PLAN_AUTH => Steps::PAYMENT_METHOD,
            Steps::PROCESSING_PAYMENT => self::getPreviousBeforeProcessing($context),
            Steps::CONFIRMATION => null, // Can't go back from confirmation
            default => null,
        };
    }

    /**
     * Determine next step after account verification
     */
    protected static function getNextAfterVerification(array $context): string
    {
        // If there are projects to accept, go there first
        if (!empty($context['hasProjectsToAccept'])) {
            return Steps::PROJECT_ACCEPTANCE;
        }
        
        // Otherwise, load invoices
        return Steps::LOADING_INVOICES;
    }

    /**
     * Determine next step after project acceptance
     */
    protected static function getNextAfterProjectAcceptance(array $context): string
    {
        // Check if there are more projects to accept
        $currentIndex = $context['currentProjectIndex'] ?? 0;
        $totalProjects = $context['totalProjects'] ?? 0;
        
        if ($currentIndex < $totalProjects - 1) {
            // More projects to accept - stay on same step (handled by component)
            return Steps::PROJECT_ACCEPTANCE;
        }
        
        // All projects accepted, load invoices
        return Steps::LOADING_INVOICES;
    }

    /**
     * Determine next step after payment method selection
     */
    protected static function getNextAfterPaymentMethod(array $context): string
    {
        if (!empty($context['isPaymentPlan'])) {
            return Steps::PAYMENT_PLAN_AUTH;
        }
        
        return Steps::PAYMENT_DETAILS;
    }

    /**
     * Determine previous step before invoice selection
     */
    protected static function getPreviousBeforeInvoices(array $context): string
    {
        if (!empty($context['hasProjectsToAccept'])) {
            return Steps::PROJECT_ACCEPTANCE;
        }
        
        return Steps::VERIFY_ACCOUNT;
    }

    /**
     * Determine previous step before payment processing
     */
    protected static function getPreviousBeforeProcessing(array $context): string
    {
        if (!empty($context['isPaymentPlan'])) {
            return Steps::PAYMENT_PLAN_AUTH;
        }
        
        return Steps::PAYMENT_DETAILS;
    }

    /**
     * Check if user can navigate to a step
     */
    public static function canNavigateTo(string $targetStep, string $currentStep, array $context = []): bool
    {
        // Can always go back (if there's a previous step)
        $previous = self::getPreviousStep($currentStep, $context);
        if ($targetStep === $previous) {
            return true;
        }
        
        // Can go forward if validation passes (handled by component)
        $next = self::getNextStep($currentStep, $context);
        if ($targetStep === $next) {
            return true;
        }
        
        return false;
    }

    /**
     * Get the skeleton type to show for a loading step
     */
    public static function getSkeletonType(string $loadingStep): string
    {
        return match($loadingStep) {
            Steps::LOADING_VERIFICATION => 'form',
            Steps::LOADING_INVOICES => 'table',
            Steps::LOADING_PAYMENT => 'cards',
            Steps::PROCESSING_PAYMENT => 'processing',
            default => 'generic',
        };
    }

    /**
     * Get the duration for a loading step (in milliseconds)
     */
    public static function getLoadingDuration(string $loadingStep): int
    {
        return match($loadingStep) {
            Steps::LOADING_VERIFICATION => 600,
            Steps::LOADING_INVOICES => 1000,
            Steps::LOADING_PAYMENT => 600,
            Steps::PROCESSING_PAYMENT => 0, // Wait for actual processing
            default => 800,
        };
    }

    /**
     * Get the progress percentage for a step
     */
    public static function getProgressPercentage(string $currentStep): int
    {
        $progressSteps = Steps::getProgressSteps();
        
        // Find the associated progress step for loading steps
        $effectiveStep = match($currentStep) {
            Steps::LOADING_VERIFICATION => Steps::VERIFY_ACCOUNT,
            Steps::LOADING_INVOICES => Steps::INVOICE_SELECTION,
            Steps::LOADING_PAYMENT => Steps::PAYMENT_METHOD,
            Steps::PROCESSING_PAYMENT => Steps::CONFIRMATION,
            default => $currentStep,
        };
        
        $index = array_search($effectiveStep, $progressSteps);
        
        if ($index === false) {
            return 0;
        }
        
        return (int) round(($index + 1) / count($progressSteps) * 100);
    }

    /**
     * Get the current progress step index (0-based)
     */
    public static function getCurrentProgressIndex(string $currentStep): int
    {
        $progressSteps = Steps::getProgressSteps();
        
        // Map loading steps to their associated progress step
        $effectiveStep = match($currentStep) {
            Steps::LOADING_VERIFICATION => Steps::VERIFY_ACCOUNT,
            Steps::LOADING_INVOICES => Steps::INVOICE_SELECTION,
            Steps::LOADING_PAYMENT => Steps::PAYMENT_METHOD,
            Steps::PROCESSING_PAYMENT => Steps::CONFIRMATION,
            Steps::PROJECT_ACCEPTANCE => Steps::VERIFY_ACCOUNT, // Part of verification flow
            Steps::PAYMENT_PLAN_AUTH => Steps::PAYMENT_DETAILS, // Part of payment details
            default => $currentStep,
        };
        
        $index = array_search($effectiveStep, $progressSteps);
        
        return $index !== false ? $index : 0;
    }
}
