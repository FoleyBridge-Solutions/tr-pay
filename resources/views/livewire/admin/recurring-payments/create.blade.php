<div>
    <div class="mb-8">
        <flux:heading size="xl">Create Recurring Payment</flux:heading>
        <flux:subheading>Set up a new recurring payment schedule</flux:subheading>
    </div>

    @if($createdPayment)
        {{-- Success State --}}
        <flux:card class="max-w-lg mx-auto">
            <div class="p-8 text-center">
                @if($createdPayment->status === 'pending')
                    <div class="w-16 h-16 bg-amber-100 dark:bg-amber-900 rounded-full flex items-center justify-center mx-auto mb-4">
                        <flux:icon name="clock" class="w-8 h-8 text-amber-600 dark:text-amber-400" />
                    </div>
                    <flux:heading size="xl" class="mb-2">Payment Created!</flux:heading>
                    <flux:text class="text-zinc-500 mb-6">
                        Recurring payment created with <strong>pending</strong> status. A payment method must be added before payments can be processed.
                    </flux:text>
                @else
                    <div class="w-16 h-16 bg-green-100 dark:bg-green-900 rounded-full flex items-center justify-center mx-auto mb-4">
                        <flux:icon name="check" class="w-8 h-8 text-green-600 dark:text-green-400" />
                    </div>
                    <flux:heading size="xl" class="mb-2">Payment Created!</flux:heading>
                    <flux:text class="text-zinc-500 mb-6">
                        Recurring payment has been set up successfully.
                    </flux:text>
                @endif

                <div class="bg-zinc-50 dark:bg-zinc-800 rounded-lg p-4 mb-6 text-left">
                    <div class="grid grid-cols-2 gap-2 text-sm">
                        <span class="text-zinc-500">Client:</span>
                        <span class="font-medium">{{ $createdPayment->client_name }}</span>
                        <span class="text-zinc-500">Amount:</span>
                        <span class="font-medium">${{ number_format($createdPayment->amount, 2) }}</span>
                        <span class="text-zinc-500">Frequency:</span>
                        <span>{{ $createdPayment->frequency_label }}</span>
                        <span class="text-zinc-500">Status:</span>
                        <span>
                            @if($createdPayment->status === 'pending')
                                <flux:badge color="amber" size="sm">Pending</flux:badge>
                            @else
                                <flux:badge color="green" size="sm">Active</flux:badge>
                            @endif
                        </span>
                        @if($createdPayment->next_payment_date)
                            <span class="text-zinc-500">Next Payment:</span>
                            <span><local-time datetime="{{ $createdPayment->next_payment_date->toIso8601String() }}" format="date"></local-time></span>
                        @endif
                    </div>
                </div>

                <div class="flex justify-center gap-3">
                    <flux:button href="{{ route('admin.recurring-payments') }}" variant="ghost">
                        View All
                    </flux:button>
                    <flux:button wire:click="createAnother" variant="primary">
                        Create Another
                    </flux:button>
                </div>
            </div>
        </flux:card>
    @else
        {{-- Form --}}
        <div class="max-w-2xl">
            @if($errorMessage)
                <flux:callout variant="danger" icon="exclamation-triangle" class="mb-6">
                    {{ $errorMessage }}
                </flux:callout>
            @endif

            <form wire:submit="create" class="space-y-6">
                {{-- Client Selection --}}
                <flux:card>
                    <div class="p-6">
                        <flux:heading size="md" class="mb-4">Client</flux:heading>

                        @if($selectedClient)
                            <div class="bg-zinc-50 dark:bg-zinc-800 rounded-lg p-4 flex items-center justify-between">
                                <div>
                                    <flux:text class="font-medium">{{ $selectedClient['client_name'] }}</flux:text>
                                    <flux:text class="text-sm text-zinc-500">ID: {{ $selectedClient['client_id'] }}</flux:text>
                                </div>
                                <flux:button wire:click="clearClient" variant="ghost" size="sm" icon="x-mark">
                                    Change
                                </flux:button>
                            </div>
                        @else
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
                                <flux:button wire:click="searchClients" type="button">
                                    Search
                                </flux:button>
                            </div>

                            @if(count($searchResults) > 0)
                                <div class="border border-zinc-200 dark:border-zinc-700 rounded-lg overflow-hidden max-h-48 overflow-y-auto">
                                    @foreach($searchResults as $client)
                                        <button
                                            type="button"
                                            wire:click="selectClient('{{ $client['client_id'] }}')"
                                            class="w-full px-4 py-2 text-left hover:bg-zinc-50 dark:hover:bg-zinc-800 border-b border-zinc-200 dark:border-zinc-700 last:border-b-0"
                                        >
                                            <span class="font-medium">{{ $client['client_name'] }}</span>
                                            <span class="text-zinc-500 text-sm ml-2">{{ $client['client_id'] }}</span>
                                        </button>
                                    @endforeach
                                </div>
                            @endif
                        @endif
                    </div>
                </flux:card>

                {{-- Payment Details --}}
                <flux:card>
                    <div class="p-6">
                        <flux:heading size="md" class="mb-4">Payment Details</flux:heading>

                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <flux:field>
                                <flux:label>Amount</flux:label>
                                <flux:input wire:model="amount" type="number" step="0.01" min="0.01" placeholder="0.00" icon="currency-dollar" />
                                <flux:error name="amount" />
                            </flux:field>

                            <flux:field>
                                <flux:label>Frequency</flux:label>
                                <flux:select wire:model="frequency">
                                    @foreach($this->frequencies as $value => $label)
                                        <option value="{{ $value }}">{{ $label }}</option>
                                    @endforeach
                                </flux:select>
                                <flux:error name="frequency" />
                            </flux:field>

                            <flux:field>
                                <flux:label>Start Date</flux:label>
                                <flux:input wire:model="startDate" type="date" />
                                <flux:error name="startDate" />
                            </flux:field>

                            <flux:field>
                                <flux:label>End Date (Optional)</flux:label>
                                <flux:input wire:model="endDate" type="date" />
                                <flux:description>Leave blank for no end date</flux:description>
                                <flux:error name="endDate" />
                            </flux:field>

                            <flux:field>
                                <flux:label>Max Occurrences (Optional)</flux:label>
                                <flux:input wire:model="maxOccurrences" type="number" min="1" placeholder="e.g., 12" />
                                <flux:description>Number of payments before auto-completion</flux:description>
                                <flux:error name="maxOccurrences" />
                            </flux:field>
                        </div>

                        <flux:field class="mt-4">
                            <flux:label>Description (Optional)</flux:label>
                            <flux:input wire:model="description" placeholder="e.g., Monthly retainer, Quarterly service fee" />
                            <flux:error name="description" />
                        </flux:field>
                    </div>
                </flux:card>

                {{-- Payment Method --}}
                <flux:card>
                    <div class="p-6">
                        <flux:heading size="md" class="mb-4">Payment Method</flux:heading>

                        <div class="flex gap-2 mb-6">
                            <flux:button
                                type="button"
                                wire:click="$set('paymentMethodType', 'card')"
                                :variant="$paymentMethodType === 'card' ? 'primary' : 'ghost'"
                            >
                                Credit Card
                            </flux:button>
                            <flux:button
                                type="button"
                                wire:click="$set('paymentMethodType', 'ach')"
                                :variant="$paymentMethodType === 'ach' ? 'primary' : 'ghost'"
                            >
                                Bank Account (ACH)
                            </flux:button>
                            @if($savedPaymentMethods->count() > 0)
                                <flux:button
                                    type="button"
                                    wire:click="$set('paymentMethodType', 'saved')"
                                    :variant="$paymentMethodType === 'saved' ? 'primary' : 'ghost'"
                                >
                                    Saved Method
                                </flux:button>
                            @endif
                            <flux:button
                                type="button"
                                wire:click="$set('paymentMethodType', 'none')"
                                :variant="$paymentMethodType === 'none' ? 'primary' : 'ghost'"
                            >
                                Add Later
                            </flux:button>
                        </div>

                        @if($paymentMethodType === 'card')
                            <div wire:key="payment-fields-card" class="space-y-4">
                                <flux:field>
                                    <flux:label>Card Number</flux:label>
                                    <flux:input wire:model="cardNumber" placeholder="1234 5678 9012 3456" />
                                    <flux:error name="cardNumber" />
                                </flux:field>

                                <div class="grid grid-cols-2 gap-4">
                                    <flux:field>
                                        <flux:label>Expiry Date</flux:label>
                                        <flux:input wire:model="cardExpiry" placeholder="MM/YY" />
                                        <flux:error name="cardExpiry" />
                                    </flux:field>
                                    <flux:field>
                                        <flux:label>CVV (Optional)</flux:label>
                                        <flux:input wire:model="cardCvv" type="password" placeholder="123" />
                                    </flux:field>
                                </div>

                                <flux:field>
                                    <flux:label>Name on Card (Optional)</flux:label>
                                    <flux:input wire:model="cardName" placeholder="John Doe" />
                                </flux:field>
                            </div>
                        @elseif($paymentMethodType === 'ach')
                            <div wire:key="payment-fields-ach" class="space-y-4">
                                <flux:field>
                                    <flux:label>Account Holder Name (Optional)</flux:label>
                                    <flux:input wire:model="accountName" placeholder="John Doe" />
                                </flux:field>

                                <flux:field>
                                    <flux:label>Routing Number</flux:label>
                                    <flux:input wire:model="routingNumber" placeholder="123456789" />
                                    <flux:error name="routingNumber" />
                                </flux:field>

                                <flux:field>
                                    <flux:label>Account Number</flux:label>
                                    <flux:input wire:model="accountNumber" placeholder="1234567890" />
                                    <flux:error name="accountNumber" />
                                </flux:field>

                                <flux:field>
                                    <flux:label>Account Type</flux:label>
                                    <flux:select wire:model="accountType">
                                        <option value="checking">Checking</option>
                                        <option value="savings">Savings</option>
                                    </flux:select>
                                </flux:field>
                            </div>
                        @elseif($paymentMethodType === 'saved')
                            <div wire:key="payment-fields-saved" class="space-y-3">
                                @if($savedPaymentMethods->count() === 0)
                                    <div class="text-center py-8 text-zinc-500">
                                        <flux:icon name="credit-card" class="w-12 h-12 mx-auto mb-2 opacity-50" />
                                        <p>No saved payment methods found for this client.</p>
                                    </div>
                                @else
                                    <flux:label class="mb-3">Select a saved payment method</flux:label>
                                    @foreach($savedPaymentMethods as $method)
                                        <label 
                                            wire:key="method-{{ $method->id }}"
                                            class="flex items-center gap-4 p-4 border rounded-lg cursor-pointer transition-colors {{ $savedPaymentMethodId === $method->id ? 'border-blue-500 bg-blue-50 dark:bg-blue-900/20' : 'border-zinc-200 dark:border-zinc-700 hover:border-zinc-300 dark:hover:border-zinc-600' }} {{ $method->isExpired() ? 'opacity-50' : '' }}"
                                        >
                                            <input 
                                                type="radio" 
                                                wire:model.live="savedPaymentMethodId" 
                                                value="{{ $method->id }}"
                                                class="w-4 h-4 text-blue-600"
                                                {{ $method->isExpired() ? 'disabled' : '' }}
                                            />
                                            <div class="flex items-center gap-3 flex-1">
                                                @if($method->type === 'card')
                                                    <flux:icon name="credit-card" class="w-6 h-6 text-zinc-500" />
                                                    <div>
                                                        <div class="font-medium">
                                                            {{ $method->brand ?? 'Card' }} ending in {{ $method->last_four }}
                                                        </div>
                                                        <div class="text-sm text-zinc-500">
                                                            Expires {{ $method->exp_month }}/{{ $method->exp_year }}
                                                        </div>
                                                    </div>
                                                @else
                                                    <flux:icon name="building-library" class="w-6 h-6 text-zinc-500" />
                                                    <div>
                                                        <div class="font-medium">
                                                            {{ $method->bank_name ?? 'Bank Account' }} ending in {{ $method->last_four }}
                                                        </div>
                                                        <div class="text-sm text-zinc-500">ACH/eCheck</div>
                                                    </div>
                                                @endif
                                            </div>
                                            <div class="flex items-center gap-2">
                                                @if($method->is_default)
                                                    <flux:badge size="sm" color="green">Default</flux:badge>
                                                @endif
                                                @if($method->isExpired())
                                                    <flux:badge size="sm" color="red">Expired</flux:badge>
                                                @elseif($method->isExpiringSoon())
                                                    <flux:badge size="sm" color="amber">Expiring Soon</flux:badge>
                                                @endif
                                            </div>
                                        </label>
                                    @endforeach
                                @endif
                            </div>
                        @elseif($paymentMethodType === 'none')
                            <div class="bg-amber-50 dark:bg-amber-900/20 border border-amber-200 dark:border-amber-800 rounded-lg p-4">
                                <div class="flex gap-3">
                                    <flux:icon name="exclamation-triangle" class="w-5 h-5 text-amber-600 dark:text-amber-400 flex-shrink-0 mt-0.5" />
                                    <div>
                                        <p class="font-medium text-amber-800 dark:text-amber-200">No payment method will be set</p>
                                        <p class="text-sm text-amber-700 dark:text-amber-300 mt-1">
                                            This recurring payment will be created with a <strong>pending</strong> status. 
                                            Payments will not be processed until a payment method is added.
                                        </p>
                                    </div>
                                </div>
                            </div>
                        @endif
                    </div>
                </flux:card>

                {{-- Actions --}}
                <div class="flex justify-between">
                    <flux:button href="{{ route('admin.recurring-payments') }}" variant="ghost">
                        Cancel
                    </flux:button>
                    <flux:button type="submit" variant="primary" :disabled="$processing || !$selectedClient">
                        @if($processing)
                            <flux:icon name="arrow-path" class="w-4 h-4 animate-spin mr-2" />
                            Creating...
                        @else
                            Create Recurring Payment
                        @endif
                    </flux:button>
                </div>
            </form>
        </div>
    @endif
</div>
