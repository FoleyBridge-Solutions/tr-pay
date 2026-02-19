{{-- Payment Details Step (One-Time Payments) --}}
{{-- resources/views/livewire/payment-flow/steps/payment-details.blade.php --}}

<x-payment.step 
    name="payment-details"
    title="Enter Payment Details"
    subtitle="Securely provide your payment information"
    :show-next="false"
    :show-back="false"
>
    {{-- Payment Summary --}}
    <div class="mb-6 bg-zinc-50 dark:bg-zinc-800 border border-zinc-200 dark:border-zinc-700 rounded-lg p-4">
        <div class="flex justify-between items-center">
            <div>
                <div class="text-sm text-zinc-700 dark:text-zinc-300 font-medium">Payment Amount:</div>
                <div class="text-2xl font-bold text-zinc-900 dark:text-zinc-100">${{ number_format($paymentAmount, 2) }}</div>
            </div>
            @if($paymentMethod === 'credit_card' && $creditCardFee > 0)
                <div class="text-right">
                    <div class="text-sm text-zinc-700 dark:text-zinc-300">Non-Cash Adjustment ({{ config("payment-fees.credit_card_rate") * 100 }}%):</div>
                    <div class="text-lg font-semibold text-zinc-900 dark:text-zinc-100">+${{ number_format($creditCardFee, 2) }}</div>
                    <div class="text-xs text-zinc-800 dark:text-zinc-200 mt-1">Total: ${{ number_format($paymentAmount + $creditCardFee, 2) }}</div>
                </div>
            @endif
        </div>
    </div>

    {{-- Error Display --}}
    @if($errors->has('payment'))
        <div class="mb-6 bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 rounded-lg p-4">
            <div class="flex items-start gap-3">
                <svg class="w-6 h-6 text-red-600 dark:text-red-400 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                </svg>
                <div class="flex-1">
                    <h3 class="text-sm font-semibold text-red-800 dark:text-red-200 mb-1">Payment Error</h3>
                    <p class="text-sm text-red-700 dark:text-red-300">{{ $errors->first('payment') }}</p>
                </div>
            </div>
        </div>
    @endif

    <form wire:submit.prevent="confirmPayment" class="space-y-6">
        @if($paymentMethod === 'credit_card')
            <x-payment-method-fields
                type="card"
                :required="true"
                :show-descriptions="true"
            />
        @elseif($paymentMethod === 'ach')
            <x-payment-method-fields
                type="ach"
                :required="true"
                :show-descriptions="true"
                :show-bank-name="true"
                :show-ach-auth="true"
                :show-is-business="true"
                :account-type-value="$bankAccountType"
            />
        @endif

        {{-- Security Notice --}}
        <div class="bg-zinc-50 dark:bg-zinc-800 border border-zinc-200 dark:border-zinc-700 rounded-lg p-4 text-sm text-zinc-700 dark:text-zinc-300">
            <div class="flex items-start gap-2">
                <svg class="w-5 h-5 text-green-600 dark:text-green-400 flex-shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"></path>
                </svg>
                <div>
                    <strong>Secure Payment:</strong> Your payment information is encrypted and securely transmitted through MiPaymentChoice gateway. We never store your full card number or CVV.
                </div>
            </div>
        </div>

        {{-- Action Buttons --}}
        <div class="flex gap-3">
            <button wire:click="goBack" type="button" class="px-4 py-2 text-sm font-medium text-zinc-700 dark:text-zinc-300 hover:text-zinc-900 dark:hover:text-zinc-100">
                Back
            </button>
            <button type="submit" class="flex-1 px-4 py-2 bg-zinc-800 hover:bg-zinc-900 dark:bg-zinc-200 dark:hover:bg-zinc-100 text-white dark:text-zinc-900 font-medium rounded-lg flex items-center justify-center gap-2" wire:loading.attr="disabled" wire:target="confirmPayment">
                <span wire:loading.remove wire:target="confirmPayment">
                    Process Payment (${{ number_format($paymentMethod === 'credit_card' ? $paymentAmount + $creditCardFee : $paymentAmount, 2) }})
                </span>
                <span wire:loading wire:target="confirmPayment" class="flex items-center gap-2">
                    <svg class="animate-spin h-5 w-5" fill="none" viewBox="0 0 24 24">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                    </svg>
                    Processing Payment...
                </span>
            </button>
        </div>
    </form>
</x-payment.step>
