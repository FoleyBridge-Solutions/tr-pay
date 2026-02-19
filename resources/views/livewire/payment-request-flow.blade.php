<div class="max-w-2xl mx-auto">
    {{-- Error States --}}
    @if($currentStep === 'error')
        <div class="text-center py-12">
            @if($errorType === 'expired')
                <svg class="w-20 h-20 mx-auto mb-4 text-zinc-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                </svg>
                <flux:heading size="2xl" class="mb-2">Link Expired</flux:heading>
                <flux:subheading class="mb-6">This payment link has expired. Please contact our office to request a new one.</flux:subheading>
            @elseif($errorType === 'paid')
                <svg class="w-20 h-20 mx-auto mb-4 text-green-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                </svg>
                <flux:heading size="2xl" class="mb-2">Already Paid</flux:heading>
                <flux:subheading class="mb-6">This payment has already been completed.</flux:subheading>
                @if($paymentRequest && $paymentRequest->payment)
                    <flux:card class="max-w-sm mx-auto">
                        <div class="p-4 space-y-2 text-sm">
                            <div class="flex justify-between">
                                <span class="text-zinc-500">Transaction ID</span>
                                <span class="font-medium">{{ $paymentRequest->payment->transaction_id }}</span>
                            </div>
                            <div class="flex justify-between">
                                <span class="text-zinc-500">Paid On</span>
                                <span class="font-medium">{{ $paymentRequest->paid_at->format('M j, Y g:i A') }}</span>
                            </div>
                            <div class="flex justify-between">
                                <span class="text-zinc-500">Amount</span>
                                <span class="font-medium">${{ number_format($paymentRequest->amount, 2) }}</span>
                            </div>
                        </div>
                    </flux:card>
                @endif
            @elseif($errorType === 'revoked')
                <svg class="w-20 h-20 mx-auto mb-4 text-red-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18.364 18.364A9 9 0 005.636 5.636m12.728 12.728A9 9 0 015.636 5.636m12.728 12.728L5.636 5.636"></path>
                </svg>
                <flux:heading size="2xl" class="mb-2">Link No Longer Valid</flux:heading>
                <flux:subheading class="mb-6">This payment link has been revoked. Please contact our office for assistance.</flux:subheading>
            @else
                <svg class="w-20 h-20 mx-auto mb-4 text-zinc-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9.172 16.172a4 4 0 015.656 0M9 10h.01M15 10h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                </svg>
                <flux:heading size="2xl" class="mb-2">Invalid Link</flux:heading>
                <flux:subheading class="mb-6">This payment link is not valid. Please check the URL and try again.</flux:subheading>
            @endif

            <div class="mt-6">
                <flux:button href="{{ route('payment.start') }}" variant="primary">
                    Go to Payment Portal
                </flux:button>
            </div>
        </div>
    @endif

    {{-- Review & Pay Step --}}
    @if($currentStep === 'review')
        <div class="space-y-6">
            {{-- Header --}}
            <div class="text-center">
                <flux:heading size="2xl">Payment Request</flux:heading>
                <flux:subheading class="mt-1">from {{ config('branding.company_name', config('app.name')) }}</flux:subheading>
            </div>

            {{-- Client & Amount Card --}}
            <flux:card>
                <div class="p-6">
                    <div class="flex justify-between items-start mb-6">
                        <div>
                            <div class="text-sm text-zinc-500">Requested for</div>
                            <div class="text-lg font-semibold text-zinc-900 dark:text-zinc-100">{{ $paymentRequest->client_name }}</div>
                            <div class="text-sm text-zinc-500">{{ $paymentRequest->client_id }}</div>
                        </div>
                        <div class="text-right">
                            <div class="text-sm text-zinc-500">Amount Due</div>
                            <div class="text-3xl font-bold text-zinc-900 dark:text-zinc-100">${{ number_format($paymentRequest->amount, 2) }}</div>
                        </div>
                    </div>

                    {{-- Admin Message --}}
                    @if($paymentRequest->message)
                        <div class="bg-amber-50 dark:bg-amber-900/20 border border-amber-200 dark:border-amber-800 rounded-lg p-4 mb-6">
                            <div class="text-xs font-semibold text-amber-700 dark:text-amber-300 uppercase mb-1">Message</div>
                            <p class="text-sm text-amber-800 dark:text-amber-200">{{ $paymentRequest->message }}</p>
                        </div>
                    @endif

                    {{-- Invoice Table --}}
                    @if($paymentRequest->invoices && count($paymentRequest->invoices) > 0)
                        <div class="mb-6">
                            <flux:heading size="sm" class="mb-3">Invoices</flux:heading>
                            <flux:table>
                                <flux:table.columns>
                                    <flux:table.column>Invoice #</flux:table.column>
                                    <flux:table.column>Description</flux:table.column>
                                    <flux:table.column align="end">Amount</flux:table.column>
                                </flux:table.columns>
                                <flux:table.rows>
                                    @foreach($paymentRequest->invoices as $invoice)
                                        <flux:table.row>
                                            <flux:table.cell>{{ $invoice['invoice_number'] }}</flux:table.cell>
                                            <flux:table.cell>{{ $invoice['description'] ?? '' }}</flux:table.cell>
                                            <flux:table.cell align="end">${{ number_format($invoice['open_amount'] ?? 0, 2) }}</flux:table.cell>
                                        </flux:table.row>
                                    @endforeach
                                </flux:table.rows>
                            </flux:table>
                        </div>
                    @endif
                </div>
            </flux:card>

            {{-- Payment Method Selection --}}
            <flux:card>
                <div class="p-6">
                    <flux:heading size="md" class="mb-4">Payment Method</flux:heading>

                    <div class="grid grid-cols-2 gap-3 mb-6">
                        <button
                            wire:click="selectPaymentMethod('credit_card')"
                            type="button"
                            class="p-4 rounded-lg border-2 text-center transition-all {{ $paymentMethod === 'credit_card' ? 'border-zinc-800 dark:border-zinc-200 bg-zinc-50 dark:bg-zinc-800' : 'border-zinc-200 dark:border-zinc-700 hover:border-zinc-400 dark:hover:border-zinc-500' }}"
                        >
                            <svg class="w-8 h-8 mx-auto mb-2 {{ $paymentMethod === 'credit_card' ? 'text-zinc-800 dark:text-zinc-200' : 'text-zinc-400' }}" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 10h18M7 15h1m4 0h1m-7 4h12a3 3 0 003-3V8a3 3 0 00-3-3H6a3 3 0 00-3 3v8a3 3 0 003 3z"></path>
                            </svg>
                            <span class="text-sm font-medium {{ $paymentMethod === 'credit_card' ? 'text-zinc-800 dark:text-zinc-200' : 'text-zinc-600 dark:text-zinc-400' }}">Credit Card</span>
                        </button>
                        <button
                            wire:click="selectPaymentMethod('ach')"
                            type="button"
                            class="p-4 rounded-lg border-2 text-center transition-all {{ $paymentMethod === 'ach' ? 'border-zinc-800 dark:border-zinc-200 bg-zinc-50 dark:bg-zinc-800' : 'border-zinc-200 dark:border-zinc-700 hover:border-zinc-400 dark:hover:border-zinc-500' }}"
                        >
                            <svg class="w-8 h-8 mx-auto mb-2 {{ $paymentMethod === 'ach' ? 'text-zinc-800 dark:text-zinc-200' : 'text-zinc-400' }}" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 14v3m4-3v3m4-3v3M3 21h18M3 10h18M3 7l9-4 9 4M4 10h16v11H4V10z"></path>
                            </svg>
                            <span class="text-sm font-medium {{ $paymentMethod === 'ach' ? 'text-zinc-800 dark:text-zinc-200' : 'text-zinc-600 dark:text-zinc-400' }}">Bank Account (ACH)</span>
                        </button>
                    </div>

                    {{-- Payment Details Form --}}
                    @if($paymentMethod)
                        {{-- Error Display --}}
                        @if($errors->has('payment'))
                            <div class="mb-6 bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 rounded-lg p-4">
                                <div class="flex items-start gap-3">
                                    <svg class="w-5 h-5 text-red-600 dark:text-red-400 flex-shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                                    </svg>
                                    <p class="text-sm text-red-700 dark:text-red-300">{{ $errors->first('payment') }}</p>
                                </div>
                            </div>
                        @endif

                        <form wire:submit.prevent="confirmPayment" class="space-y-4">
                            @if($paymentMethod === 'credit_card')
                                {{-- Credit Card Fee Notice --}}
                                @if($creditCardFee > 0)
                                    <div class="bg-zinc-50 dark:bg-zinc-800 border border-zinc-200 dark:border-zinc-700 rounded-lg p-3">
                                        <div class="flex justify-between text-sm">
                                            <span class="text-zinc-600 dark:text-zinc-400">Payment Amount:</span>
                                            <span class="font-medium">${{ number_format($paymentRequest->amount, 2) }}</span>
                                        </div>
                                        <div class="flex justify-between text-sm mt-1">
                                            <span class="text-zinc-600 dark:text-zinc-400">Non-Cash Adjustment ({{ config('payment-fees.credit_card_rate') * 100 }}%):</span>
                                            <span class="font-medium">+${{ number_format($creditCardFee, 2) }}</span>
                                        </div>
                                        <div class="flex justify-between text-sm mt-2 pt-2 border-t border-zinc-200 dark:border-zinc-700">
                                            <span class="font-semibold text-zinc-800 dark:text-zinc-200">Total Charge:</span>
                                            <span class="font-bold text-zinc-900 dark:text-zinc-100">${{ number_format($paymentRequest->amount + $creditCardFee, 2) }}</span>
                                        </div>
                                    </div>
                                @endif

                                <x-payment-method-fields
                                    type="card"
                                    :required="true"
                                />
                            @elseif($paymentMethod === 'ach')
                                <x-payment-method-fields
                                    type="ach"
                                    :required="true"
                                    :show-bank-name="true"
                                    :show-ach-auth="true"
                                    :show-is-business="true"
                                    :show-descriptions="true"
                                    :account-type-value="$bankAccountType"
                                    ach-auth-description="By providing your bank account information, you authorize {{ config('branding.company_name', 'our company') }} to electronically debit your {{ $bankAccountType === 'savings' ? 'savings' : 'checking' }} account for the amount specified."
                                    ach-auth-text="I authorize this ACH debit."
                                />
                            @endif

                            {{-- Security Notice --}}
                            <div class="bg-zinc-50 dark:bg-zinc-800 border border-zinc-200 dark:border-zinc-700 rounded-lg p-3 text-sm text-zinc-600 dark:text-zinc-400">
                                <div class="flex items-start gap-2">
                                    <svg class="w-4 h-4 text-green-600 dark:text-green-400 flex-shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"></path>
                                    </svg>
                                    <span><strong>Secure Payment</strong> - Your information is encrypted and securely transmitted.</span>
                                </div>
                            </div>

                            {{-- Submit Button --}}
                            <button
                                type="submit"
                                class="w-full px-6 py-4 bg-zinc-800 hover:bg-zinc-900 dark:bg-zinc-200 dark:hover:bg-zinc-100 text-white dark:text-zinc-900 font-semibold text-lg rounded-lg flex items-center justify-center gap-2"
                                wire:loading.attr="disabled"
                                wire:target="confirmPayment"
                            >
                                <span wire:loading.remove wire:target="confirmPayment">
                                    Pay ${{ number_format($paymentMethod === 'credit_card' ? $paymentRequest->amount + $creditCardFee : $paymentRequest->amount, 2) }}
                                </span>
                                <span wire:loading wire:target="confirmPayment" class="flex items-center gap-2">
                                    <svg class="animate-spin h-5 w-5" fill="none" viewBox="0 0 24 24">
                                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                                    </svg>
                                    Processing...
                                </span>
                            </button>
                        </form>
                    @endif
                </div>
            </flux:card>

            {{-- Expiry Notice --}}
            <p class="text-center text-xs text-zinc-400">
                This link expires on {{ $paymentRequest->expires_at->format('F j, Y') }}.
            </p>
        </div>
    @endif

    {{-- Confirmation Step --}}
    @if($currentStep === 'confirmation')
        <div class="space-y-6">
            <div class="text-center">
                <svg class="w-20 h-20 mx-auto mb-4 text-green-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                </svg>
                <flux:heading size="2xl" class="text-green-600 mb-2">Payment Confirmed!</flux:heading>
                <flux:subheading>Your payment has been processed successfully.</flux:subheading>
            </div>

            <flux:card>
                <div class="p-6 space-y-3">
                    <div class="flex justify-between py-2 border-b border-zinc-200 dark:border-zinc-700">
                        <flux:subheading>Transaction ID</flux:subheading>
                        <flux:text class="font-mono text-sm">{{ $transactionId }}</flux:text>
                    </div>
                    <div class="flex justify-between py-2 border-b border-zinc-200 dark:border-zinc-700">
                        <flux:subheading>Account</flux:subheading>
                        <flux:text>{{ $paymentRequest->client_name }}</flux:text>
                    </div>
                    <div class="flex justify-between py-2 border-b border-zinc-200 dark:border-zinc-700">
                        <flux:subheading>Payment Amount</flux:subheading>
                        <flux:text>${{ number_format($paymentRequest->amount, 2) }}</flux:text>
                    </div>
                    @if($creditCardFee > 0)
                        <div class="flex justify-between py-2 border-b border-zinc-200 dark:border-zinc-700">
                            <flux:subheading>Non-Cash Adjustment</flux:subheading>
                            <flux:text>${{ number_format($creditCardFee, 2) }}</flux:text>
                        </div>
                        <div class="flex justify-between py-2 border-b border-zinc-200 dark:border-zinc-700">
                            <flux:heading size="sm">Total Charged</flux:heading>
                            <flux:heading size="sm">${{ number_format($paymentRequest->amount + $creditCardFee, 2) }}</flux:heading>
                        </div>
                    @endif
                    <div class="flex justify-between py-2 border-b border-zinc-200 dark:border-zinc-700">
                        <flux:subheading>Payment Method</flux:subheading>
                        <flux:text class="capitalize">{{ str_replace('_', ' ', $paymentMethod) }}</flux:text>
                    </div>
                    @if($paymentRequest->invoices && count($paymentRequest->invoices) > 0)
                        <div class="flex justify-between py-2 border-b border-zinc-200 dark:border-zinc-700">
                            <flux:subheading>Invoices Paid</flux:subheading>
                            <flux:text>{{ count($paymentRequest->invoices) }} invoice(s)</flux:text>
                        </div>
                    @endif
                </div>
            </flux:card>

            {{-- Additional Invoices CTA --}}
            @if(count($additionalInvoices) > 0)
                <flux:card>
                    <div class="p-6 text-center">
                        <flux:heading size="md" class="mb-2">You have {{ count($additionalInvoices) }} more open invoice(s)</flux:heading>
                        <flux:subheading class="mb-4">
                            Total remaining balance: ${{ number_format($this->additionalInvoicesTotal, 2) }}
                        </flux:subheading>
                        <flux:button href="{{ route('payment.start') }}" variant="primary" icon="banknotes">
                            Pay Remaining Invoices
                        </flux:button>
                    </div>
                </flux:card>
            @endif

            <div class="text-center">
                <p class="text-sm text-zinc-500">A receipt has been sent to {{ $paymentRequest->email }}.</p>
            </div>
        </div>
    @endif
</div>
