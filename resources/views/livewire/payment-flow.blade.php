{{--
    Payment Flow - Main View
    
    Uses named steps and skeleton loading between ALL step transitions.
    Each step is in its own file for maintainability.
--}}

@php
    use App\Livewire\PaymentFlow\Steps;
    use App\Livewire\PaymentFlow\Navigator;
@endphp

<div class="max-w-4xl mx-auto py-8 px-4">
    {{-- Progress Indicator --}}
    <x-payment.progress :currentStep="$currentStep" />

    {{-- Step Content Container --}}
    <div class="space-y-6">
        
        {{-- Account Type Selection - REMOVED: Always default to personal --}}
        {{-- @if($currentStep === Steps::ACCOUNT_TYPE)
            @include('livewire.payment-flow.steps.account-type')
        @endif --}}

        {{-- Account Verification --}}
        @if($currentStep === Steps::VERIFY_ACCOUNT)
            @include('livewire.payment-flow.steps.verify-account')
        @endif

        {{-- Project Acceptance (conditional) --}}
        @if($currentStep === Steps::PROJECT_ACCEPTANCE && $hasProjectsToAccept && isset($pendingProjects[$currentProjectIndex]))
            @include('livewire.payment-flow.steps.project-acceptance')
        @endif

        {{-- Loading: Invoices Skeleton --}}
        @if($currentStep === Steps::LOADING_INVOICES)
            <x-payment.skeleton 
                type="table"
                title="Loading Your Invoices"
                subtitle="Please wait while we retrieve your account information"
                :duration="1000"
                onComplete="onSkeletonComplete"
            />
        @endif

        {{-- Invoice Selection --}}
        @if($currentStep === Steps::INVOICE_SELECTION)
            @include('livewire.payment-flow.steps.invoice-selection')
        @endif

        {{-- Loading: Payment Options Skeleton --}}
        @if($currentStep === Steps::LOADING_PAYMENT)
            <x-payment.skeleton 
                type="cards"
                title="Preparing Payment Options"
                subtitle="Please wait while we load available payment methods"
                :duration="600"
                onComplete="onSkeletonComplete"
            />
        @endif

        {{-- Payment Method Selection --}}
        @if($currentStep === Steps::PAYMENT_METHOD)
            @include('livewire.payment-flow.steps.payment-method')
        @endif

        {{-- Payment Details (One-Time Payments) --}}
        @if($currentStep === Steps::PAYMENT_DETAILS && !$isPaymentPlan)
            @include('livewire.payment-flow.steps.payment-details')
        @endif

        {{-- Payment Plan Authorization --}}
        @if($currentStep === Steps::PAYMENT_PLAN_AUTH || ($currentStep === Steps::PAYMENT_DETAILS && $isPaymentPlan))
            @include('livewire.payment-flow.steps.payment-plan-auth')
        @endif

        {{-- Processing Payment Skeleton --}}
        @if($currentStep === Steps::PROCESSING_PAYMENT)
            <x-payment.skeleton 
                type="processing"
                title="Processing Your Payment"
                subtitle="Please wait while we securely process your payment"
                :duration="0"
            />
        @endif

        {{-- Confirmation --}}
        @if($currentStep === Steps::CONFIRMATION)
            @include('livewire.payment-flow.steps.confirmation')
        @endif

    </div>
</div>
