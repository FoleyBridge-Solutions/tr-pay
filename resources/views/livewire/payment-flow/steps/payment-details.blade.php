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
            <div class="space-y-4">
                <flux:field>
                    <flux:label>Card Number</flux:label>
                    <flux:input wire:model.live="cardNumber" placeholder="4111 1111 1111 1111" maxlength="19" required />
                    <flux:error name="cardNumber" />
                    <flux:description>Enter your 16-digit card number</flux:description>
                </flux:field>

                <div class="grid md:grid-cols-2 gap-4">
                    <flux:field>
                        <flux:label>Expiration Date</flux:label>
                        <flux:input wire:model.live="cardExpiry" placeholder="MM/YY" maxlength="5" required />
                        <flux:error name="cardExpiry" />
                        <flux:description>Format: MM/YY</flux:description>
                    </flux:field>

                    <flux:field>
                        <flux:label>CVV / Security Code</flux:label>
                        <flux:input wire:model.live="cardCvv" type="password" placeholder="123" maxlength="4" required />
                        <flux:error name="cardCvv" />
                        <flux:description>3 or 4 digits on back of card</flux:description>
                    </flux:field>
                </div>
            </div>
        @elseif($paymentMethod === 'ach')
            <div class="space-y-4">
                <flux:field>
                    <flux:label>Bank Name</flux:label>
                    <flux:input wire:model="bankName" placeholder="First National Bank" required />
                    <flux:error name="bankName" />
                </flux:field>

                <div class="grid md:grid-cols-2 gap-4">
                    <flux:field>
                        <flux:label>Routing Number</flux:label>
                        <flux:input wire:model="routingNumber" placeholder="123456789" maxlength="9" required />
                        <flux:error name="routingNumber" />
                        <flux:description>9 digits (bottom left of check)</flux:description>
                    </flux:field>

                    <flux:field>
                        <flux:label>Account Number</flux:label>
                        <flux:input wire:model="accountNumber" placeholder="123456789" maxlength="17" required />
                        <flux:error name="accountNumber" />
                        <flux:description>8-17 digits</flux:description>
                    </flux:field>
                </div>

                <div class="grid md:grid-cols-2 gap-4">
                    <flux:field>
                        <flux:label>Account Type</flux:label>
                        <flux:select wire:model="bankAccountType">
                            <option value="checking">Checking</option>
                            <option value="savings">Savings</option>
                        </flux:select>
                        <flux:error name="bankAccountType" />
                    </flux:field>

                    <flux:field>
                        <flux:label>Account Classification</flux:label>
                        <flux:select wire:model="isBusiness">
                            <option value="0">Personal Account</option>
                            <option value="1">Business Account</option>
                        </flux:select>
                        <flux:error name="isBusiness" />
                    </flux:field>
                </div>

                {{-- ACH Authorization --}}
                <div class="bg-zinc-50 dark:bg-zinc-800 border border-zinc-200 dark:border-zinc-700 rounded-lg p-4 mt-4">
                    <h4 class="text-sm font-semibold text-zinc-900 dark:text-zinc-100 mb-3">ACH Debit Authorization</h4>
                    <div class="text-xs text-zinc-600 dark:text-zinc-400 space-y-2">
                        <p>
                            By providing your bank account information and proceeding with this payment, you authorize 
                            <strong>{{ config('branding.company_name', 'our company') }}</strong> to electronically debit your 
                            {{ $bankAccountType === 'savings' ? 'savings' : 'checking' }} account at the financial institution 
                            indicated for the amount specified.
                        </p>
                        <p>
                            You also authorize {{ config('branding.company_name', 'our company') }}, if necessary, to electronically 
                            credit your account to correct erroneous debits or make payment of refunds or other related credits.
                        </p>
                        <p>
                            This authorization will remain in full force and effect until you notify 
                            {{ config('branding.company_name', 'our company') }} in writing that you wish to revoke this authorization.
                            {{ config('branding.company_name', 'our company') }} requires at least <strong>five (5) business days</strong> 
                            prior notice in order to cancel this authorization.
                        </p>
                    </div>
                    <div class="mt-3">
                        <label class="flex items-start gap-2 cursor-pointer">
                            <input 
                                type="checkbox" 
                                wire:model="achAuthorization" 
                                class="mt-0.5 rounded border-zinc-300 dark:border-zinc-600 text-zinc-800 dark:text-zinc-200 focus:ring-zinc-500"
                                required
                            >
                            <span class="text-xs text-zinc-700 dark:text-zinc-300">
                                I authorize this ACH debit and agree to the terms above.
                            </span>
                        </label>
                        <flux:error name="achAuthorization" />
                    </div>
                </div>
            </div>
        @endif

        {{-- Save Payment Method Option --}}
        <div class="bg-zinc-50 dark:bg-zinc-800 border border-zinc-200 dark:border-zinc-700 rounded-lg p-4">
            <label class="flex items-start gap-3 cursor-pointer">
                <input 
                    type="checkbox" 
                    wire:model.live="savePaymentMethod" 
                    class="mt-0.5 rounded border-zinc-300 dark:border-zinc-600 text-zinc-800 dark:text-zinc-200 focus:ring-zinc-500"
                >
                <div>
                    <span class="text-sm font-medium text-zinc-900 dark:text-zinc-100">
                        Save this payment method for future purchases
                    </span>
                    <p class="text-xs text-zinc-500 dark:text-zinc-400 mt-1">
                        Securely store your payment information for faster checkout next time.
                    </p>
                </div>
            </label>
            
            {{-- Nickname field (shown when save is checked) --}}
            @if($savePaymentMethod)
                <div class="mt-3 pl-7">
                    <flux:field>
                        <flux:label>Nickname (optional)</flux:label>
                        <flux:input 
                            wire:model="paymentMethodNickname" 
                            placeholder="{{ $paymentMethod === 'credit_card' ? 'e.g., Personal Card, Work Card' : 'e.g., Checking Account' }}"
                            maxlength="50"
                        />
                        <flux:description>Give this payment method a name for easy identification</flux:description>
                    </flux:field>
                </div>
            @endif
        </div>

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
