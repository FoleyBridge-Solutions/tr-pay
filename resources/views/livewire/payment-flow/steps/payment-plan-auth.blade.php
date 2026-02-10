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
                    <li>You agree to pay the total amount of ${{ number_format($paymentAmount + $paymentPlanFee, 2) }} in {{ $planDuration }} monthly installments</li>
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
                <div class="space-y-4">
                    <flux:field>
                        <flux:label>Card Number</flux:label>
                        <flux:input wire:model.live="cardNumber" placeholder="1234 5678 9012 3456" maxlength="19" />
                        <flux:error name="cardNumber" />
                        <flux:description>Enter your 16-digit card number</flux:description>
                    </flux:field>

                    <div class="grid md:grid-cols-2 gap-4">
                        <flux:field>
                            <flux:label>Expiration Date</flux:label>
                            <flux:input wire:model.live="cardExpiry" placeholder="MM/YY" maxlength="5" />
                            <flux:error name="cardExpiry" />
                        </flux:field>

                        <flux:field>
                            <flux:label>CVV</flux:label>
                            <flux:input wire:model.live="cardCvv" type="password" placeholder="123" maxlength="4" />
                            <flux:error name="cardCvv" />
                        </flux:field>
                    </div>
                </div>
            @elseif($paymentMethod === 'ach')
                {{-- ACH Bank Account Form --}}
                <div class="space-y-4">
                    <flux:field>
                        <flux:label>Bank Name</flux:label>
                        <flux:input wire:model="bankName" placeholder="First National Bank" />
                        <flux:error name="bankName" />
                    </flux:field>

                    <div class="grid md:grid-cols-2 gap-4">
                        <flux:field>
                            <flux:label>Account Number</flux:label>
                            <flux:input wire:model="accountNumber" placeholder="123456789" maxlength="17" />
                            <flux:error name="accountNumber" />
                            <flux:description>8-17 digits</flux:description>
                        </flux:field>

                        <flux:field>
                            <flux:label>Routing Number</flux:label>
                            <flux:input wire:model="routingNumber" placeholder="123456789" maxlength="9" />
                            <flux:error name="routingNumber" />
                            <flux:description>9 digits</flux:description>
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

                    {{-- ACH Authorization for Payment Plans --}}
                    <div class="bg-zinc-50 dark:bg-zinc-800 border border-zinc-200 dark:border-zinc-700 rounded-lg p-4 mt-4">
                        <h4 class="text-sm font-semibold text-zinc-900 dark:text-zinc-100 mb-3">ACH Debit Authorization for Recurring Payments</h4>
                        <div class="text-xs text-zinc-600 dark:text-zinc-400 space-y-2">
                            <p>
                                By providing your bank account information and proceeding with this payment plan, you authorize 
                                <strong>{{ config('branding.company_name', 'our company') }}</strong> to electronically debit your 
                                {{ $bankAccountType === 'savings' ? 'savings' : 'checking' }} account at the financial institution 
                                indicated for the scheduled payment amounts on their respective due dates.
                            </p>
                            <p>
                                You also authorize {{ config('branding.company_name', 'our company') }}, if necessary, to electronically 
                                credit your account to correct erroneous debits or make payment of refunds or other related credits.
                            </p>
                            <p>
                                This authorization will remain in full force and effect until the payment plan is completed or until you notify 
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
                                    I authorize recurring ACH debits for this payment plan and agree to the terms above.
                                </span>
                            </label>
                            <flux:error name="achAuthorization" />
                        </div>
                    </div>
                </div>
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
