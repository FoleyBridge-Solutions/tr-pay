<div>
    <div class="mb-8">
        <div class="flex items-center gap-2 text-sm text-zinc-500 mb-2">
            <a href="{{ route('admin.clients') }}" class="hover:text-zinc-700 dark:hover:text-zinc-300">Clients</a>
            <flux:icon name="chevron-right" class="w-4 h-4" />
            <span>Payment Methods</span>
        </div>
        <div class="flex items-center justify-between">
            <div>
                <flux:heading size="xl">Manage Payment Methods</flux:heading>
                @if($clientInfo)
                    <flux:subheading>{{ $clientInfo['client_name'] }} ({{ $clientInfo['client_id'] }})</flux:subheading>
                @endif
            </div>
            @if($clientInfo && !$showAddForm)
                <flux:button wire:click="toggleAddForm" variant="primary" icon="plus">
                    Add Payment Method
                </flux:button>
            @endif
        </div>
    </div>

    {{-- Error Message --}}
    @if($errorMessage)
        <flux:callout variant="danger" icon="exclamation-triangle" class="mb-6">
            {{ $errorMessage }}
        </flux:callout>
    @endif

    {{-- Success Message --}}
    @if($successMessage)
        <flux:callout variant="success" icon="check-circle" class="mb-6">
            {{ $successMessage }}
        </flux:callout>
    @endif

    {{-- No Client Selected --}}
    @if(!$clientId)
        <flux:card class="max-w-lg mx-auto">
            <div class="p-8 text-center">
                <flux:icon name="user" class="w-12 h-12 mx-auto text-zinc-400 mb-4" />
                <flux:heading size="lg" class="mb-2">No Client Selected</flux:heading>
                <flux:text class="text-zinc-500 mb-4">
                    Please select a client from the clients list to manage their payment methods.
                </flux:text>
                <flux:button href="{{ route('admin.clients') }}" variant="primary">
                    Go to Clients
                </flux:button>
            </div>
        </flux:card>
    @else
        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
            {{-- Main Content --}}
            <div class="lg:col-span-2">
                {{-- Add Payment Method Form --}}
                @if($showAddForm)
                    <flux:card class="mb-6">
                        <div class="p-6">
                            <div class="flex items-center justify-between mb-6">
                                <flux:heading size="lg">Add Payment Method</flux:heading>
                                <flux:button wire:click="toggleAddForm" size="sm" variant="ghost" icon="x-mark" />
                            </div>

                            {{-- Payment Type Tabs --}}
                            <div class="flex gap-2 mb-6">
                                <flux:button
                                    wire:click="$set('paymentType', 'card')"
                                    :variant="$paymentType === 'card' ? 'primary' : 'ghost'"
                                    icon="credit-card"
                                >
                                    Credit Card
                                </flux:button>
                                <flux:button
                                    wire:click="$set('paymentType', 'ach')"
                                    :variant="$paymentType === 'ach' ? 'primary' : 'ghost'"
                                    icon="building-library"
                                >
                                    Bank Account (ACH)
                                </flux:button>
                            </div>

                            @if($paymentType === 'card')
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
                            @else
                                <div wire:key="payment-fields-ach" class="space-y-4">
                                    <flux:field>
                                        <flux:label>Account Holder Name</flux:label>
                                        <flux:input wire:model="accountName" placeholder="John Doe" />
                                    </flux:field>

                                    <flux:field>
                                        <flux:label>Bank Name (Optional)</flux:label>
                                        <flux:input wire:model="bankName" placeholder="Chase Bank" />
                                    </flux:field>

                                    <flux:field>
                                        <flux:label>Routing Number</flux:label>
                                        <flux:input wire:model="routingNumber" placeholder="123456789" />
                                    </flux:field>

                                    <flux:field>
                                        <flux:label>Account Number</flux:label>
                                        <flux:input wire:model="accountNumber" placeholder="1234567890" />
                                    </flux:field>

                                    <div class="grid grid-cols-2 gap-4">
                                        <flux:field>
                                            <flux:label>Account Type</flux:label>
                                            <flux:select wire:model="accountType">
                                                <option value="checking">Checking</option>
                                                <option value="savings">Savings</option>
                                            </flux:select>
                                        </flux:field>

                                        <flux:field>
                                            <flux:label>Account Classification</flux:label>
                                            <flux:select wire:model="isBusiness">
                                                <option value="0">Personal Account</option>
                                                <option value="1">Business Account</option>
                                            </flux:select>
                                            <flux:description>Affects ACH batch type (PPD/CCD)</flux:description>
                                        </flux:field>
                                    </div>
                                </div>
                            @endif

                            {{-- Common Fields --}}
                            <div class="space-y-4 mt-6 pt-6 border-t border-zinc-200 dark:border-zinc-700">
                                <flux:field>
                                    <flux:label>Nickname (Optional)</flux:label>
                                    <flux:input wire:model="nickname" placeholder="e.g., Personal Card, Business Account" />
                                    <flux:description>A friendly name to help identify this payment method</flux:description>
                                </flux:field>

                                <div class="flex items-center gap-3">
                                    <flux:checkbox wire:model="setAsDefault" id="setAsDefault" />
                                    <label for="setAsDefault" class="text-sm cursor-pointer">
                                        Set as default payment method
                                    </label>
                                </div>
                            </div>

                            {{-- Actions --}}
                            <div class="flex justify-end gap-3 mt-6 pt-6 border-t border-zinc-200 dark:border-zinc-700">
                                <flux:button wire:click="toggleAddForm" variant="ghost">Cancel</flux:button>
                                <flux:button
                                    wire:click="createPaymentMethod"
                                    variant="primary"
                                    :disabled="$processing"
                                >
                                    @if($processing)
                                        <flux:icon name="arrow-path" class="w-4 h-4 animate-spin mr-2" />
                                        Adding...
                                    @else
                                        Add Payment Method
                                    @endif
                                </flux:button>
                            </div>
                        </div>
                    </flux:card>
                @endif

                {{-- Payment Methods List --}}
                <flux:card>
                    <div class="p-4 border-b border-zinc-200 dark:border-zinc-700">
                        <flux:heading size="md">Saved Payment Methods</flux:heading>
                    </div>

                    @if($paymentMethods->isEmpty())
                        <div class="p-12 text-center">
                            <flux:icon name="credit-card" class="w-12 h-12 mx-auto text-zinc-400 mb-4" />
                            <flux:heading size="lg">No Payment Methods</flux:heading>
                            <flux:text class="text-zinc-500 mb-4">This client has no saved payment methods yet.</flux:text>
                            @if(!$showAddForm)
                                <flux:button wire:click="toggleAddForm" variant="primary" icon="plus">
                                    Add Payment Method
                                </flux:button>
                            @endif
                        </div>
                    @else
                        <div class="divide-y divide-zinc-200 dark:divide-zinc-700">
                            @foreach($paymentMethods as $method)
                                <div
                                    wire:key="method-{{ $method->id }}"
                                    class="p-4 flex items-center justify-between hover:bg-zinc-50 dark:hover:bg-zinc-800/50"
                                >
                                    <div class="flex items-center gap-4">
                                        {{-- Icon --}}
                                        <div class="w-12 h-12 rounded-lg bg-zinc-100 dark:bg-zinc-800 flex items-center justify-center">
                                            @if($method->type === 'card')
                                                <flux:icon name="credit-card" class="w-6 h-6 text-zinc-600 dark:text-zinc-400" />
                                            @else
                                                <flux:icon name="building-library" class="w-6 h-6 text-zinc-600 dark:text-zinc-400" />
                                            @endif
                                        </div>

                                        {{-- Details --}}
                                        <div>
                                            <div class="flex items-center gap-2">
                                                <span class="font-medium">{{ $method->display_name }}</span>
                                                @if($method->is_default)
                                                    <span class="text-xs bg-blue-100 text-blue-700 dark:bg-blue-900 dark:text-blue-300 px-2 py-0.5 rounded-full">
                                                        Default
                                                    </span>
                                                @endif
                                                @if($method->type === 'card' && $method->isExpired())
                                                    <span class="text-xs bg-red-100 text-red-700 dark:bg-red-900 dark:text-red-300 px-2 py-0.5 rounded-full">
                                                        Expired
                                                    </span>
                                                @elseif($method->type === 'card' && $method->isExpiringSoon())
                                                    <span class="text-xs bg-amber-100 text-amber-700 dark:bg-amber-900 dark:text-amber-300 px-2 py-0.5 rounded-full">
                                                        Expiring Soon
                                                    </span>
                                                @endif
                                            </div>
                                            <div class="text-sm text-zinc-500">
                                                @if($method->type === 'card')
                                                    {{ $method->brand ?? 'Card' }} ending in {{ $method->last_four }}
                                                    @if($method->expiration_display)
                                                        <span class="mx-1">-</span>
                                                        Expires {{ $method->expiration_display }}
                                                    @endif
                                                @else
                                                    {{ $method->bank_name ?? 'Bank' }} ending in {{ $method->last_four }}
                                                @endif
                                            </div>
                                            @if($method->nickname)
                                                <div class="text-xs text-zinc-400 mt-1">
                                                    Nickname: {{ $method->nickname }}
                                                </div>
                                            @endif
                                        </div>
                                    </div>

                                    {{-- Actions --}}
                                    <div class="flex items-center gap-2">
                                        @if(!$method->is_default)
                                            <flux:button
                                                wire:click="setDefault({{ $method->id }})"
                                                size="sm"
                                                variant="ghost"
                                            >
                                                Set Default
                                            </flux:button>
                                        @endif
                                        <flux:button
                                            wire:click="confirmDelete({{ $method->id }})"
                                            size="sm"
                                            variant="ghost"
                                            class="text-red-600 hover:text-red-700 hover:bg-red-50 dark:hover:bg-red-900/20"
                                            icon="trash"
                                        />
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    @endif
                </flux:card>
            </div>

            {{-- Sidebar --}}
            <div class="lg:col-span-1">
                @if($clientInfo)
                    <flux:card class="sticky top-4">
                        <div class="p-4 border-b border-zinc-200 dark:border-zinc-700">
                            <flux:heading size="md">Client Info</flux:heading>
                        </div>
                        <div class="p-4 space-y-4">
                            <div>
                                <flux:text class="text-sm text-zinc-500">Client Name</flux:text>
                                <flux:text class="font-medium">{{ $clientInfo['client_name'] }}</flux:text>
                            </div>

                            <div>
                                <flux:text class="text-sm text-zinc-500">Client ID</flux:text>
                                <flux:text class="font-mono">{{ $clientInfo['client_id'] }}</flux:text>
                            </div>

                            @if($clientInfo['individual_first_name'] || $clientInfo['individual_last_name'])
                                <div>
                                    <flux:text class="text-sm text-zinc-500">Contact Name</flux:text>
                                    <flux:text>{{ $clientInfo['individual_first_name'] }} {{ $clientInfo['individual_last_name'] }}</flux:text>
                                </div>
                            @endif

                            <div>
                                <flux:text class="text-sm text-zinc-500">Tax ID</flux:text>
                                <flux:text>****{{ substr($clientInfo['federal_tin'] ?? '', -4) }}</flux:text>
                            </div>

                            <div class="pt-4 border-t border-zinc-200 dark:border-zinc-700">
                                <flux:text class="text-sm text-zinc-500">Total Payment Methods</flux:text>
                                <flux:heading size="lg">{{ $paymentMethods->count() }}</flux:heading>
                            </div>

                            <div>
                                <flux:text class="text-sm text-zinc-500">Cards</flux:text>
                                <flux:text>{{ $paymentMethods->where('type', 'card')->count() }}</flux:text>
                            </div>

                            <div>
                                <flux:text class="text-sm text-zinc-500">Bank Accounts</flux:text>
                                <flux:text>{{ $paymentMethods->where('type', 'ach')->count() }}</flux:text>
                            </div>
                        </div>
                    </flux:card>
                @endif
            </div>
        </div>
    @endif

    {{-- Delete Confirmation Modal --}}
    <flux:modal name="delete-payment-method" class="max-w-md" :dismissible="false" @close="cancelDelete">
        <div class="space-y-6" x-data="{ get d() { return $wire.deleteMethodDetails } }">
            <div class="text-center">
                <div class="w-12 h-12 bg-red-100 dark:bg-red-900/30 rounded-full flex items-center justify-center mx-auto">
                    <flux:icon name="exclamation-triangle" class="w-6 h-6 text-red-600 dark:text-red-400" />
                </div>

                <flux:heading size="lg" class="mt-4">Delete Payment Method?</flux:heading>

                <flux:text class="mt-2 text-zinc-500">
                    Are you sure you want to delete <strong x-text="d.display_name ?? 'this payment method'"></strong>?
                    This action cannot be undone.
                </flux:text>

                <template x-if="d.is_linked_to_active_plans">
                    <div class="bg-amber-50 dark:bg-amber-900/20 border border-amber-200 dark:border-amber-800 rounded-lg p-4 mt-4 text-left">
                        <div class="flex items-start gap-3">
                            <flux:icon name="exclamation-triangle" class="w-5 h-5 text-amber-600 flex-shrink-0 mt-0.5" />
                            <div class="text-sm">
                                <strong class="text-amber-800 dark:text-amber-200">Warning:</strong>
                                <span class="text-amber-700 dark:text-amber-300">
                                    This payment method is linked to active payment plans or recurring payments.
                                    Deleting it may cause payment failures.
                                </span>
                            </div>
                        </div>
                    </div>
                </template>
            </div>

            <div class="flex gap-2">
                <flux:spacer />
                <flux:modal.close>
                    <flux:button variant="ghost">Cancel</flux:button>
                </flux:modal.close>
                <flux:button wire:click="deletePaymentMethod" variant="danger">Delete</flux:button>
            </div>
        </div>
    </flux:modal>
</div>
