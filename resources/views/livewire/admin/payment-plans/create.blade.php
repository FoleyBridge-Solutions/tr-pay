<div>
    <div class="mb-8">
        <flux:heading size="xl">Create Payment Plan</flux:heading>
        <flux:subheading>Set up a scheduled payment plan for a client</flux:subheading>
    </div>

    {{-- Progress Steps --}}
    @if($currentStep <= 5)
        <div class="mb-8">
            <div class="flex items-center justify-between max-w-3xl">
                @foreach(['Select Client', 'Select Invoices', 'Payment Method', 'Configure Plan', 'Review'] as $index => $stepName)
                    @php $stepNum = $index + 1; @endphp
                    <div class="flex items-center {{ $index < 4 ? 'flex-1' : '' }}">
                        <div class="flex items-center">
                            <div class="w-8 h-8 rounded-full flex items-center justify-center text-sm font-medium {{ $currentStep > $stepNum ? 'bg-green-500 text-white' : ($currentStep === $stepNum ? 'bg-blue-500 text-white' : 'bg-zinc-200 dark:bg-zinc-700 text-zinc-600 dark:text-zinc-400') }}">
                                @if($currentStep > $stepNum)
                                    <flux:icon name="check" class="w-4 h-4" />
                                @else
                                    {{ $stepNum }}
                                @endif
                            </div>
                            <span class="ml-2 text-sm {{ $currentStep >= $stepNum ? 'text-zinc-900 dark:text-white' : 'text-zinc-500' }} hidden sm:inline">{{ $stepName }}</span>
                        </div>
                        @if($index < 4)
                            <div class="flex-1 h-0.5 mx-4 {{ $currentStep > $stepNum ? 'bg-green-500' : 'bg-zinc-200 dark:bg-zinc-700' }}"></div>
                        @endif
                    </div>
                @endforeach
            </div>
        </div>
    @endif

    {{-- Error Message --}}
    @if($errorMessage)
        <flux:callout variant="danger" icon="exclamation-triangle" class="mb-6">
            {{ $errorMessage }}
        </flux:callout>
    @endif

    {{-- Step 1: Select Client --}}
    @if($currentStep === 1)
        <flux:card class="max-w-3xl">
            <div class="p-6">
                <flux:heading size="lg" class="mb-4">Search for Client</flux:heading>
                <livewire:admin.client-search mode="select" />
            </div>
        </flux:card>
    @endif

    {{-- Step 2: Select Invoices --}}
    @if($currentStep === 2)
        <flux:card class="max-w-4xl">
            <div class="p-6">
                {{-- Selected Client Info --}}
                <div class="bg-zinc-50 dark:bg-zinc-800 rounded-lg p-4 mb-6">
                    <div class="flex items-center justify-between">
                        <div>
                            <flux:text class="text-sm text-zinc-500">Selected Client</flux:text>
                            <flux:heading size="md">{{ $selectedClient['client_name'] }}</flux:heading>
                            <flux:text class="text-sm text-zinc-500">ID: {{ $selectedClient['client_id'] }}</flux:text>
                        </div>
                        <div class="text-right">
                            <flux:text class="text-sm text-zinc-500">Current Balance</flux:text>
                            <flux:heading size="md" class="{{ $selectedClient['balance'] > 0 ? 'text-red-600' : 'text-green-600' }}">
                                ${{ number_format($selectedClient['balance'], 2) }}
                            </flux:heading>
                        </div>
                    </div>
                </div>

                <div class="flex items-center justify-between mb-4">
                    <flux:heading size="lg">Select Invoices</flux:heading>
                    <flux:button wire:click="clearSelection" size="sm" variant="ghost">Clear</flux:button>
                </div>

                @if(count($availableInvoices) === 0)
                    <div class="text-center py-8 text-zinc-500">
                        No open invoices found for this client.
                    </div>
                @else
                    <div class="border border-zinc-200 dark:border-zinc-700 rounded-lg overflow-hidden mb-4">
                        <flux:checkbox.group wire:model.live="selectedInvoices">
                            <flux:table>
                                <flux:table.columns>
                                    <flux:table.column class="w-10">
                                        <flux:checkbox.all />
                                    </flux:table.column>
                                    <flux:table.column>Invoice #</flux:table.column>
                                    <flux:table.column>Date</flux:table.column>
                                    <flux:table.column>Due Date</flux:table.column>
                                    <flux:table.column>Type</flux:table.column>
                                    <flux:table.column class="text-right">Amount</flux:table.column>
                                </flux:table.columns>
                                <flux:table.rows>
                                    @foreach($availableInvoices as $invoice)
                                        @php $invoiceKey = (string) $invoice['ledger_entry_KEY']; @endphp
                                        <flux:table.row
                                            wire:key="invoice-{{ $invoiceKey }}"
                                            x-on:click="$el.querySelector('input[type=checkbox]')?.click()"
                                            class="cursor-pointer hover:bg-zinc-50 dark:hover:bg-zinc-800"
                                        >
                                            <flux:table.cell x-on:click.stop>
                                                <flux:checkbox value="{{ $invoiceKey }}" />
                                            </flux:table.cell>
                                            <flux:table.cell class="font-mono">{{ $invoice['invoice_number'] }}</flux:table.cell>
                                            <flux:table.cell>{{ $invoice['invoice_date'] }}</flux:table.cell>
                                            <flux:table.cell>{{ $invoice['due_date'] }}</flux:table.cell>
                                            <flux:table.cell>{{ $invoice['type'] }}</flux:table.cell>
                                            <flux:table.cell class="text-right font-medium">
                                                ${{ number_format($invoice['open_amount'], 2) }}
                                            </flux:table.cell>
                                        </flux:table.row>
                                    @endforeach
                                </flux:table.rows>
                            </flux:table>
                        </flux:checkbox.group>
                    </div>

                    {{-- Selected Total --}}
                    <div class="flex justify-end">
                        <div class="bg-zinc-100 dark:bg-zinc-800 rounded-lg px-4 py-2">
                            <span class="text-zinc-600 dark:text-zinc-400">Selected Total:</span>
                            <span class="font-bold ml-2">${{ number_format($invoiceTotal, 2) }}</span>
                            <span class="text-zinc-500 ml-2">({{ count($selectedInvoices) }} invoices)</span>
                        </div>
                    </div>
                @endif

                <div class="flex justify-between mt-6 pt-6 border-t border-zinc-200 dark:border-zinc-700">
                    <flux:button wire:click="previousStep" variant="ghost">Back</flux:button>
                    <flux:button wire:click="nextStep" variant="primary" :disabled="count($selectedInvoices) === 0">
                        Continue
                    </flux:button>
                </div>
            </div>
        </flux:card>
    @endif

    {{-- Step 3: Payment Method --}}
    @if($currentStep === 3)
        <flux:card class="max-w-2xl">
            <div class="p-6">
                <flux:heading size="lg" class="mb-6">Payment Method</flux:heading>

                {{-- Payment Type Tabs --}}
                <div class="flex gap-2 mb-6">
                    @if($savedPaymentMethods->count() > 0)
                        <flux:button
                            wire:click="$set('paymentMethodType', 'saved')"
                            :variant="$paymentMethodType === 'saved' ? 'primary' : 'ghost'"
                        >
                            Saved Method ({{ $savedPaymentMethods->count() }})
                        </flux:button>
                    @endif
                    <flux:button
                        wire:click="$set('paymentMethodType', 'card')"
                        :variant="$paymentMethodType === 'card' ? 'primary' : 'ghost'"
                    >
                        Credit Card
                    </flux:button>
                    <flux:button
                        wire:click="$set('paymentMethodType', 'ach')"
                        :variant="$paymentMethodType === 'ach' ? 'primary' : 'ghost'"
                    >
                        Bank Account (ACH)
                    </flux:button>
                </div>

                {{-- Credit Card NCA Notice --}}
                @if($paymentMethodType === 'card' || ($paymentMethodType === 'saved' && $savedPaymentMethodId && $savedPaymentMethods->firstWhere('id', $savedPaymentMethodId)?->type === 'card'))
                    <flux:callout variant="warning" icon="exclamation-triangle" class="mb-6">
                        <flux:callout.heading>Non-Cash Adjustment</flux:callout.heading>
                        <flux:callout.text>
                            A {{ config('payment-fees.credit_card_rate') * 100 }}% credit card processing fee will be applied to this payment plan.
                            This fee is included in the total amount and spread across all payments.
                        </flux:callout.text>
                    </flux:callout>
                @endif

                {{-- Saved Payment Methods --}}
                @if($paymentMethodType === 'saved')
                    <div wire:key="payment-fields-saved" class="space-y-3">
                        @foreach($savedPaymentMethods as $method)
                            <label
                                wire:key="saved-method-{{ $method->id }}"
                                class="block p-4 rounded-lg border-2 cursor-pointer transition-all
                                    {{ $savedPaymentMethodId === $method->id
                                        ? 'border-blue-500 bg-blue-50 dark:bg-blue-900/20 dark:border-blue-400'
                                        : 'border-zinc-200 dark:border-zinc-700 hover:border-zinc-400 dark:hover:border-zinc-500' }}
                                    {{ $method->isExpired() ? 'opacity-50' : '' }}"
                            >
                                <div class="flex items-center gap-4">
                                    <input
                                        type="radio"
                                        wire:model.live="savedPaymentMethodId"
                                        value="{{ $method->id }}"
                                        class="text-blue-500"
                                        @if($method->isExpired()) disabled @endif
                                    />

                                    {{-- Card/Bank Icon --}}
                                    <div class="flex-shrink-0">
                                        @if($method->type === 'card')
                                            @switch($method->brand)
                                                @case('Visa')
                                                    <div class="w-12 h-8 bg-blue-600 rounded flex items-center justify-center text-white font-bold text-xs">VISA</div>
                                                    @break
                                                @case('Mastercard')
                                                    <div class="w-12 h-8 bg-orange-500 rounded flex items-center justify-center text-white font-bold text-xs">MC</div>
                                                    @break
                                                @case('American Express')
                                                    <div class="w-12 h-8 bg-blue-800 rounded flex items-center justify-center text-white font-bold text-xs">AMEX</div>
                                                    @break
                                                @case('Discover')
                                                    <div class="w-12 h-8 bg-orange-600 rounded flex items-center justify-center text-white font-bold text-xs">DISC</div>
                                                    @break
                                                @default
                                                    <div class="w-12 h-8 bg-zinc-500 rounded flex items-center justify-center text-white font-bold text-xs">CARD</div>
                                            @endswitch
                                        @else
                                            <div class="w-12 h-8 bg-green-600 rounded flex items-center justify-center text-white font-bold text-xs">ACH</div>
                                        @endif
                                    </div>

                                    {{-- Method Details --}}
                                    <div class="flex-1 min-w-0">
                                        <div class="font-medium text-zinc-900 dark:text-zinc-100">
                                            @if($method->type === 'card')
                                                {{ $method->brand ?? 'Card' }} ending in {{ $method->last_four }}
                                            @else
                                                {{ ucfirst($method->account_type ?? 'Bank') }} account ending in {{ $method->last_four }}
                                            @endif
                                        </div>
                                        <div class="text-sm text-zinc-500 dark:text-zinc-400">
                                            @if($method->type === 'card' && $method->exp_month && $method->exp_year)
                                                Expires {{ str_pad($method->exp_month, 2, '0', STR_PAD_LEFT) }}/{{ $method->exp_year }}
                                            @elseif($method->type === 'ach' && $method->bank_name)
                                                {{ $method->bank_name }}
                                            @endif
                                            @if($method->nickname)
                                                &middot; {{ $method->nickname }}
                                            @endif
                                        </div>
                                    </div>

                                    {{-- Badges --}}
                                    <div class="flex items-center gap-2 flex-shrink-0">
                                        @if($method->is_default)
                                            <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-blue-100 text-blue-800 dark:bg-blue-900 dark:text-blue-200">
                                                Default
                                            </span>
                                        @endif
                                        @if($method->isExpired())
                                            <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-red-100 text-red-800 dark:bg-red-900 dark:text-red-200">
                                                Expired
                                            </span>
                                        @endif
                                    </div>
                                </div>
                            </label>
                        @endforeach
                    </div>
                @endif

                @if($paymentMethodType === 'card')
                    <div wire:key="payment-fields-card" class="space-y-4">
                        <flux:field>
                            <flux:label>Card Number</flux:label>
                            <flux:input wire:model="cardNumber" placeholder="1234 5678 9012 3456" />
                        </flux:field>

                        <div class="grid grid-cols-2 gap-4">
                            <flux:field>
                                <flux:label>Expiry Date</flux:label>
                                <flux:input wire:model="cardExpiry" placeholder="MM/YY" />
                            </flux:field>
                            <flux:field>
                                <flux:label>CVV</flux:label>
                                <flux:input wire:model="cardCvv" type="password" placeholder="123" />
                            </flux:field>
                        </div>

                        <flux:field>
                            <flux:label>Name on Card</flux:label>
                            <flux:input wire:model="cardName" placeholder="John Doe" />
                        </flux:field>
                    </div>
                @endif

                @if($paymentMethodType === 'ach')
                    <div wire:key="payment-fields-ach" class="space-y-4">
                        <flux:field>
                            <flux:label>Account Holder Name</flux:label>
                            <flux:input wire:model="accountName" placeholder="John Doe" />
                        </flux:field>

                        <flux:field>
                            <flux:label>Routing Number</flux:label>
                            <flux:input wire:model="routingNumber" placeholder="123456789" />
                        </flux:field>

                        <flux:field>
                            <flux:label>Account Number</flux:label>
                            <flux:input wire:model="accountNumber" placeholder="1234567890" />
                        </flux:field>

                        <flux:field>
                            <flux:label>Account Type</flux:label>
                            <flux:select wire:model="accountType">
                                <option value="checking">Checking</option>
                                <option value="savings">Savings</option>
                            </flux:select>
                        </flux:field>
                    </div>
                @endif

                <div class="flex justify-between mt-6 pt-6 border-t border-zinc-200 dark:border-zinc-700">
                    <flux:button wire:click="previousStep" variant="ghost">Back</flux:button>
                    <flux:button wire:click="nextStep" variant="primary">Continue</flux:button>
                </div>
            </div>
        </flux:card>
    @endif

    {{-- Step 4: Configure Plan --}}
    @if($currentStep === 4)
        <flux:card class="max-w-2xl">
            <div class="p-6">
                <flux:heading size="lg" class="mb-6">Configure Payment Plan</flux:heading>

                {{-- Plan Duration --}}
                <flux:field class="mb-6">
                    <flux:label>Plan Duration</flux:label>
                    <flux:select wire:model.live="planDuration">
                        <option value="3">3 Months{{ $waivePlanFee ? '' : ' - $150 Fee' }}</option>
                        <option value="6">6 Months{{ $waivePlanFee ? '' : ' - $300 Fee' }}</option>
                        <option value="9">9 Months{{ $waivePlanFee ? '' : ' - $450 Fee' }}</option>
                    </flux:select>
                </flux:field>

                {{-- Admin Override Options --}}
                <div class="mb-6 p-4 bg-blue-50 dark:bg-blue-900/20 border border-blue-200 dark:border-blue-800 rounded-lg space-y-4">
                    <div class="text-sm font-medium text-blue-800 dark:text-blue-300">Admin Options</div>

                    {{-- Waive Plan Fee --}}
                    <div class="flex items-start gap-3">
                        <flux:checkbox wire:model.live="waivePlanFee" id="waivePlanFee" />
                        <div>
                            <label for="waivePlanFee" class="font-medium text-zinc-900 dark:text-zinc-100 cursor-pointer">
                                Waive plan fee
                            </label>
                            <p class="text-sm text-zinc-600 dark:text-zinc-400 mt-1">
                                Set the plan fee to $0 instead of the standard ${{ number_format(config('payment-fees.payment_plan_fees')[$planDuration] ?? 0, 0) }} fee.
                            </p>
                        </div>
                    </div>

                    {{-- Custom Down Payment --}}
                    <div class="flex items-start gap-3">
                        <flux:checkbox wire:model.live="useCustomDownPayment" id="useCustomDownPayment" />
                        <div class="flex-1">
                            <label for="useCustomDownPayment" class="font-medium text-zinc-900 dark:text-zinc-100 cursor-pointer">
                                Set custom down payment amount
                            </label>
                            <p class="text-sm text-zinc-600 dark:text-zinc-400 mt-1">
                                Override the standard 30% down payment with a custom dollar amount.
                            </p>

                            @if($useCustomDownPayment)
                                <div class="mt-3 max-w-xs">
                                    <flux:input
                                        wire:model.live.debounce.300ms="customDownPaymentAmount"
                                        type="number"
                                        min="0"
                                        max="{{ $totalAmount }}"
                                        step="0.01"
                                        placeholder="0.00"
                                        icon="currency-dollar"
                                    />
                                    <p class="text-xs text-zinc-500 mt-1">
                                        Enter an amount between $0.00 and ${{ number_format($totalAmount, 2) }}
                                    </p>
                                </div>
                            @endif
                        </div>
                    </div>

                    {{-- Custom Recurring Payment Day --}}
                    <div class="flex items-start gap-3">
                        <div class="flex-1">
                            <label for="recurringDay" class="font-medium text-zinc-900 dark:text-zinc-100">
                                Recurring payment day of month
                            </label>
                            <p class="text-sm text-zinc-600 dark:text-zinc-400 mt-1">
                                Set a specific day (1-31) for monthly payments. Leave blank to use today's date each month.
                                For shorter months, payments will fall on the last day of the month.
                            </p>
                            <div class="mt-3 max-w-xs">
                                <flux:input
                                    wire:model.live.debounce.300ms="recurringDay"
                                    type="number"
                                    min="1"
                                    max="31"
                                    step="1"
                                    placeholder="e.g. 15"
                                    icon="calendar-days"
                                />
                            </div>
                        </div>
                    </div>
                </div>

                {{-- Summary --}}
                <div class="bg-zinc-50 dark:bg-zinc-800 rounded-lg p-4 space-y-3">
                    <div class="flex justify-between">
                        <span class="text-zinc-600 dark:text-zinc-400">Invoice Total</span>
                        <span>${{ number_format($invoiceTotal, 2) }}</span>
                    </div>
                    <div class="flex justify-between">
                        <span class="text-zinc-600 dark:text-zinc-400">Plan Fee</span>
                        <span class="{{ $waivePlanFee ? 'text-green-600 dark:text-green-400' : '' }}">
                            @if($waivePlanFee)
                                <span class="line-through text-zinc-400 mr-1">${{ number_format(config('payment-fees.payment_plan_fees')[$planDuration] ?? 0, 2) }}</span>
                                $0.00 (Waived)
                            @else
                                ${{ number_format($planFee, 2) }}
                            @endif
                        </span>
                    </div>
                    @if($creditCardFee > 0)
                    <div class="flex justify-between text-amber-700 dark:text-amber-400">
                        <span>Credit Card Fee ({{ config('payment-fees.credit_card_rate') * 100 }}%)</span>
                        <span>${{ number_format($creditCardFee, 2) }}</span>
                    </div>
                    @endif
                    <div class="border-t border-zinc-200 dark:border-zinc-700 pt-3 flex justify-between font-medium">
                        <span>Total Amount</span>
                        <span>${{ number_format($totalAmount, 2) }}</span>
                    </div>
                    <div class="border-t border-zinc-200 dark:border-zinc-700 pt-3 flex justify-between font-bold text-green-600 dark:text-green-400">
                        <span>
                            @if($useCustomDownPayment)
                                Down Payment (Custom){{ $downPayment > 0 ? ' - Due Today' : '' }}
                            @else
                                Down Payment (30%) - Due Today
                            @endif
                        </span>
                        <span>${{ number_format($downPayment, 2) }}</span>
                    </div>
                    <div class="flex justify-between text-lg font-bold text-blue-600">
                        <span>Monthly Payment (x{{ $planDuration }})</span>
                        <span>${{ number_format($monthlyPayment, 2) }}/month</span>
                    </div>
                </div>

                {{-- Split Down Payment Option (Admin Only) - Hidden when using custom down payment --}}
                @if(!$useCustomDownPayment && $downPayment > 0)
                <div class="mt-6 p-4 bg-yellow-50 dark:bg-yellow-900/20 border border-yellow-200 dark:border-yellow-800 rounded-lg">
                    <div class="flex items-start gap-3">
                        <flux:checkbox wire:model.live="splitDownPayment" id="splitDownPayment" />
                        <div>
                            <label for="splitDownPayment" class="font-medium text-zinc-900 dark:text-zinc-100 cursor-pointer">
                                Split down payment into two payments
                            </label>
                            <p class="text-sm text-zinc-600 dark:text-zinc-400 mt-1">
                                Allow client to pay 15% today and 15% later this month.
                            </p>
                        </div>
                    </div>

                    @if($splitDownPayment && count($splitPaymentDetails) > 0)
                        <div class="mt-4 p-3 bg-white dark:bg-zinc-800 rounded border border-yellow-200 dark:border-yellow-700">
                            <div class="text-sm font-medium text-zinc-700 dark:text-zinc-300 mb-2">Split Payment Schedule:</div>
                            <div class="space-y-2 text-sm">
                                <div class="flex justify-between">
                                    <span>First Payment (Today):</span>
                                    <span class="font-medium">${{ number_format($splitPaymentDetails['first_payment']['amount'], 2) }}</span>
                                </div>
                                <div class="flex justify-between">
                                    <span>Second Payment ({{ $splitPaymentDetails['second_payment']['date_formatted'] }}):</span>
                                    <span class="font-medium">${{ number_format($splitPaymentDetails['second_payment']['amount'], 2) }}</span>
                                </div>
                            </div>
                        </div>
                    @endif
                </div>
                @endif

                {{-- Payment Schedule Preview --}}
                <div class="mt-6" wire:replace>
                    <flux:heading size="md" class="mb-3">Payment Schedule</flux:heading>
                    <div class="border border-zinc-200 dark:border-zinc-700 rounded-lg overflow-hidden">
                        <flux:table>
                            <flux:table.columns>
                                <flux:table.column>Payment</flux:table.column>
                                <flux:table.column>Date</flux:table.column>
                                <flux:table.column class="text-right">Amount</flux:table.column>
                            </flux:table.columns>
                            <flux:table.rows>
                                @foreach($this->paymentSchedule as $payment)
                                    <flux:table.row class="{{ ($payment['type'] ?? '') === 'down_payment' ? 'bg-green-50 dark:bg-green-900/20' : '' }}">
                                        <flux:table.cell>
                                            <span class="{{ ($payment['type'] ?? '') === 'down_payment' ? 'text-green-700 dark:text-green-400 font-medium' : '' }}">
                                                {{ $payment['label'] }}
                                            </span>
                                        </flux:table.cell>
                                        <flux:table.cell>{{ $payment['due_date'] }}</flux:table.cell>
                                        <flux:table.cell class="text-right {{ ($payment['type'] ?? '') === 'down_payment' ? 'text-green-700 dark:text-green-400 font-medium' : '' }}">
                                            ${{ number_format($payment['amount'], 2) }}
                                        </flux:table.cell>
                                    </flux:table.row>
                                @endforeach
                            </flux:table.rows>
                        </flux:table>
                    </div>
                </div>

                <div class="flex justify-between mt-6 pt-6 border-t border-zinc-200 dark:border-zinc-700">
                    <flux:button wire:click="previousStep" variant="ghost">Back</flux:button>
                    <flux:button wire:click="nextStep" variant="primary">Continue</flux:button>
                </div>
            </div>
        </flux:card>
    @endif

    {{-- Step 5: Review --}}
    @if($currentStep === 5)
        <flux:card class="max-w-2xl">
            <div class="p-6">
                <flux:heading size="lg" class="mb-6">Review Payment Plan</flux:heading>

                <div class="space-y-6">
                    {{-- Client Info --}}
                    <div>
                        <flux:text class="text-sm text-zinc-500 mb-1">Client</flux:text>
                        <flux:text class="font-medium">{{ $selectedClient['client_name'] }}</flux:text>
                        <flux:text class="text-sm text-zinc-500">ID: {{ $selectedClient['client_id'] }}</flux:text>
                    </div>

                    {{-- Plan Summary --}}
                    <div class="bg-zinc-50 dark:bg-zinc-800 rounded-lg p-4 space-y-2">
                        <div class="flex justify-between">
                            <span class="text-zinc-600 dark:text-zinc-400">Invoice Total ({{ count($selectedInvoices) }} invoices)</span>
                            <span>${{ number_format($invoiceTotal, 2) }}</span>
                        </div>
                        <div class="flex justify-between">
                            <span class="text-zinc-600 dark:text-zinc-400">Plan Fee ({{ $planDuration }} months)</span>
                            <span class="{{ $waivePlanFee ? 'text-green-600 dark:text-green-400' : '' }}">
                                @if($waivePlanFee)
                                    <span class="line-through text-zinc-400 mr-1">${{ number_format(config('payment-fees.payment_plan_fees')[$planDuration] ?? 0, 2) }}</span>
                                    $0.00 (Waived)
                                @else
                                    ${{ number_format($planFee, 2) }}
                                @endif
                            </span>
                        </div>
                        @if($creditCardFee > 0)
                        <div class="flex justify-between text-amber-700 dark:text-amber-400">
                            <span>Credit Card Fee ({{ config('payment-fees.credit_card_rate') * 100 }}%)</span>
                            <span>${{ number_format($creditCardFee, 2) }}</span>
                        </div>
                        @endif
                        <div class="border-t border-zinc-200 dark:border-zinc-700 pt-2 flex justify-between font-medium">
                            <span>Total Amount</span>
                            <span>${{ number_format($totalAmount, 2) }}</span>
                        </div>
                        @if($downPayment > 0)
                        <div class="flex justify-between font-medium text-green-600 dark:text-green-400">
                            <span>
                                @if($useCustomDownPayment)
                                    Down Payment (Custom) - Due Today
                                @else
                                    Down Payment (30%) - Due Today
                                @endif
                            </span>
                            <span>${{ number_format($downPayment, 2) }}</span>
                        </div>
                        @else
                        <div class="flex justify-between font-medium text-zinc-500">
                            <span>Down Payment</span>
                            <span>$0.00 (None)</span>
                        </div>
                        @endif
                        <div class="flex justify-between text-lg font-bold text-blue-600">
                            <span>Monthly Payment</span>
                            <span>${{ number_format($monthlyPayment, 2) }}/month</span>
                        </div>
                    </div>

                    {{-- Payment Method --}}
                    <div>
                        <flux:text class="text-sm text-zinc-500 mb-1">Payment Method</flux:text>
                        @if($paymentMethodType === 'saved')
                            @php $savedMethod = $this->getSelectedSavedMethod(); @endphp
                            @if($savedMethod)
                                @if($savedMethod->type === 'card')
                                    <flux:text class="font-medium">{{ $savedMethod->brand ?? 'Card' }} ending in {{ $savedMethod->last_four }}{{ $savedMethod->nickname ? ' ('.$savedMethod->nickname.')' : '' }}</flux:text>
                                @else
                                    <flux:text class="font-medium">{{ $savedMethod->bank_name ?? ucfirst($savedMethod->account_type ?? 'Bank') }} Account ending in {{ $savedMethod->last_four }}{{ $savedMethod->nickname ? ' ('.$savedMethod->nickname.')' : '' }}</flux:text>
                                @endif
                                <flux:text class="text-xs text-zinc-400">Saved payment method</flux:text>
                            @else
                                <flux:text class="font-medium text-red-500">Saved method not found</flux:text>
                            @endif
                        @elseif($paymentMethodType === 'card')
                            <flux:text class="font-medium">Credit Card ending in {{ substr(preg_replace('/\D/', '', $cardNumber), -4) }}</flux:text>
                        @else
                            <flux:text class="font-medium">{{ ucfirst($accountType) }} Account ending in {{ substr($accountNumber, -4) }}</flux:text>
                        @endif
                    </div>

                    {{-- First Payment --}}
                    <div>
                        <flux:text class="text-sm text-zinc-500 mb-1">First Payment</flux:text>
                        <flux:text class="font-medium">{{ $this->paymentSchedule[0]['due_date'] ?? 'N/A' }}</flux:text>
                    </div>

                    {{-- Recurring Payment Day --}}
                    @if($recurringDay)
                    <div>
                        <flux:text class="text-sm text-zinc-500 mb-1">Recurring Payment Day</flux:text>
                        <flux:text class="font-medium">Day {{ $recurringDay }} of each month</flux:text>
                    </div>
                    @endif
                </div>

                {{-- Confirmation --}}
                <div class="mt-6 p-4 bg-amber-50 dark:bg-amber-900/20 border border-amber-200 dark:border-amber-800 rounded-lg">
                    <flux:checkbox wire:model.live="confirmed" label="I confirm this payment plan has been authorized by the client." />
                </div>

                <div class="flex justify-between mt-6 pt-6 border-t border-zinc-200 dark:border-zinc-700">
                    <flux:button wire:click="previousStep" variant="ghost">Back</flux:button>
                    <flux:button
                        wire:click="createPlan"
                        variant="primary"
                        :disabled="!$confirmed || $processing"
                    >
                        @if($processing)
                            <flux:icon name="arrow-path" class="w-4 h-4 animate-spin mr-2" />
                            Creating...
                        @else
                            Create Payment Plan
                        @endif
                    </flux:button>
                </div>
            </div>
        </flux:card>
    @endif

    {{-- Step 6: Success --}}
    @if($currentStep === 6)
        <flux:card class="max-w-lg mx-auto">
            <div class="p-8 text-center">
                <div class="w-16 h-16 bg-green-100 dark:bg-green-900 rounded-full flex items-center justify-center mx-auto mb-4">
                    <flux:icon name="check" class="w-8 h-8 text-green-600 dark:text-green-400" />
                </div>
                <flux:heading size="xl" class="mb-2">Payment Plan Created!</flux:heading>
                <flux:text class="text-zinc-500 mb-6">
                    The payment plan has been successfully created and scheduled.
                </flux:text>

                @if($createdPlanId)
                    <div class="bg-zinc-50 dark:bg-zinc-800 rounded-lg p-4 mb-6">
                        <flux:text class="text-sm text-zinc-500">Plan ID</flux:text>
                        <flux:text class="font-mono">{{ $createdPlanId }}</flux:text>
                    </div>
                @endif

                <div class="flex justify-center gap-3">
                    <flux:button href="{{ route('admin.payment-plans') }}" variant="ghost">
                        View All Plans
                    </flux:button>
                    <flux:button wire:click="startOver" variant="primary">
                        Create Another
                    </flux:button>
                </div>
            </div>
        </flux:card>
    @endif
</div>
