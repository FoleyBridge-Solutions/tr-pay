{{--
    Step: Verify Account
    
    User enters their identification information to verify their account.
--}}

<x-payment.step 
    name="verify-account"
    title="Verify Your Account"
    :subtitle="$accountType === 'business' ? 'Please enter your business information' : 'Please enter your personal information'"
    :show-next="false"
    :show-back="false"
>
    <form wire:submit.prevent="verifyAccount" class="space-y-6 max-w-md mx-auto">
        <flux:field>
            <flux:label>
                @if($accountType === 'business')
                    Last 4 Digits of EIN
                @else
                    Last 4 Digits of SSN
                @endif
            </flux:label>
            <flux:input 
                wire:model="last4" 
                placeholder="1234" 
                maxlength="4" 
                wire:loading.attr="disabled" 
                wire:target="verifyAccount" 
            />
            <flux:error name="last4" />
            <flux:description>
                @if($accountType === 'business')
                    Example: If your EIN is 12-3456789, enter 6789
                @else
                    Example: If your SSN is 123-45-6789, enter 6789
                @endif
            </flux:description>
        </flux:field>

        @if($accountType === 'business')
            <flux:field>
                <flux:label>Legal Business Name</flux:label>
                <flux:input 
                    wire:model="businessName" 
                    placeholder="Acme Corporation, LLC" 
                    wire:loading.attr="disabled" 
                    wire:target="verifyAccount" 
                />
                <flux:error name="businessName" />
                <flux:description>Enter exactly as shown on tax documents</flux:description>
            </flux:field>
        @else
            <flux:field>
                <flux:label>Last Name</flux:label>
                <flux:input 
                    wire:model="lastName" 
                    placeholder="Smith" 
                    wire:loading.attr="disabled" 
                    wire:target="verifyAccount" 
                />
                <flux:error name="lastName" />
                <flux:description>Enter as shown on your account</flux:description>
            </flux:field>
        @endif

        {{-- Custom footer for this step --}}
        <div class="flex gap-3 pt-4">
            <flux:button 
                variant="ghost" 
                wire:click="goToPrevious"
                wire:loading.attr="disabled" 
                wire:target="verifyAccount"
            >
                <svg class="w-4 h-4 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"></path>
                </svg>
                Back
            </flux:button>
            <flux:button 
                type="submit"
                variant="primary"
                class="flex-1"
                wire:loading.attr="disabled" 
                wire:target="verifyAccount"
            >
                <span wire:loading.remove wire:target="verifyAccount">Continue</span>
                <span wire:loading wire:target="verifyAccount" class="flex items-center gap-2">
                    <svg class="animate-spin h-4 w-4" fill="none" viewBox="0 0 24 24">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                    </svg>
                    Verifying...
                </span>
            </flux:button>
        </div>
    </form>
</x-payment.step>
