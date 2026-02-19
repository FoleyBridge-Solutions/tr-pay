<div>
    <div class="mb-8">
        <flux:heading size="xl">Create Single Payment</flux:heading>
        <flux:subheading>Process an immediate payment for a client</flux:subheading>
    </div>

    {{-- Progress Steps --}}
    @if($currentStep <= 3)
        <div class="mb-8">
            <div class="flex items-center justify-between max-w-3xl mx-auto">
                @foreach(['Select Client', 'Select Invoices', 'Payment & Review'] as $index => $stepName)
                    @php $stepNum = $index + 1; @endphp
                    <div class="flex items-center {{ $index < 2 ? 'flex-1' : '' }}">
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
                        @if($index < 3)
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
        <flux:card class="max-w-3xl mx-auto">
            <div class="p-6">
                <flux:heading size="lg" class="mb-4">Search for Client</flux:heading>
                <livewire:admin.client-search mode="select" />
            </div>
        </flux:card>
    @endif

    {{-- Step 2: Select Invoices & Amount --}}
    @if($currentStep === 2)
        <flux:card class="max-w-4xl mx-auto">
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

                {{-- Leave Unapplied Toggle --}}
                <div class="bg-blue-50 dark:bg-blue-900/20 border border-blue-200 dark:border-blue-800 rounded-lg p-4 mb-6">
                    <div class="flex items-center justify-between">
                        <div>
                            <flux:label>Leave Unapplied (Credit Balance)</flux:label>
                            <flux:description class="text-sm text-blue-700 dark:text-blue-300">
                                Payment will not be applied to any invoices and will remain as a credit on the client's account.
                            </flux:description>
                        </div>
                        <flux:switch wire:model.live="leaveUnapplied" />
                    </div>
                </div>

                @if($leaveUnapplied)
                    {{-- Unapplied Payment Amount Entry --}}
                    <div class="bg-zinc-50 dark:bg-zinc-800 rounded-lg p-4">
                        <flux:field>
                            <flux:label>Payment Amount</flux:label>
                            <flux:description class="text-sm text-zinc-500 mb-2">Enter the amount to charge. This will be recorded as a credit balance.</flux:description>
                            <div class="flex items-center gap-2">
                                <span class="text-lg text-zinc-500">$</span>
                                <flux:input 
                                    type="number" 
                                    wire:model.live.debounce.500ms="paymentAmount" 
                                    step="0.01" 
                                    min="0.01"
                                    class="max-w-xs"
                                    placeholder="0.00"
                                />
                            </div>
                        </flux:field>
                    </div>
                @else
                <div class="flex items-center justify-between mb-4">
                    <flux:heading size="lg">Select Invoices</flux:heading>
                    <flux:button wire:click="clearSelection" size="sm" variant="ghost">Clear</flux:button>
                </div>

                @if(count($availableInvoices) === 0)
                    <div class="text-center py-8 text-zinc-500">
                        No open invoices found for this client.
                    </div>
                @else
                    <div class="mb-4">
                        <x-invoice-selection-table :invoices="$availableInvoices" wire-model="selectedInvoices" />
                    </div>

                    {{-- Fee Requests (EXP Engagements) Section --}}
                    @if(count($pendingEngagements) > 0)
                        <div class="mt-6 mb-4">
                            <div class="flex items-center justify-between mb-4">
                                <div>
                                    <flux:heading size="lg">Fee Requests</flux:heading>
                                    <flux:text class="text-sm text-zinc-500">Pending engagement fee requests awaiting acceptance</flux:text>
                                </div>
                                <div class="flex gap-2">
                                    <flux:button wire:click="selectAllEngagements" size="sm" variant="ghost">Select All</flux:button>
                                    <flux:button wire:click="clearEngagementSelection" size="sm" variant="ghost">Clear</flux:button>
                                </div>
                            </div>

                            <div class="space-y-3">
                                @foreach($pendingEngagements as $engagement)
                                    @php $engagementKey = (int) $engagement['engagement_KEY']; @endphp
                                    @php $isSelected = in_array($engagementKey, $selectedEngagements, true); @endphp
                                    <div
                                        wire:key="engagement-{{ $engagementKey }}"
                                        wire:click="toggleEngagement({{ $engagementKey }})"
                                        class="border rounded-lg cursor-pointer transition-colors {{ $isSelected ? 'border-blue-500 bg-blue-50 dark:bg-blue-900/20' : 'border-zinc-200 dark:border-zinc-700 hover:border-zinc-300 dark:hover:border-zinc-600' }}"
                                    >
                                        {{-- Engagement header --}}
                                        <div class="flex items-center gap-3 p-4">
                                            <flux:checkbox
                                                :checked="$isSelected"
                                                wire:click.stop="toggleEngagement({{ $engagementKey }})"
                                            />
                                            <div class="flex-1 min-w-0">
                                                <div class="flex items-center gap-2">
                                                    <span class="font-medium">{{ $engagement['engagement_name'] }}</span>
                                                    <flux:badge size="sm" color="zinc">{{ $engagement['engagement_type'] }}</flux:badge>
                                                </div>
                                                <div class="text-sm text-zinc-500 mt-0.5">
                                                    {{ $engagement['group_name'] ?? $selectedClient['client_name'] }}
                                                    @if(count($engagement['projects']) > 1)
                                                        &middot; {{ count($engagement['projects']) }} projects
                                                    @endif
                                                </div>
                                            </div>
                                            <div class="text-right">
                                                <span class="font-bold text-lg">${{ number_format($engagement['total_budget'], 2) }}</span>
                                            </div>
                                        </div>

                                        {{-- Child projects (if multiple) --}}
                                        @if(count($engagement['projects']) > 1)
                                            <div class="border-t border-zinc-200 dark:border-zinc-700 px-4 py-2 bg-zinc-50/50 dark:bg-zinc-800/50 rounded-b-lg">
                                                <div class="text-xs font-medium text-zinc-400 uppercase tracking-wide mb-1">Projects</div>
                                                @foreach($engagement['projects'] as $project)
                                                    <div class="flex items-center justify-between py-1 text-sm">
                                                        <div class="flex items-center gap-2">
                                                            <span class="font-mono text-zinc-500">{{ $project['project_number'] }}</span>
                                                            @if($project['start_date'])
                                                                <span class="text-zinc-400">{{ date('m/d/Y', strtotime($project['start_date'])) }}</span>
                                                            @endif
                                                        </div>
                                                        <span class="text-zinc-600 dark:text-zinc-400">${{ number_format($project['budget_amount'], 2) }}</span>
                                                    </div>
                                                @endforeach
                                            </div>
                                        @endif
                                    </div>
                                @endforeach
                            </div>
                        </div>
                    @endif

                    {{-- Selected Total & Payment Amount --}}
                    <div class="bg-zinc-50 dark:bg-zinc-800 rounded-lg p-4 space-y-4">
                        @if(count($selectedInvoices) > 0 || count($selectedEngagements) > 0)
                            @if(count($selectedInvoices) > 0)
                                <div class="flex justify-between items-center">
                                    <span class="text-zinc-600 dark:text-zinc-400">Selected Invoices ({{ count($selectedInvoices) }}):</span>
                                    <span class="font-medium">${{ number_format($invoiceTotal, 2) }}</span>
                                </div>
                            @endif
                            @if(count($selectedEngagements) > 0)
                                <div class="flex justify-between items-center">
                                    <span class="text-zinc-600 dark:text-zinc-400">Selected Fee Requests ({{ count($selectedEngagements) }}):</span>
                                    <span class="font-medium">${{ number_format($engagementTotal, 2) }}</span>
                                </div>
                            @endif
                            @if(count($selectedInvoices) > 0 && count($selectedEngagements) > 0)
                                <div class="flex justify-between items-center border-t border-zinc-200 dark:border-zinc-700 pt-2">
                                    <span class="text-zinc-600 dark:text-zinc-400">Combined Total:</span>
                                    <span class="font-bold text-lg">${{ number_format($invoiceTotal + $engagementTotal, 2) }}</span>
                                </div>
                            @endif
                        @else
                            <div class="flex justify-between items-center">
                                <span class="text-zinc-600 dark:text-zinc-400">Selected Total:</span>
                                <span class="font-bold text-lg">$0.00</span>
                            </div>
                        @endif
                        
                        <div class="border-t border-zinc-200 dark:border-zinc-700 pt-4">
                            <div class="flex items-center justify-between mb-3">
                                <div>
                                    <flux:label>Custom Payment Amount</flux:label>
                                    <flux:description class="text-sm text-zinc-500">Enable to enter a partial payment amount</flux:description>
                                </div>
                                <flux:switch wire:model.live="customPaymentAmount" />
                            </div>

                            @if($customPaymentAmount)
                                <flux:field>
                                    <flux:label>Payment Amount</flux:label>
                                    <div class="flex items-center gap-2">
                                        <span class="text-lg text-zinc-500">$</span>
                                        <flux:input 
                                            type="number" 
                                            wire:model.live.debounce.500ms="paymentAmount" 
                                            step="0.01" 
                                            min="0.01" 
                                            :max="$invoiceTotal + $engagementTotal"
                                            class="max-w-xs"
                                        />
                                    </div>
                                </flux:field>
                            @else
                                <div class="flex items-center gap-2">
                                    <span class="text-zinc-600 dark:text-zinc-400">Payment Amount:</span>
                                    <span class="font-bold">${{ number_format($paymentAmount, 2) }}</span>
                                </div>
                            @endif
                        </div>

                        @php $maxAmount = $invoiceTotal + $engagementTotal; @endphp
                        @if($paymentAmount < $maxAmount && $paymentAmount > 0)
                            <div class="text-amber-600 dark:text-amber-400 text-sm flex items-center gap-2">
                                <flux:icon name="exclamation-triangle" class="w-4 h-4" />
                                Partial payment: ${{ number_format($maxAmount - $paymentAmount, 2) }} will remain due
                            </div>
                        @endif
                    </div>
                @endif
                @endif

                {{-- Schedule for Later --}}
                <div class="bg-zinc-50 dark:bg-zinc-800 rounded-lg p-4 mt-4">
                    <div class="flex items-center justify-between">
                        <div>
                            <flux:label>Schedule for Later</flux:label>
                            <flux:description class="text-sm text-zinc-500">Schedule this payment to process on a future date</flux:description>
                        </div>
                        <flux:switch wire:model.live="scheduleForLater" />
                    </div>

                    @if($scheduleForLater)
                        <div class="mt-4 pt-4 border-t border-zinc-200 dark:border-zinc-700">
                            <flux:field>
                                <flux:label>Scheduled Date</flux:label>
                                <flux:input 
                                    type="date" 
                                    wire:model.live="scheduledDate"
                                    min="{{ now()->addDay()->format('Y-m-d') }}"
                                    class="max-w-xs"
                                />
                            </flux:field>

                            @if($savedPaymentMethods->count() === 0)
                                <div class="mt-4 p-3 bg-amber-50 dark:bg-amber-900/20 border border-amber-200 dark:border-amber-800 rounded-lg">
                                    <div class="flex items-start gap-2">
                                        <flux:icon name="exclamation-triangle" class="w-5 h-5 text-amber-600 dark:text-amber-400 mt-0.5" />
                                        <div class="text-sm text-amber-700 dark:text-amber-300">
                                            <strong>No saved payment methods.</strong><br>
                                            Scheduled payments require a saved payment method. The client must first save a payment method through the payment portal.
                                        </div>
                                    </div>
                                </div>
                            @else
                                <div class="mt-3 text-sm text-zinc-500 flex items-center gap-2">
                                    <flux:icon name="information-circle" class="w-4 h-4" />
                                    Payment will be charged to the client's saved payment method on {{ $scheduledDate ? date('F j, Y', strtotime($scheduledDate)) : 'the selected date' }}.
                                </div>
                            @endif
                        </div>
                    @endif
                </div>

                <div class="flex justify-between mt-6 pt-6 border-t border-zinc-200 dark:border-zinc-700">
                    <flux:button wire:click="previousStep" variant="ghost">Back</flux:button>
                    <flux:button wire:click="nextStep" variant="primary" :disabled="(!$leaveUnapplied && count($selectedInvoices) === 0 && count($selectedEngagements) === 0) || $paymentAmount <= 0 || ($scheduleForLater && $savedPaymentMethods->count() === 0)">
                        Continue
                    </flux:button>
                </div>
            </div>
        </flux:card>
    @endif

    {{-- Step 3: Payment Method --}}
    @if($currentStep === 3)
        <flux:card class="max-w-2xl mx-auto">
            <div class="p-6">
                <flux:heading size="lg" class="mb-6">Payment Method</flux:heading>

                {{-- Payment Type Tabs --}}
                <div class="flex gap-2 mb-6">
                    @if($scheduleForLater)
                        {{-- Scheduled payments must use saved payment method --}}
                        <flux:button
                            wire:click="$set('paymentMethodType', 'saved')"
                            :variant="$paymentMethodType === 'saved' ? 'primary' : 'ghost'"
                        >
                            Saved Payment Method
                        </flux:button>
                    @else
                        {{-- Immediate payments can use new card/ACH or saved --}}
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
                        @if($savedPaymentMethods->count() > 0)
                            <flux:button
                                wire:click="$set('paymentMethodType', 'saved')"
                                :variant="$paymentMethodType === 'saved' ? 'primary' : 'ghost'"
                            >
                                Saved Method
                            </flux:button>
                        @endif
                    @endif
                </div>

                {{-- Fee Notice --}}
                @php
                    $showCardFee = $paymentMethodType === 'card' || 
                        ($paymentMethodType === 'saved' && $savedPaymentMethodId && $savedPaymentMethods->firstWhere('id', $savedPaymentMethodId)?->type === 'card');
                @endphp
                @if($showCardFee)
                    <div class="bg-amber-50 dark:bg-amber-900/20 border border-amber-200 dark:border-amber-800 rounded-lg p-4 mb-6">
                        <div class="flex items-start gap-3">
                            <flux:icon name="information-circle" class="w-5 h-5 text-amber-600 dark:text-amber-400 mt-0.5" />
                            <div>
                                <div class="font-medium text-amber-800 dark:text-amber-200">Non-Cash Adjustment</div>
                                @if($feeIncludedInCustomAmount && $customPaymentAmount)
                                    <div class="text-sm text-amber-700 dark:text-amber-300">
                                        The entered amount of ${{ number_format($paymentAmount, 2) }} includes the {{ number_format($this->getCreditCardFeeRate() * 100, 1) }}% fee.
                                        <br>
                                        <strong>Base payment:</strong> ${{ number_format($this->getBasePaymentAmount(), 2) }} | 
                                        <strong>Fee:</strong> ${{ number_format($this->getCreditCardFee(), 2) }}
                                    </div>
                                @else
                                    <div class="text-sm text-amber-700 dark:text-amber-300">
                                        A {{ number_format($this->getCreditCardFeeRate() * 100, 1) }}% non-cash adjustment of ${{ number_format($this->getCreditCardFee(), 2) }} will be added to the payment.
                                    </div>
                                @endif
                            </div>
                        </div>
                    </div>

                    {{-- Fee Included Toggle (only for custom amount with card) --}}
                    @if($customPaymentAmount)
                        <div class="bg-blue-50 dark:bg-blue-900/20 border border-blue-200 dark:border-blue-800 rounded-lg p-4 mb-6">
                            <div class="flex items-center justify-between">
                                <div>
                                    <flux:label>Amount Includes Fee</flux:label>
                                    <flux:description class="text-sm text-blue-700 dark:text-blue-300">
                                        Enable if the custom amount you entered already includes the credit card fee
                                    </flux:description>
                                </div>
                                <flux:switch wire:model.live="feeIncludedInCustomAmount" />
                            </div>
                        </div>
                    @endif
                @elseif($paymentMethodType !== 'saved' || ($savedPaymentMethodId && $savedPaymentMethods->firstWhere('id', $savedPaymentMethodId)))
                    <div class="bg-green-50 dark:bg-green-900/20 border border-green-200 dark:border-green-800 rounded-lg p-4 mb-6">
                        <div class="flex items-start gap-3">
                            <flux:icon name="check-circle" class="w-5 h-5 text-green-600 dark:text-green-400 mt-0.5" />
                            <div>
                                <div class="font-medium text-green-800 dark:text-green-200">No Fee</div>
                                <div class="text-sm text-green-700 dark:text-green-300">
                                    ACH/eCheck payments have no additional fees.
                                </div>
                            </div>
                        </div>
                    </div>
                @endif

                @if($paymentMethodType === 'card')
                    <x-payment-method-fields
                        type="card"
                        :show-card-name="true"
                    />
                @elseif($paymentMethodType === 'ach')
                    <x-payment-method-fields
                        type="ach"
                        :show-account-name="true"
                        :show-is-business="true"
                        account-type-model="accountType"
                    />
                @elseif($paymentMethodType === 'saved')
                    <div wire:key="payment-fields-saved" class="space-y-4">
                        @if($savedPaymentMethods->count() === 0)
                            <div class="text-center py-8 text-zinc-500">
                                <flux:icon name="credit-card" class="w-12 h-12 mx-auto mb-2 opacity-50" />
                                <p>No saved payment methods found for this client.</p>
                            </div>
                        @else
                            <flux:label class="mb-3">Select a saved payment method</flux:label>
                            <div class="space-y-2">
                                @foreach($savedPaymentMethods as $method)
                                    <label 
                                        wire:key="method-{{ $method->id }}"
                                        class="flex items-center gap-4 p-4 border rounded-lg transition-colors {{ $savedPaymentMethodId === $method->id ? 'border-blue-500 bg-blue-50 dark:bg-blue-900/20' : 'border-zinc-200 dark:border-zinc-700 hover:border-zinc-300 dark:hover:border-zinc-600' }} cursor-pointer"
                                    >
                                        <input 
                                            type="radio" 
                                            wire:model.live="savedPaymentMethodId" 
                                            value="{{ $method->id }}"
                                            class="w-4 h-4 text-blue-600"
                                        />
                                        <div class="flex items-center gap-3 flex-1">
                                            @if($method->type === 'card')
                                                <flux:icon name="credit-card" class="w-6 h-6 text-zinc-500" />
                                                <div>
                                                    <div class="font-medium">
                                                        {{ ucfirst($method->card_brand ?? 'Card') }} ending in {{ $method->last_four }}
                                                    </div>
                                                    <div class="text-sm text-zinc-500">
                                                        Expires {{ $method->exp_month }}/{{ $method->exp_year }}
                                                    </div>
                                                </div>
                                            @else
                                                <flux:icon name="building-library" class="w-6 h-6 text-zinc-500" />
                                                <div>
                                                    <div class="font-medium">
                                                        {{ ucfirst($method->account_type ?? 'Bank') }} Account ending in {{ $method->last_four }}
                                                    </div>
                                                    <div class="text-sm text-zinc-500">ACH/eCheck</div>
                                                </div>
                                            @endif
                                        </div>
                                        @if($method->is_default)
                                            <flux:badge size="sm" color="green">Default</flux:badge>
                                        @endif
                                    </label>
                                @endforeach
                            </div>
                        @endif
                    </div>
                @endif

                {{-- Payment Summary --}}
                <div class="mt-6 bg-zinc-50 dark:bg-zinc-800 rounded-lg p-4 space-y-2">
                    @if($feeIncludedInCustomAmount && $customPaymentAmount && $showCardFee)
                        {{-- Fee-included mode: show breakdown --}}
                        <div class="flex justify-between">
                            <span class="text-zinc-600 dark:text-zinc-400">Amount Entered (includes fee):</span>
                            <span>${{ number_format($paymentAmount, 2) }}</span>
                        </div>
                        <div class="flex justify-between text-sm">
                            <span class="text-zinc-500 dark:text-zinc-400 pl-4">Base Payment Applied:</span>
                            <span class="text-zinc-600">${{ number_format($this->getBasePaymentAmount(), 2) }}</span>
                        </div>
                        <div class="flex justify-between text-sm">
                            <span class="text-zinc-500 dark:text-zinc-400 pl-4">Fee Portion ({{ number_format($this->getCreditCardFeeRate() * 100, 1) }}%):</span>
                            <span class="text-zinc-600">${{ number_format($this->getCreditCardFee(), 2) }}</span>
                        </div>
                        <div class="border-t border-zinc-200 dark:border-zinc-700 pt-2 flex justify-between font-bold text-lg">
                            <span>Total Charge:</span>
                            <span class="text-green-600">${{ number_format($this->getTotalCharge(), 2) }}</span>
                        </div>
                    @else
                        {{-- Standard mode: base + fee --}}
                        <div class="flex justify-between">
                            <span class="text-zinc-600 dark:text-zinc-400">Payment Amount:</span>
                            <span>${{ number_format($paymentAmount, 2) }}</span>
                        </div>
                        @if($showCardFee)
                            <div class="flex justify-between">
                                <span class="text-zinc-600 dark:text-zinc-400">Non-Cash Adjustment ({{ number_format($this->getCreditCardFeeRate() * 100, 1) }}%):</span>
                                <span>+${{ number_format($this->getCreditCardFee(), 2) }}</span>
                            </div>
                        @endif
                        <div class="border-t border-zinc-200 dark:border-zinc-700 pt-2 flex justify-between font-bold text-lg">
                            <span>Total Charge:</span>
                            <span class="text-green-600">${{ number_format($this->getTotalCharge(), 2) }}</span>
                        </div>
                    @endif
                </div>

                {{-- Confirmation --}}
                <div class="mt-6 p-4 bg-amber-50 dark:bg-amber-900/20 border border-amber-200 dark:border-amber-800 rounded-lg">
                    <flux:field variant="inline">
                        <flux:checkbox wire:model.live="confirmed" />
                        <flux:label>
                            I confirm this {{ $scheduleForLater ? 'scheduled payment' : 'payment' }} has been authorized by the client and a signed authorization form is on file.
                        </flux:label>
                        <flux:error name="confirmed" />
                    </flux:field>
                </div>

                <div class="flex justify-between mt-6 pt-6 border-t border-zinc-200 dark:border-zinc-700">
                    <flux:button wire:click="previousStep" variant="ghost">Back</flux:button>
                    <flux:button
                        wire:click="processPayment"
                        variant="primary"
                        :disabled="!$confirmed || $processing"
                    >
                        @if($processing)
                            <flux:icon name="arrow-path" class="w-4 h-4 animate-spin mr-2" />
                            {{ $scheduleForLater ? 'Scheduling...' : 'Processing...' }}
                        @else
                            @if($scheduleForLater)
                                <flux:icon name="calendar" class="w-4 h-4 mr-2" />
                                Schedule Payment
                            @else
                                Process Payment
                            @endif
                        @endif
                    </flux:button>
                </div>
            </div>
        </flux:card>
    @endif

    {{-- Step 4: Success --}}
    @if($currentStep === 4)
        <flux:card class="max-w-lg mx-auto">
            <div class="p-8 text-center">
                @if($scheduleForLater)
                    {{-- Scheduled Payment Success --}}
                    <div class="w-16 h-16 bg-blue-100 dark:bg-blue-900 rounded-full flex items-center justify-center mx-auto mb-4">
                        <flux:icon name="calendar-days" class="w-8 h-8 text-blue-600 dark:text-blue-400" />
                    </div>
                    <flux:heading size="xl" class="mb-2">Payment Scheduled!</flux:heading>
                    <flux:text class="text-zinc-500 mb-6">
                        The payment has been scheduled for <strong>{{ date('F j, Y', strtotime($scheduledDate)) }}</strong>.
                    </flux:text>
                @else
                    {{-- Immediate Payment Success --}}
                    <div class="w-16 h-16 bg-green-100 dark:bg-green-900 rounded-full flex items-center justify-center mx-auto mb-4">
                        <flux:icon name="check" class="w-8 h-8 text-green-600 dark:text-green-400" />
                    </div>
                    <flux:heading size="xl" class="mb-2">Payment Successful!</flux:heading>
                    <flux:text class="text-zinc-500 mb-6">
                        The payment has been processed and recorded.
                    </flux:text>
                @endif

                @if($transactionId)
                    <div class="bg-zinc-50 dark:bg-zinc-800 rounded-lg p-4 mb-6">
                        <flux:text class="text-sm text-zinc-500">{{ $scheduleForLater ? 'Reference ID' : 'Transaction ID' }}</flux:text>
                        <flux:text class="font-mono">{{ $transactionId }}</flux:text>
                    </div>
                @endif

                <div class="bg-zinc-50 dark:bg-zinc-800 rounded-lg p-4 mb-6 space-y-2 text-left">
                    <div class="flex justify-between">
                        <span class="text-zinc-500">Date:</span>
                        <span class="font-medium"><local-time datetime="{{ now()->toIso8601String() }}" format="date"></local-time></span>
                    </div>
                    @php $successShowCardFee = $this->getCreditCardFee() > 0; @endphp
                    @if($feeIncludedInCustomAmount && $customPaymentAmount && $successShowCardFee)
                        {{-- Fee-included mode --}}
                        <div class="flex justify-between">
                            <span class="text-zinc-500">{{ $scheduleForLater ? 'Amount Entered (includes fee):' : 'Amount Charged (includes fee):' }}</span>
                            <span class="font-medium">${{ number_format($paymentAmount, 2) }}</span>
                        </div>
                        <div class="flex justify-between text-sm">
                            <span class="text-zinc-400 pl-4">Base Payment Applied:</span>
                            <span>${{ number_format($this->getBasePaymentAmount(), 2) }}</span>
                        </div>
                        <div class="flex justify-between text-sm">
                            <span class="text-zinc-400 pl-4">Fee Portion:</span>
                            <span>${{ number_format($this->getCreditCardFee(), 2) }}</span>
                        </div>
                    @else
                        {{-- Standard mode --}}
                        <div class="flex justify-between">
                            <span class="text-zinc-500">{{ $scheduleForLater ? 'Amount to Pay:' : 'Amount Paid:' }}</span>
                            <span class="font-medium">${{ number_format($this->getBasePaymentAmount(), 2) }}</span>
                        </div>
                        @if($successShowCardFee)
                            <div class="flex justify-between">
                                <span class="text-zinc-500">Fee:</span>
                                <span class="font-medium">${{ number_format($this->getCreditCardFee(), 2) }}</span>
                            </div>
                        @endif
                    @endif
                    <div class="flex justify-between border-t border-zinc-200 dark:border-zinc-700 pt-2">
                        <span class="text-zinc-500">{{ $scheduleForLater ? 'Total to Charge:' : 'Total Charged:' }}</span>
                        <span class="font-bold {{ $scheduleForLater ? 'text-blue-600' : 'text-green-600' }}">${{ number_format($this->getTotalCharge(), 2) }}</span>
                    </div>
                    @if($scheduleForLater)
                        <div class="flex justify-between border-t border-zinc-200 dark:border-zinc-700 pt-2">
                            <span class="text-zinc-500">Scheduled Date:</span>
                            <span class="font-medium">{{ date('F j, Y', strtotime($scheduledDate)) }}</span>
                        </div>
                    @endif
                </div>

                {{-- Show accepted fee requests summary --}}
                @if(count($selectedEngagements) > 0)
                    <div class="bg-green-50 dark:bg-green-900/20 border border-green-200 dark:border-green-800 rounded-lg p-4 mb-6 text-left">
                        <div class="flex items-start gap-2">
                            <flux:icon name="check-circle" class="w-5 h-5 text-green-600 dark:text-green-400 mt-0.5 flex-shrink-0" />
                            <div class="text-sm text-green-700 dark:text-green-300">
                                <strong>{{ count($selectedEngagements) }} fee request(s) accepted</strong><br>
                                The selected fee requests have been automatically accepted on behalf of the client.
                            </div>
                        </div>
                    </div>
                @endif

                @if($scheduleForLater)
                    <div class="bg-blue-50 dark:bg-blue-900/20 border border-blue-200 dark:border-blue-800 rounded-lg p-4 mb-6 text-left text-sm text-blue-700 dark:text-blue-300">
                        <div class="flex items-start gap-2">
                            <flux:icon name="information-circle" class="w-5 h-5 mt-0.5 flex-shrink-0" />
                            <div>
                                The payment will be automatically processed on the scheduled date. You can view and manage scheduled payments from the Payments page.
                            </div>
                        </div>
                    </div>
                @endif

                <div class="flex justify-center gap-3">
                    <flux:button href="{{ route('admin.payments') }}" variant="ghost">
                        View All Payments
                    </flux:button>
                    <flux:button wire:click="startOver" variant="primary">
                        Create Another
                    </flux:button>
                </div>
            </div>
        </flux:card>
    @endif
</div>
