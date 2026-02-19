{{-- Payment Plan Authorization Step --}}
{{-- resources/views/livewire/payment-flow/steps/payment-plan-auth.blade.php --}}

<x-payment.step 
    name="payment-plan-auth"
    title="Authorize Payment Plan"
    subtitle="Review terms and provide payment method for your installment plan"
    :show-next="false"
    :show-back="false"
>
    {{-- Payment Plan Summary --}}
    <div class="bg-zinc-50 dark:bg-zinc-900 p-4 rounded-lg mb-6">
        <flux:heading size="lg" class="mb-3">Payment Plan Summary</flux:heading>
        <div class="grid md:grid-cols-2 gap-4 text-sm">
            <div>
                <flux:subheading>Invoice Amount:</flux:subheading>
                <flux:text class="font-bold">${{ number_format($paymentAmount, 2) }}</flux:text>
            </div>
            @if($creditCardFee > 0)
            <div>
                <flux:subheading>Non-Cash Adjustment:</flux:subheading>
                <flux:text class="font-bold">${{ number_format($creditCardFee, 2) }}</flux:text>
            </div>
            @endif
            @if($paymentPlanFee > 0)
            <div>
                <flux:subheading>Plan Fee:</flux:subheading>
                <flux:text class="font-bold">${{ number_format($paymentPlanFee, 2) }}</flux:text>
            </div>
            @endif
            <div>
                <flux:subheading>Total Obligation:</flux:subheading>
                <flux:text class="font-bold text-zinc-800 dark:text-zinc-200">${{ number_format($paymentAmount + $creditCardFee + $paymentPlanFee, 2) }}</flux:text>
            </div>
            <div>
                <flux:subheading>Installments:</flux:subheading>
                <flux:text>{{ $planDuration }} payments</flux:text>
            </div>
            <div>
                <flux:subheading>Frequency:</flux:subheading>
                <flux:text class="capitalize">Monthly</flux:text>
            </div>
        </div>
    </div>

    <form wire:submit.prevent="authorizePaymentPlan" class="space-y-6">
        {{-- Terms and Conditions --}}
        <div class="border border-zinc-300 dark:border-zinc-600 rounded-lg p-6">
            <flux:heading size="lg" class="mb-4">Terms and Conditions</flux:heading>

            <div class="prose prose-sm max-w-none text-zinc-700 dark:text-zinc-300 mb-6">
                <p class="mb-3"><strong>Payment Plan Agreement:</strong></p>
                <ul class="list-disc pl-5 space-y-1 mb-4">
                    <li>You agree to pay the total amount of ${{ number_format($paymentAmount + $creditCardFee + $paymentPlanFee, 2) }} in {{ $planDuration }} monthly installments</li>
                    <li>Payments will be automatically charged to your selected payment method on the due dates</li>
                    <li>Late payments may incur additional fees and interest charges</li>
                    <li>You may cancel this payment plan at any time, but all outstanding amounts become due immediately</li>
                </ul>

                <p class="mb-3"><strong>Authorization:</strong></p>
                <p>By agreeing to these terms, you authorize {{ config('branding.company_name', 'our company') }} to charge your payment method for all scheduled payments. You understand that this authorization will remain in effect until the payment plan is completed or cancelled.</p>
            </div>

            <flux:field>
                <flux:checkbox wire:model="agreeToTerms" label="I agree to the terms and conditions above and authorize the automatic charges to my payment method." />
                <flux:error name="agreeToTerms" />
            </flux:field>
        </div>

        {{-- Payment Method Collection --}}
        <div class="border border-zinc-300 dark:border-zinc-600 rounded-lg p-6">
            <flux:heading size="lg" class="mb-4">Payment Method</flux:heading>
            <flux:subheading class="mb-4">Securely store your payment method for automatic billing</flux:subheading>

            {{-- Payment Method Selection --}}
            <div class="grid md:grid-cols-2 gap-6 mb-6">
                <button
                    wire:click="$set('paymentMethod', 'credit_card')"
                    type="button"
                    class="h-32 flex flex-col items-center justify-center gap-3 rounded-lg border-2 transition-colors {{ $paymentMethod === 'credit_card' ? 'border-zinc-500 bg-zinc-50 dark:bg-zinc-800' : 'border-zinc-300 dark:border-zinc-600 hover:border-zinc-500 bg-white dark:bg-zinc-900 hover:bg-zinc-50 dark:hover:bg-zinc-800' }}"
                >
                    <svg class="w-10 h-10 text-zinc-800 dark:text-zinc-200" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 10h18M7 15h1m4 0h1m-7 4h12a3 3 0 003-3V8a3 3 0 00-3-3H6a3 3 0 00-3 3v8a3 3 0 003 3z"></path>
                    </svg>
                    <div class="text-center">
                        <div class="text-lg font-semibold text-zinc-900 dark:text-zinc-100">Credit Card</div>
                        <div class="text-sm text-zinc-600 dark:text-zinc-400">Visa, MasterCard, Amex</div>
                    </div>
                </button>

                <button
                    wire:click="$set('paymentMethod', 'ach')"
                    type="button"
                    class="h-32 flex flex-col items-center justify-center gap-3 rounded-lg border-2 transition-colors {{ $paymentMethod === 'ach' ? 'border-zinc-500 bg-zinc-50 dark:bg-zinc-800' : 'border-zinc-300 dark:border-zinc-600 hover:border-zinc-500 bg-white dark:bg-zinc-900 hover:bg-zinc-50 dark:hover:bg-zinc-800' }}"
                >
                    <svg class="w-10 h-10 text-zinc-800 dark:text-zinc-200" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 14v3m4-3v3m4-3v3M3 21h18M3 10h18M3 7l9-4 9 4M4 10h16v11H4V10z"></path>
                    </svg>
                    <div class="text-center">
                        <div class="text-lg font-semibold text-zinc-900 dark:text-zinc-100">Bank Account</div>
                        <div class="text-sm text-zinc-600 dark:text-zinc-400">ACH Transfer</div>
                    </div>
                </button>
            </div>

            {{-- Credit Card Form --}}
            @if($paymentMethod === 'credit_card')
                <x-payment-method-fields
                    type="card"
                    :show-descriptions="true"
                />
            @elseif($paymentMethod === 'ach')
                {{-- ACH Bank Account Form --}}
                <x-payment-method-fields
                    type="ach"
                    :show-bank-name="true"
                    :show-descriptions="true"
                    :show-ach-auth="true"
                    :show-is-business="true"
                    :account-type-value="$bankAccountType"
                    ach-auth-title="ACH Debit Authorization for Recurring Payments"
                    ach-auth-text="I authorize recurring ACH debits for this payment plan and agree to the terms above."
                />
            @endif
        </div>

        {{-- Action Buttons --}}
        <div class="flex gap-3">
            <button wire:click="changePaymentMethod" type="button" class="px-4 py-2 bg-zinc-500 hover:bg-zinc-600 text-white font-medium rounded-lg">
                Change Method
            </button>
            <button wire:click="editPaymentPlan" type="button" class="px-4 py-2 bg-orange-600 hover:bg-orange-700 text-white font-medium rounded-lg flex items-center gap-2">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"></path>
                </svg>
                Edit Plan
            </button>
            <button type="submit" class="flex-1 px-4 py-2 bg-zinc-800 hover:bg-zinc-900 dark:bg-zinc-200 dark:hover:bg-zinc-100 text-white dark:text-zinc-900 font-medium rounded-lg flex items-center justify-center gap-2" wire:loading.attr="disabled" wire:target="authorizePaymentPlan">
                <span wire:loading.remove wire:target="authorizePaymentPlan">Authorize & Continue</span>
                <span wire:loading wire:target="authorizePaymentPlan" class="flex items-center gap-2">
                    <svg class="animate-spin h-5 w-5" fill="none" viewBox="0 0 24 24">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                    </svg>
                    Processing...
                </span>
            </button>
        </div>
    </form>
</x-payment.step>
