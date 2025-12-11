{{--
    Step: Payment Method Selection
    
    User selects their payment method (credit card, ACH, check, or payment plan).
--}}

<x-payment.step 
    name="payment-method"
    title=""
    :show-next="false"
    :show-back="false"
>
    @if(!$isPaymentPlan)
        {{-- Payment Method Selection --}}
        <flux:heading size="xl" class="text-center mb-2">Select Payment Method</flux:heading>
        <div class="text-center mb-8">
            <flux:subheading>Payment Amount:</flux:subheading>
            <flux:heading size="2xl" class="text-zinc-800">${{ number_format($paymentAmount, 2) }}</flux:heading>
        </div>

        @error('payment_method')
            <div class="mb-4 p-4 bg-red-50 border border-red-200 rounded-lg text-red-700">
                {{ $message }}
            </div>
        @enderror

        <div class="grid md:grid-cols-2 gap-6 mb-6">
            <button wire:click="selectPaymentMethod('credit_card')" type="button" class="h-40 flex flex-col items-center justify-center gap-3 rounded-lg border-2 border-zinc-300 hover:border-zinc-500 bg-white hover:bg-zinc-50 transition-all hover:scale-[1.02] active:scale-[0.98]">
                <svg class="w-12 h-12 text-zinc-800" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 10h18M7 15h1m4 0h1m-7 4h12a3 3 0 003-3V8a3 3 0 00-3-3H6a3 3 0 00-3 3v8a3 3 0 003 3z"></path>
                </svg>
                <div>
                    <div class="text-lg font-semibold text-zinc-900">Credit Card</div>
                    <div class="text-sm text-zinc-600">{{ config("payment-fees.credit_card_rate") * 100 }}% fee applies</div>
                </div>
            </button>

            <button wire:click="selectPaymentMethod('ach')" type="button" class="h-40 flex flex-col items-center justify-center gap-3 rounded-lg border-2 border-zinc-300 hover:border-zinc-500 bg-white hover:bg-zinc-50 transition-all hover:scale-[1.02] active:scale-[0.98]">
                <svg class="w-12 h-12 text-zinc-800" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 14v3m4-3v3m4-3v3M3 21h18M3 10h18M3 7l9-4 9 4M4 10h16v11H4V10z"></path>
                </svg>
                <div>
                    <div class="text-lg font-semibold text-zinc-900">ACH Transfer</div>
                    <div class="text-sm text-zinc-600">No fee</div>
                </div>
            </button>

            <button wire:click="selectPaymentMethod('check')" type="button" class="h-40 flex flex-col items-center justify-center gap-3 rounded-lg border-2 border-zinc-300 hover:border-zinc-500 bg-white hover:bg-zinc-50 transition-all hover:scale-[1.02] active:scale-[0.98]">
                <svg class="w-12 h-12 text-zinc-800" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"></path>
                </svg>
                <div>
                    <div class="text-lg font-semibold text-zinc-900">Check</div>
                    <div class="text-sm text-zinc-600">Mail payment</div>
                </div>
            </button>

            <button wire:click="selectPaymentMethod('payment_plan')" type="button" class="h-40 flex flex-col items-center justify-center gap-3 rounded-lg border-2 border-zinc-300 hover:border-zinc-500 bg-white hover:bg-zinc-50 transition-all hover:scale-[1.02] active:scale-[0.98]">
                <svg class="w-12 h-12 text-zinc-800" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"></path>
                </svg>
                <div>
                    <div class="text-lg font-semibold text-zinc-900">Payment Plan</div>
                    <div class="text-sm text-zinc-600">Pay over time</div>
                </div>
            </button>
        </div>

        <div class="text-center pt-4">
            <flux:button variant="ghost" wire:click="goToPrevious">
                <svg class="w-4 h-4 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"></path>
                </svg>
                Back
            </flux:button>
        </div>
    @else
        {{-- Payment Plan Selection --}}
        <flux:heading size="xl" class="mb-2">Choose Your Payment Plan</flux:heading>
        <flux:subheading class="mb-6">Select a plan that works for you - all plans have equal monthly payments</flux:subheading>

        {{-- Invoice Total Display --}}
        <div class="bg-zinc-50 dark:bg-zinc-900 p-4 rounded-lg mb-6">
            <div class="flex justify-between items-center">
                <div class="text-sm text-zinc-700 dark:text-zinc-300">Invoice Total:</div>
                <div class="text-xl font-bold text-zinc-900 dark:text-zinc-100">${{ number_format($paymentAmount, 2) }}</div>
            </div>
        </div>

        {{-- Plan Options --}}
        <div class="space-y-4 mb-6">
            @foreach($availablePlans as $plan)
                <button 
                    wire:click="selectPlanDuration({{ $plan['months'] }})" 
                    type="button" 
                    class="w-full p-6 rounded-xl border-2 transition-all {{ $planDuration === $plan['months'] ? 'border-zinc-800 dark:border-zinc-200 bg-zinc-50 dark:bg-zinc-800' : 'border-zinc-200 dark:border-zinc-700 hover:border-zinc-400 dark:hover:border-zinc-500' }}"
                >
                    <div class="flex items-center justify-between">
                        <div class="text-left">
                            <div class="text-xl font-bold text-zinc-900 dark:text-zinc-100">{{ $plan['months'] }} Months</div>
                            <div class="text-sm text-zinc-600 dark:text-zinc-400">${{ number_format($plan['monthly_payment'], 2) }}/month</div>
                        </div>
                        <div class="text-right">
                            <div class="text-lg font-semibold text-zinc-700 dark:text-zinc-300">${{ number_format($plan['fee'], 2) }} fee</div>
                            <div class="text-sm text-zinc-500 dark:text-zinc-400">Total: ${{ number_format($plan['total_amount'], 2) }}</div>
                        </div>
                        @if($planDuration === $plan['months'])
                            <div class="ml-4">
                                <svg class="w-6 h-6 text-green-600 dark:text-green-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
                                </svg>
                            </div>
                        @endif
                    </div>
                </button>
            @endforeach
        </div>

        {{-- Selected Plan Summary --}}
        @if($paymentPlanFee > 0)
            <div class="bg-blue-50 dark:bg-blue-900/20 border border-blue-200 dark:border-blue-800 rounded-lg p-4 mb-6">
                <div class="space-y-2">
                    <div class="flex justify-between items-center">
                        <span class="text-zinc-700 dark:text-zinc-300">Invoice Total:</span>
                        <span class="font-medium">${{ number_format($paymentAmount, 2) }}</span>
                    </div>
                    <div class="flex justify-between items-center">
                        <span class="text-zinc-700 dark:text-zinc-300">Payment Plan Fee:</span>
                        <span class="font-medium">+${{ number_format($paymentPlanFee, 2) }}</span>
                    </div>
                    <div class="border-t border-blue-200 dark:border-blue-700 pt-2 mt-2">
                        <div class="flex justify-between items-center">
                            <span class="font-semibold text-zinc-900 dark:text-zinc-100">Total Amount:</span>
                            <span class="text-xl font-bold text-zinc-900 dark:text-zinc-100">${{ number_format($paymentAmount + $paymentPlanFee, 2) }}</span>
                        </div>
                    </div>
                </div>
            </div>
        @endif

        {{-- Payment Schedule Preview --}}
        @if(count($paymentSchedule) > 0)
            <div class="mb-6">
                <flux:heading size="lg" class="mb-4">Payment Schedule</flux:heading>
                
                <flux:table container:class="max-h-60">
                    <flux:table.columns sticky class="bg-white dark:bg-zinc-900">
                        <flux:table.column>Payment</flux:table.column>
                        <flux:table.column>Due Date</flux:table.column>
                        <flux:table.column align="end">Amount</flux:table.column>
                    </flux:table.columns>
                    <flux:table.rows>
                        @foreach($paymentSchedule as $payment)
                            <flux:table.row>
                                <flux:table.cell>
                                    <div class="font-medium">{{ $payment['label'] }}</div>
                                </flux:table.cell>
                                <flux:table.cell class="whitespace-nowrap">
                                    {{ $payment['due_date'] }}
                                </flux:table.cell>
                                <flux:table.cell variant="strong" align="end">
                                    ${{ number_format($payment['amount'], 2) }}
                                </flux:table.cell>
                            </flux:table.row>
                        @endforeach
                    </flux:table.rows>
                </flux:table>
            </div>
        @endif

        <flux:error name="planDuration" />

        <div class="flex gap-3 pt-4">
            <flux:button variant="ghost" wire:click="changePaymentMethod">
                <svg class="w-4 h-4 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"></path>
                </svg>
                Back
            </flux:button>
            <flux:button variant="primary" class="flex-1" wire:click="confirmPaymentPlan">
                Continue to Payment Details
            </flux:button>
        </div>
    @endif
</x-payment.step>
