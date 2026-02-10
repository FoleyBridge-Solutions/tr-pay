<div>
    <div class="mb-8">
        <flux:heading size="xl">Create Payment Plan</flux:heading>
        <flux:subheading>Set up a scheduled payment plan for a client</flux:subheading>
    </div>

    {{-- Progress Steps --}}
    @if($currentStep <= 5)
        <div class="mb-8">
            <div class="flex items-center justify-between max-w-3xl">
                @foreach(['Select Client', 'Select Invoices', 'Configure Plan', 'Payment Method', 'Review'] as $index => $stepName)
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

                <div class="flex gap-4 mb-4">
                    <div class="w-48">
                        <flux:select wire:model.live="searchType">
                            <option value="name">By Name</option>
                            <option value="client_id">By Client ID</option>
                            <option value="tax_id">By Tax ID (Last 4)</option>
                        </flux:select>
                    </div>
                    <div class="flex-1">
                        <flux:input
                            wire:model="searchQuery"
                            wire:keydown.enter="searchClients"
                            placeholder="{{ $searchType === 'name' ? 'Enter client name...' : ($searchType === 'client_id' ? 'Enter client ID...' : 'Enter last 4 digits of SSN/EIN...') }}"
                            icon="magnifying-glass"
                            maxlength="{{ $searchType === 'tax_id' ? '4' : '' }}"
                        />
                    </div>
                    <flux:button wire:click="searchClients" variant="primary">
                        Search
                    </flux:button>
                </div>

                {{-- Search Results --}}
                @if(count($searchResults) > 0)
                    <div class="border border-zinc-200 dark:border-zinc-700 rounded-lg overflow-hidden">
                        <flux:table>
                            <flux:table.columns>
                                <flux:table.column>Client ID</flux:table.column>
                                <flux:table.column>Name</flux:table.column>
                                <flux:table.column>Tax ID</flux:table.column>
                                <flux:table.column></flux:table.column>
                            </flux:table.columns>
                            <flux:table.rows>
                                @foreach($searchResults as $client)
                                    <flux:table.row wire:key="client-{{ $client['client_id'] }}">
                                        <flux:table.cell class="font-mono">{{ $client['client_id'] }}</flux:table.cell>
                                        <flux:table.cell>{{ $client['client_name'] }}</flux:table.cell>
                                        <flux:table.cell class="text-zinc-500">****{{ substr($client['federal_tin'] ?? '', -4) }}</flux:table.cell>
                                        <flux:table.cell>
                                            <flux:button wire:click="selectClient('{{ $client['client_id'] }}')" size="sm" variant="primary">
                                                Select
                                            </flux:button>
                                        </flux:table.cell>
                                    </flux:table.row>
                                @endforeach
                            </flux:table.rows>
                        </flux:table>
                    </div>
                @elseif($searchQuery && count($searchResults) === 0)
                    <div class="text-center py-8 text-zinc-500">
                        No clients found matching your search.
                    </div>
                @endif
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
                    <div class="flex gap-2">
                        <flux:button wire:click="selectAllInvoices" size="sm" variant="ghost">Select All</flux:button>
                        <flux:button wire:click="clearSelection" size="sm" variant="ghost">Clear</flux:button>
                    </div>
                </div>

                @if(count($availableInvoices) === 0)
                    <div class="text-center py-8 text-zinc-500">
                        No open invoices found for this client.
                    </div>
                @else
                    <div class="border border-zinc-200 dark:border-zinc-700 rounded-lg overflow-hidden mb-4">
                        <flux:table>
                            <flux:table.columns>
                                <flux:table.column class="w-10"></flux:table.column>
                                <flux:table.column>Invoice #</flux:table.column>
                                <flux:table.column>Date</flux:table.column>
                                <flux:table.column>Due Date</flux:table.column>
                                <flux:table.column>Type</flux:table.column>
                                <flux:table.column class="text-right">Amount</flux:table.column>
                            </flux:table.columns>
                            <flux:table.rows>
                                @foreach($availableInvoices as $invoice)
                                    <flux:table.row
                                        wire:key="invoice-{{ $invoice['ledger_entry_KEY'] }}"
                                        wire:click="toggleInvoice('{{ $invoice['ledger_entry_KEY'] }}')"
                                        class="cursor-pointer hover:bg-zinc-50 dark:hover:bg-zinc-800"
                                    >
                                        <flux:table.cell>
                                            <flux:checkbox
                                                :checked="in_array((string)$invoice['ledger_entry_KEY'], $selectedInvoices)"
                                                wire:click.stop="toggleInvoice('{{ $invoice['ledger_entry_KEY'] }}')"
                                            />
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

    {{-- Step 3: Configure Plan --}}
    @if($currentStep === 3)
        <flux:card class="max-w-2xl">
            <div class="p-6">
                <flux:heading size="lg" class="mb-6">Configure Payment Plan</flux:heading>

                {{-- Plan Duration --}}
                <flux:field class="mb-6">
                    <flux:label>Plan Duration</flux:label>
                    <flux:select wire:model.live="planDuration">
                        <option value="3">3 Months - $150 Fee</option>
                        <option value="6">6 Months - $300 Fee</option>
                        <option value="9">9 Months - $450 Fee</option>
                    </flux:select>
                </flux:field>

                {{-- Summary --}}
                <div class="bg-zinc-50 dark:bg-zinc-800 rounded-lg p-4 space-y-3">
                    <div class="flex justify-between">
                        <span class="text-zinc-600 dark:text-zinc-400">Invoice Total</span>
                        <span>${{ number_format($invoiceTotal, 2) }}</span>
                    </div>
                    <div class="flex justify-between">
                        <span class="text-zinc-600 dark:text-zinc-400">Plan Fee</span>
                        <span>${{ number_format($planFee, 2) }}</span>
                    </div>
                    <div class="border-t border-zinc-200 dark:border-zinc-700 pt-3 flex justify-between font-medium">
                        <span>Total Amount</span>
                        <span>${{ number_format($totalAmount, 2) }}</span>
                    </div>
                    <div class="border-t border-zinc-200 dark:border-zinc-700 pt-3 flex justify-between font-bold text-green-600 dark:text-green-400">
                        <span>Down Payment (30%) - Due Today</span>
                        <span>${{ number_format($downPayment, 2) }}</span>
                    </div>
                    <div class="flex justify-between text-lg font-bold text-blue-600">
                        <span>Monthly Payment (x{{ $planDuration }})</span>
                        <span>${{ number_format($monthlyPayment, 2) }}/month</span>
                    </div>
                </div>

                {{-- Split Down Payment Option (Admin Only) --}}
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

                {{-- Payment Schedule Preview --}}
                <div class="mt-6">
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

    {{-- Step 4: Payment Method --}}
    @if($currentStep === 4)
        <flux:card class="max-w-2xl">
            <div class="p-6">
                <flux:heading size="lg" class="mb-6">Payment Method</flux:heading>

                {{-- Payment Type Tabs --}}
                <div class="flex gap-2 mb-6">
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

                @if($paymentMethodType === 'card')
                    <div class="space-y-4">
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
                @else
                    <div class="space-y-4">
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
                            <span>${{ number_format($planFee, 2) }}</span>
                        </div>
                        <div class="border-t border-zinc-200 dark:border-zinc-700 pt-2 flex justify-between font-medium">
                            <span>Total Amount</span>
                            <span>${{ number_format($totalAmount, 2) }}</span>
                        </div>
                        <div class="flex justify-between text-lg font-bold text-blue-600">
                            <span>Monthly Payment</span>
                            <span>${{ number_format($monthlyPayment, 2) }}/month</span>
                        </div>
                    </div>

                    {{-- Payment Method --}}
                    <div>
                        <flux:text class="text-sm text-zinc-500 mb-1">Payment Method</flux:text>
                        @if($paymentMethodType === 'card')
                            <flux:text class="font-medium">Credit Card ending in {{ substr(preg_replace('/\D/', '', $cardNumber), -4) }}</flux:text>
                        @else
                            <flux:text class="font-medium">{{ ucfirst($accountType) }} Account ending in {{ substr($accountNumber, -4) }}</flux:text>
                        @endif
                    </div>

                    {{-- First Payment --}}
                    <div>
                        <flux:text class="text-sm text-zinc-500 mb-1">First Payment</flux:text>
                        <flux:text class="font-medium">{{ $this->paymentSchedule[0]['date'] ?? 'N/A' }}</flux:text>
                    </div>
                </div>

                {{-- Confirmation --}}
                <div class="mt-6 p-4 bg-amber-50 dark:bg-amber-900/20 border border-amber-200 dark:border-amber-800 rounded-lg">
                    <flux:checkbox wire:model="confirmed" label="I confirm this payment plan has been authorized by the client." />
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
