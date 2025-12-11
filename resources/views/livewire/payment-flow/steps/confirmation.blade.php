{{-- Confirmation Step --}}
{{-- resources/views/livewire/payment-flow/steps/confirmation.blade.php --}}

<x-payment.step 
    name="confirmation"
    title=""
    :show-next="false"
    :show-back="false"
>
    <div class="text-center mb-8">
        @if($paymentProcessed)
            {{-- Payment Confirmed --}}
            <svg class="w-20 h-20 mx-auto mb-4 text-green-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
            </svg>
            @if($isPaymentPlan)
                <flux:heading size="2xl" class="text-green-600 mb-2">Payment Plan Confirmed!</flux:heading>
                <flux:subheading>Your payment plan has been set up successfully</flux:subheading>
            @else
                <flux:heading size="2xl" class="text-green-600 mb-2">Payment Confirmed!</flux:heading>
                <flux:subheading>Your payment has been processed successfully</flux:subheading>
            @endif
        @else
            {{-- Confirmation Needed --}}
            <svg class="w-20 h-20 mx-auto mb-4 text-blue-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
            </svg>
            @if($isPaymentPlan)
                <flux:heading size="2xl" class="text-blue-600 mb-2">Review Your Payment Plan</flux:heading>
                <flux:subheading>Please confirm the details below to set up your payment plan</flux:subheading>
            @else
                <flux:heading size="2xl" class="text-blue-600 mb-2">Review Your Payment</flux:heading>
                <flux:subheading>Please confirm the details below to process your payment</flux:subheading>
            @endif
        @endif
    </div>

    <flux:separator class="my-6" />

    {{-- Transaction/Plan Details --}}
    <div class="space-y-3">
        <div class="flex justify-between py-3 border-b border-zinc-200 dark:border-zinc-700">
            <flux:subheading>{{ $isPaymentPlan ? 'Plan ID:' : 'Transaction ID:' }}</flux:subheading>
            <flux:text>{{ $transactionId }}</flux:text>
        </div>
        <div class="flex justify-between py-3 border-b border-zinc-200 dark:border-zinc-700">
            <flux:subheading>Account:</flux:subheading>
            <flux:text>{{ $clientInfo['client_name'] ?? 'Unknown' }}</flux:text>
        </div>
        
        @if($isPaymentPlan)
            {{-- Payment Plan Details --}}
            <div class="flex justify-between py-3 border-b border-zinc-200 dark:border-zinc-700">
                <flux:subheading>Invoice Amount:</flux:subheading>
                <flux:text>${{ number_format($paymentAmount, 2) }}</flux:text>
            </div>
            @if($creditCardFee > 0)
                <div class="flex justify-between py-3 border-b border-zinc-200 dark:border-zinc-700">
                    <flux:subheading>Credit Card Fee:</flux:subheading>
                    <flux:text>${{ number_format($creditCardFee, 2) }}</flux:text>
                </div>
            @endif
            @if($paymentPlanFee > 0)
                <div class="flex justify-between py-3 border-b border-zinc-200 dark:border-zinc-700">
                    <flux:subheading>Plan Fee:</flux:subheading>
                    <flux:text>${{ number_format($paymentPlanFee, 2) }}</flux:text>
                </div>
            @endif
            <div class="flex justify-between py-3 border-b border-zinc-200 dark:border-zinc-700">
                <flux:subheading>Total Obligation:</flux:subheading>
                <flux:text class="font-bold text-zinc-800 dark:text-zinc-200">${{ number_format($paymentAmount + $creditCardFee + $paymentPlanFee, 2) }}</flux:text>
            </div>
            <div class="flex justify-between py-3 border-b border-zinc-200 dark:border-zinc-700">
                <flux:subheading>Payment Frequency:</flux:subheading>
                <flux:text class="capitalize">Monthly</flux:text>
            </div>
            <div class="flex justify-between py-3 border-b border-zinc-200 dark:border-zinc-700">
                <flux:subheading>Number of Payments:</flux:subheading>
                <flux:text>{{ $planDuration }} installments</flux:text>
            </div>
            
            {{-- Payment Schedule --}}
            @if(count($paymentSchedule) > 0)
                <div class="mt-6">
                    <flux:heading size="lg" class="mb-4">Your Payment Schedule</flux:heading>
                    <flux:table>
                        <flux:table.columns>
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
                                        @if(isset($payment['payment_number']) && $payment['payment_number'] === 0)
                                            <flux:badge color="green" size="sm" inset="top bottom" class="ml-2">Paid Today</flux:badge>
                                        @endif
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
            
            {{-- Important Information --}}
            <div class="mt-6 p-4 bg-blue-50 dark:bg-blue-900/20 rounded-lg">
                <flux:subheading class="mb-2">Important Information</flux:subheading>
                <ul class="text-sm text-zinc-700 dark:text-zinc-300 space-y-1">
                    <li>A confirmation email has been sent to your registered email address</li>
                    <li>You will receive payment reminders before each due date</li>
                    <li>Payments will be automatically charged to your selected payment method</li>
                    <li>You can view or modify your payment plan in your account portal</li>
                </ul>
            </div>
        @else
            {{-- One-time Payment Details --}}
            <div class="flex justify-between py-3 border-b border-zinc-200 dark:border-zinc-700">
                <flux:subheading>Payment Amount:</flux:subheading>
                <flux:text>${{ number_format($paymentAmount, 2) }}</flux:text>
            </div>
            @if($creditCardFee > 0)
                <div class="flex justify-between py-3 border-b border-zinc-200 dark:border-zinc-700">
                    <flux:subheading>Credit Card Fee ({{ config("payment-fees.credit_card_rate") * 100 }}%):</flux:subheading>
                    <flux:text>${{ number_format($creditCardFee, 2) }}</flux:text>
                </div>
                <div class="flex justify-between py-3 border-b border-zinc-200 dark:border-zinc-700 bg-zinc-50 dark:bg-zinc-800">
                    <flux:heading size="lg">Total Amount:</flux:heading>
                    <flux:heading size="lg" class="text-zinc-800 dark:text-zinc-200">${{ number_format($paymentAmount + $creditCardFee, 2) }}</flux:heading>
                </div>
            @endif
            <div class="flex justify-between py-3 border-b border-zinc-200 dark:border-zinc-700">
                <flux:subheading>Payment Method:</flux:subheading>
                <flux:text class="capitalize">{{ str_replace('_', ' ', $paymentMethod ?? 'N/A') }}</flux:text>
            </div>
            <div class="flex justify-between py-3 border-b border-zinc-200 dark:border-zinc-700">
                <flux:subheading>Invoices Paid:</flux:subheading>
                <flux:text>{{ count($selectedInvoices) }} invoice(s)</flux:text>
            </div>
            @if($paymentNotes)
                <div class="flex justify-between py-3 border-b border-zinc-200 dark:border-zinc-700">
                    <flux:subheading>Notes:</flux:subheading>
                    <flux:text>{{ $paymentNotes }}</flux:text>
                </div>
            @endif
        @endif
    </div>

    {{-- Action Buttons --}}
    <div class="text-center mt-8 space-y-4">
        @if(!$paymentProcessed)
            {{-- Confirm Payment Button --}}
            <div class="space-y-4">
                <button wire:click="confirmPayment" wire:loading.attr="disabled" wire:loading.class="opacity-50 cursor-not-allowed" wire:target="confirmPayment" type="button" class="px-8 py-4 bg-green-600 hover:bg-green-700 text-white font-medium text-xl rounded-lg flex items-center justify-center gap-3 mx-auto">
                    <svg wire:loading.remove wire:target="confirmPayment" class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
                    </svg>
                    <span wire:loading.remove wire:target="confirmPayment">
                        @if($isPaymentPlan)
                            Confirm Payment Plan
                        @else
                            Confirm Payment
                        @endif
                    </span>
                    <span wire:loading wire:target="confirmPayment" class="flex items-center gap-2">
                        <svg class="animate-spin h-6 w-6" fill="none" viewBox="0 0 24 24">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                        </svg>
                        Processing...
                    </span>
                </button>
                <div class="flex gap-3 justify-center">
                    <button wire:click="goBack" type="button" class="px-4 py-2 text-sm font-medium text-zinc-700 dark:text-zinc-300 hover:text-zinc-900 dark:hover:text-zinc-100">Back</button>
                    <button wire:click="startOver" type="button" class="px-4 py-2 text-sm font-medium text-zinc-700 dark:text-zinc-300 hover:text-zinc-900 dark:hover:text-zinc-100">Start Over</button>
                </div>
            </div>
        @else
            {{-- Payment Confirmed Actions --}}
            @if($isPaymentPlan)
                <div class="bg-blue-50 dark:bg-blue-900/20 p-4 rounded-lg mb-4">
                    <flux:heading size="md" class="mb-2">Manage Your Payment Plan</flux:heading>
                    <div class="flex flex-wrap gap-3 justify-center">
                        <button wire:click="editPaymentPlan" type="button" class="px-4 py-2 bg-orange-600 hover:bg-orange-700 text-white font-medium rounded-lg flex items-center gap-2">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"></path>
                            </svg>
                            Edit Payment Plan
                        </button>
                        <button wire:click="startOver" type="button" class="px-4 py-2 bg-zinc-800 hover:bg-zinc-900 dark:bg-zinc-200 dark:hover:bg-zinc-100 text-white dark:text-zinc-900 font-medium rounded-lg">
                            Set Up New Plan
                        </button>
                    </div>
                    <div class="text-sm text-zinc-600 dark:text-zinc-400 mt-3">
                        <a href="#" class="text-zinc-800 dark:text-zinc-200 hover:underline mr-4">View in Account Portal</a>
                        <a href="#" class="text-zinc-800 dark:text-zinc-200 hover:underline">Contact Support</a>
                    </div>
                </div>
            @else
                <button wire:click="startOver" type="button" class="px-6 py-3 bg-zinc-800 hover:bg-zinc-900 dark:bg-zinc-200 dark:hover:bg-zinc-100 text-white dark:text-zinc-900 font-medium text-lg rounded-lg">
                    Make Another Payment
                </button>
            @endif
        @endif
    </div>
</x-payment.step>
