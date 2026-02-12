<div>
    {{-- Header --}}
    <div class="flex items-center justify-between mb-8">
        <div>
            @if($client)
                <flux:heading size="xl">{{ $client['client_name'] }}</flux:heading>
                <flux:subheading>Client ID: {{ $client['client_id'] }}</flux:subheading>
            @else
                <flux:heading size="xl">Client Details</flux:heading>
            @endif
        </div>
        <div class="flex gap-2">
            <flux:button href="{{ route('admin.clients') }}" variant="ghost" icon="arrow-left">
                Back to Clients
            </flux:button>
        </div>
    </div>

    @if($loading)
        <div class="flex items-center justify-center py-12">
            <flux:icon name="arrow-path" class="w-8 h-8 animate-spin text-zinc-400" />
        </div>
    @elseif($notFound && !$client)
        <flux:card>
            <div class="p-12 text-center">
                <flux:icon name="exclamation-triangle" class="w-12 h-12 mx-auto text-amber-500 mb-4" />
                <flux:heading size="lg">Client Not Found</flux:heading>
                <flux:text class="text-zinc-500 mb-4">
                    No client with ID "{{ $clientId }}" was found in PracticeCS, and no local records exist.
                </flux:text>
                <flux:button href="{{ route('admin.clients') }}" variant="primary">
                    Search Clients
                </flux:button>
            </div>
        </flux:card>
    @else
        @if(session('success'))
            <div class="mb-6 rounded-lg border border-green-300 bg-green-50 dark:border-green-700 dark:bg-green-950/30 p-4 flex items-start gap-3">
                <flux:icon name="check-circle" class="w-5 h-5 text-green-500 flex-shrink-0 mt-0.5" />
                <flux:text class="font-medium text-green-800 dark:text-green-200">{{ session('success') }}</flux:text>
            </div>
        @endif
        @if($notFound)
            <div class="mb-6 rounded-lg border border-amber-300 bg-amber-50 dark:border-amber-700 dark:bg-amber-950/30 p-4 flex items-start gap-3">
                <flux:icon name="exclamation-triangle" class="w-5 h-5 text-amber-500 flex-shrink-0 mt-0.5" />
                <div>
                    <flux:text class="font-medium text-amber-800 dark:text-amber-200">Client not found in PracticeCS</flux:text>
                    <flux:text class="text-sm text-amber-700 dark:text-amber-300">
                        Client ID "{{ $clientId }}" does not exist in PracticeCS. This may have been removed or the ID may be incorrect. Showing local payment records only.
                    </flux:text>
                </div>
            </div>
        @endif
        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
            {{-- Left Column - Client Info --}}
            <div class="space-y-6">
                {{-- Basic Info Card --}}
                <flux:card>
                    <div class="p-4 border-b border-zinc-200 dark:border-zinc-700">
                        <flux:heading size="md">Client Information</flux:heading>
                    </div>
                    <div class="p-4 space-y-4">
                        <div>
                            <flux:text class="text-sm text-zinc-500">Client Name</flux:text>
                            <flux:text class="font-medium">{{ $client['client_name'] }}</flux:text>
                        </div>
                        <div>
                            <flux:text class="text-sm text-zinc-500">Client ID</flux:text>
                            @if($editingClientId)
                                <form wire:submit="updateClientId" class="mt-1">
                                    <div class="flex items-center gap-2">
                                        <flux:input
                                            wire:model="newClientId"
                                            size="sm"
                                            class="font-mono max-w-48"
                                            placeholder="Enter client ID"
                                            autofocus
                                        />
                                        <flux:button type="submit" size="sm" variant="primary" icon="check">
                                            Save
                                        </flux:button>
                                        <flux:button wire:click="cancelEditingClientId" size="sm" variant="ghost" icon="x-mark">
                                            Cancel
                                        </flux:button>
                                    </div>
                                    @error('newClientId')
                                        <flux:text class="text-sm text-red-600 mt-1">{{ $message }}</flux:text>
                                    @enderror
                                </form>
                            @else
                                <div class="flex items-center gap-2">
                                    <flux:text class="font-mono">{{ $client['client_id'] }}</flux:text>
                                    <flux:button
                                        wire:click="startEditingClientId"
                                        size="xs"
                                        variant="ghost"
                                        icon="pencil"
                                        title="Edit Client ID"
                                    />
                                </div>
                            @endif
                        </div>
                        @if($client['individual_first_name'] || $client['individual_last_name'])
                            <div>
                                <flux:text class="text-sm text-zinc-500">Contact Name</flux:text>
                                <flux:text>{{ $client['individual_first_name'] }} {{ $client['individual_last_name'] }}</flux:text>
                            </div>
                        @endif
                        @if(!empty($client['federal_tin']))
                        <div>
                            <flux:text class="text-sm text-zinc-500">Tax ID</flux:text>
                            <flux:text>****{{ substr($client['federal_tin'], -4) }}</flux:text>
                        </div>
                        @endif
                        @if(!$notFound)
                        <div class="pt-2 border-t border-zinc-200 dark:border-zinc-700">
                            <flux:text class="text-sm text-zinc-500">Current Balance</flux:text>
                            <flux:heading size="lg" class="{{ $balance > 0 ? 'text-red-600' : 'text-green-600' }}">
                                ${{ number_format($balance, 2) }}
                            </flux:heading>
                        </div>
                        @endif
                    </div>
                </flux:card>

                {{-- Open Invoices Card (only when client exists in PracticeCS) --}}
                @if(!$notFound)
                <flux:card>
                    <div class="p-4 border-b border-zinc-200 dark:border-zinc-700">
                        <flux:heading size="md">Open Invoices ({{ count($openInvoices) }})</flux:heading>
                    </div>
                    @if(count($openInvoices) > 0)
                        <div class="p-4 max-h-64 overflow-y-auto">
                            <div class="space-y-2">
                                @foreach($openInvoices as $invoice)
                                    <div class="flex justify-between text-sm py-2 border-b border-zinc-100 dark:border-zinc-800 last:border-0">
                                        <span class="font-mono">#{{ $invoice['invoice_number'] }}</span>
                                        <span class="font-medium">${{ number_format($invoice['open_amount'], 2) }}</span>
                                    </div>
                                @endforeach
                            </div>
                        </div>
                    @else
                        <div class="p-4 text-center text-zinc-500">
                            <flux:text>No open invoices</flux:text>
                        </div>
                    @endif
                </flux:card>
                @endif

                {{-- Saved Payment Methods Card --}}
                <flux:card>
                    <div class="p-4 border-b border-zinc-200 dark:border-zinc-700 flex items-center justify-between">
                        <flux:heading size="md">Saved Payment Methods ({{ $paymentMethods->count() }})</flux:heading>
                        @if(!$notFound && $client && $client['client_id'])
                            <flux:button
                                href="{{ route('admin.clients.payment-methods', ['client' => $client['client_id']]) }}"
                                size="sm"
                                variant="ghost"
                                icon="plus"
                            >
                                Manage
                            </flux:button>
                        @endif
                    </div>
                    @if($paymentMethods->count() > 0)
                        <div class="p-4 space-y-2">
                            @foreach($paymentMethods as $method)
                                <div class="flex items-center justify-between text-sm py-2 px-2 border border-zinc-200 dark:border-zinc-700 rounded-lg bg-zinc-50 dark:bg-zinc-800/50">
                                    <div class="flex items-center gap-2 min-w-0">
                                        @if($method->type === 'card')
                                            <flux:icon name="credit-card" class="w-4 h-4 text-zinc-500 flex-shrink-0" />
                                        @else
                                            <flux:icon name="building-library" class="w-4 h-4 text-zinc-500 flex-shrink-0" />
                                        @endif
                                        <div class="min-w-0">
                                            <div class="flex items-center gap-1">
                                                <span class="font-medium truncate">{{ $method->display_name }}</span>
                                                @if($method->is_default)
                                                    <span class="text-xs bg-blue-100 text-blue-700 dark:bg-blue-900 dark:text-blue-300 px-1.5 py-0.5 rounded">Default</span>
                                                @endif
                                            </div>
                                            @if($method->type === 'card' && $method->exp_month && $method->exp_year)
                                                <span class="text-xs text-zinc-400">
                                                    Exp: {{ str_pad($method->exp_month, 2, '0', STR_PAD_LEFT) }}/{{ $method->exp_year }}
                                                </span>
                                            @endif
                                        </div>
                                    </div>
                                    <flux:button
                                        wire:click="deletePaymentMethod({{ $method->id }})"
                                        wire:confirm="Are you sure you want to delete this payment method?"
                                        size="xs"
                                        variant="ghost"
                                        class="text-red-500 hover:text-red-700 flex-shrink-0"
                                        icon="trash"
                                    />
                                </div>
                            @endforeach
                        </div>
                    @else
                        <div class="p-4 text-center text-zinc-500">
                            <flux:text>No saved payment methods</flux:text>
                            @if(!$notFound && $client && $client['client_id'])
                                <div class="mt-2">
                                    <flux:button
                                        href="{{ route('admin.clients.payment-methods', ['client' => $client['client_id']]) }}"
                                        size="sm"
                                        variant="primary"
                                        icon="plus"
                                    >
                                        Add Payment Method
                                    </flux:button>
                                </div>
                            @endif
                        </div>
                    @endif
                </flux:card>

                {{-- Actions (only when client exists in PracticeCS) --}}
                @if(!$notFound)
                <div class="space-y-2">
                    <flux:button
                        href="{{ route('admin.payments.create') }}?client={{ $client['client_id'] }}"
                        variant="primary"
                        class="w-full"
                        icon="banknotes"
                    >
                        Create Single Payment
                    </flux:button>
                    <flux:button
                        href="{{ route('admin.payment-plans.create') }}?client={{ $client['client_id'] }}"
                        variant="ghost"
                        class="w-full"
                        icon="calendar-days"
                    >
                        Create Payment Plan
                    </flux:button>
                    <flux:button
                        href="{{ route('admin.recurring-payments.create') }}?client={{ $client['client_id'] }}"
                        variant="ghost"
                        class="w-full"
                        icon="arrow-path"
                    >
                        Create Recurring Payment
                    </flux:button>
                </div>
                @endif
            </div>

            {{-- Right Column - Payment History & Plans --}}
            <div class="lg:col-span-2 space-y-6">
                {{-- Recurring Payments --}}
                <flux:card>
                    <div class="p-4 border-b border-zinc-200 dark:border-zinc-700">
                        <flux:heading size="md">Recurring Payments ({{ $recurringPayments->count() }})</flux:heading>
                    </div>
                    <div wire:replace.self>
                        @if($recurringPayments->count() > 0)
                            <flux:table>
                            <flux:table.columns>
                                <flux:table.column>Amount</flux:table.column>
                                <flux:table.column>Frequency</flux:table.column>
                                <flux:table.column>Payment Method</flux:table.column>
                                <flux:table.column>Next Payment</flux:table.column>
                                <flux:table.column>Status</flux:table.column>
                                <flux:table.column>Collected</flux:table.column>
                                <flux:table.column></flux:table.column>
                            </flux:table.columns>
                            <flux:table.rows>
                                @foreach($recurringPayments as $recurring)
                                    @php
                                        $methodStatus = $this->getRecurringPaymentMethodStatus($recurring);
                                    @endphp
                                    <flux:table.row :key="'recurring-'.$recurring->id.'-'.$recurring->status.'-'.($recurring->payment_method_token ? '1' : '0')">
                                        <flux:table.cell>
                                            <span class="font-medium">${{ number_format($recurring->amount, 2) }}</span>
                                        </flux:table.cell>
                                        <flux:table.cell>{{ $recurring->frequency_label }}</flux:table.cell>
                                        <flux:table.cell>
                                            @if($methodStatus['status'] === 'ok')
                                                <div class="flex items-center gap-1">
                                                    @if($recurring->payment_method_type === 'card')
                                                        <flux:icon name="credit-card" class="w-4 h-4 text-zinc-400" />
                                                    @else
                                                        <flux:icon name="building-library" class="w-4 h-4 text-zinc-400" />
                                                    @endif
                                                    <span class="text-sm">{{ $methodStatus['method']->display_name }}</span>
                                                </div>
                                            @elseif($methodStatus['status'] === 'expired')
                                                <div class="flex items-center gap-1">
                                                    <flux:icon name="credit-card" class="w-4 h-4 text-red-400" />
                                                    <span class="text-sm text-red-600">{{ $methodStatus['method']->display_name }}</span>
                                                </div>
                                                <flux:badge color="red" size="sm" class="mt-1">Expired</flux:badge>
                                            @elseif($methodStatus['status'] === 'missing')
                                                <div class="flex items-center gap-1">
                                                    @if($recurring->payment_method_type === 'card')
                                                        <flux:icon name="credit-card" class="w-4 h-4 text-amber-400" />
                                                    @else
                                                        <flux:icon name="building-library" class="w-4 h-4 text-amber-400" />
                                                    @endif
                                                    <span class="text-sm text-zinc-500">
                                                        {{ ucfirst($recurring->payment_method_type ?? 'Unknown') }}
                                                        @if($recurring->payment_method_last_four)
                                                            •••• {{ $recurring->payment_method_last_four }}
                                                        @endif
                                                    </span>
                                                </div>
                                                <flux:badge color="amber" size="sm" class="mt-1">No Saved Method</flux:badge>
                                            @elseif($methodStatus['status'] === 'pending')
                                                @if($paymentMethods->count() > 0)
                                                    <flux:button
                                                        wire:click="openAssignMethodModal({{ $recurring->id }})"
                                                        size="xs"
                                                        variant="ghost"
                                                        icon="link"
                                                        class="text-amber-600 hover:text-amber-700 dark:text-amber-400 dark:hover:text-amber-300"
                                                    >
                                                        Assign Method
                                                    </flux:button>
                                                @else
                                                    <flux:button
                                                        href="{{ route('admin.clients.payment-methods', ['client' => $clientId]) }}"
                                                        size="xs"
                                                        variant="ghost"
                                                        icon="plus"
                                                        class="text-amber-600 hover:text-amber-700 dark:text-amber-400 dark:hover:text-amber-300"
                                                    >
                                                        Add Method
                                                    </flux:button>
                                                @endif
                                            @endif
                                        </flux:table.cell>
                                        <flux:table.cell>
                                            @if($recurring->next_payment_date)
                                                <span class="{{ $recurring->next_payment_date->isPast() ? 'text-amber-600' : '' }}">
                                                    <local-time datetime="{{ $recurring->next_payment_date->toIso8601String() }}" format="date"></local-time>
                                                </span>
                                            @else
                                                <span class="text-zinc-400">-</span>
                                            @endif
                                        </flux:table.cell>
                                        <flux:table.cell>
                                            @if($recurring->status === 'active')
                                                <flux:badge color="green" size="sm">Active</flux:badge>
                                            @elseif($recurring->status === 'paused')
                                                <flux:badge color="amber" size="sm">Paused</flux:badge>
                                            @elseif($recurring->status === 'cancelled')
                                                <flux:badge color="zinc" size="sm">Cancelled</flux:badge>
                                            @elseif($recurring->status === 'completed')
                                                <flux:badge color="blue" size="sm">Completed</flux:badge>
                                            @elseif($recurring->status === 'pending')
                                                <flux:badge color="amber" size="sm">Pending</flux:badge>
                                            @else
                                                <flux:badge size="sm">{{ ucfirst($recurring->status) }}</flux:badge>
                                            @endif
                                        </flux:table.cell>
                                        <flux:table.cell>
                                            <span class="text-zinc-500">${{ number_format($recurring->total_collected, 2) }}</span>
                                        </flux:table.cell>
                                        <flux:table.cell>
                                            @if($recurring->status === 'active' && $recurring->next_payment_date?->isPast())
                                                <flux:button
                                                    wire:click="retryRecurringPayment({{ $recurring->id }})"
                                                    wire:confirm="Process this recurring payment now?"
                                                    variant="ghost"
                                                    size="sm"
                                                    icon="arrow-path"
                                                    :disabled="$retrying"
                                                >
                                                    Retry
                                                </flux:button>
                                            @endif
                                        </flux:table.cell>
                                    </flux:table.row>
                                @endforeach
                            </flux:table.rows>
                        </flux:table>
                    @else
                        <div class="p-8 text-center text-zinc-500">
                            <flux:icon name="arrow-path" class="w-8 h-8 mx-auto text-zinc-400 mb-2" />
                            <flux:text>No recurring payments</flux:text>
                        </div>
                    @endif
                    </div>
                </flux:card>

                {{-- Payment Plans --}}
                <flux:card>
                    <div class="p-4 border-b border-zinc-200 dark:border-zinc-700">
                        <flux:heading size="md">Payment Plans ({{ $paymentPlans->count() }})</flux:heading>
                    </div>
                    @if($paymentPlans->count() > 0)
                        <flux:table>
                            <flux:table.columns>
                                <flux:table.column>Plan</flux:table.column>
                                <flux:table.column>Total</flux:table.column>
                                <flux:table.column>Paid</flux:table.column>
                                <flux:table.column>Next Payment</flux:table.column>
                                <flux:table.column>Status</flux:table.column>
                            </flux:table.columns>
                            <flux:table.rows>
                                @foreach($paymentPlans as $plan)
                                    <flux:table.row>
                                        <flux:table.cell>
                                            <span class="font-mono text-sm">{{ $plan->plan_id }}</span>
                                        </flux:table.cell>
                                        <flux:table.cell>
                                            <span class="font-medium">${{ number_format($plan->total_amount, 2) }}</span>
                                        </flux:table.cell>
                                        <flux:table.cell>
                                            <span class="text-zinc-500">${{ number_format($plan->amount_paid, 2) }}</span>
                                        </flux:table.cell>
                                        <flux:table.cell>
                                            @if($plan->next_payment_date)
                                                <local-time datetime="{{ $plan->next_payment_date->toIso8601String() }}" format="date"></local-time>
                                            @else
                                                <span class="text-zinc-400">-</span>
                                            @endif
                                        </flux:table.cell>
                                        <flux:table.cell>
                                            @if($plan->status === 'active')
                                                <flux:badge color="green" size="sm">Active</flux:badge>
                                            @elseif($plan->status === 'completed')
                                                <flux:badge color="blue" size="sm">Completed</flux:badge>
                                            @elseif($plan->status === 'cancelled')
                                                <flux:badge color="zinc" size="sm">Cancelled</flux:badge>
                                            @elseif($plan->status === 'past_due')
                                                <flux:badge color="red" size="sm">Past Due</flux:badge>
                                            @else
                                                <flux:badge size="sm">{{ ucfirst($plan->status) }}</flux:badge>
                                            @endif
                                        </flux:table.cell>
                                    </flux:table.row>
                                @endforeach
                            </flux:table.rows>
                        </flux:table>
                    @else
                        <div class="p-8 text-center text-zinc-500">
                            <flux:icon name="calendar-days" class="w-8 h-8 mx-auto text-zinc-400 mb-2" />
                            <flux:text>No payment plans</flux:text>
                        </div>
                    @endif
                </flux:card>

                {{-- Payment History --}}
                <flux:card>
                    <div class="p-4 border-b border-zinc-200 dark:border-zinc-700">
                        <flux:heading size="md">Payment History ({{ $payments->count() }})</flux:heading>
                    </div>
                    @if($payments->count() > 0)
                        <flux:table>
                            <flux:table.columns>
                                <flux:table.column>Date</flux:table.column>
                                <flux:table.column>Amount</flux:table.column>
                                <flux:table.column>Method</flux:table.column>
                                <flux:table.column>Status</flux:table.column>
                                <flux:table.column>Transaction</flux:table.column>
                            </flux:table.columns>
                            <flux:table.rows>
                                @foreach($payments as $payment)
                                    <flux:table.row>
                                        <flux:table.cell>
                                            <local-time datetime="{{ $payment->created_at->toIso8601String() }}" format="date"></local-time>
                                        </flux:table.cell>
                                        <flux:table.cell>
                                            <span class="font-medium">${{ number_format($payment->amount, 2) }}</span>
                                            @if($payment->fee > 0)
                                                <span class="text-zinc-500 text-sm block">+${{ number_format($payment->fee, 2) }} fee</span>
                                            @endif
                                        </flux:table.cell>
                                        <flux:table.cell>
                                            <span class="capitalize">{{ $payment->payment_method }}</span>
                                            @if($payment->payment_method_last_four)
                                                <span class="text-zinc-500 text-sm block">****{{ $payment->payment_method_last_four }}</span>
                                            @endif
                                        </flux:table.cell>
                                        <flux:table.cell>
                                            @if($payment->status === 'completed')
                                                <flux:badge color="green" size="sm">Completed</flux:badge>
                                            @elseif($payment->status === 'processing')
                                                <flux:badge color="blue" size="sm">Processing</flux:badge>
                                            @elseif($payment->status === 'pending')
                                                <flux:badge color="amber" size="sm">Pending</flux:badge>
                                            @elseif($payment->status === 'failed')
                                                <flux:badge color="red" size="sm">Failed</flux:badge>
                                            @elseif($payment->status === 'refunded')
                                                <flux:badge color="zinc" size="sm">Refunded</flux:badge>
                                            @else
                                                <flux:badge size="sm">{{ ucfirst($payment->status) }}</flux:badge>
                                            @endif
                                        </flux:table.cell>
                                        <flux:table.cell>
                                            <span class="font-mono text-sm text-zinc-500">{{ Str::limit($payment->transaction_id, 15) }}</span>
                                        </flux:table.cell>
                                    </flux:table.row>
                                @endforeach
                            </flux:table.rows>
                        </flux:table>
                    @else
                        <div class="p-8 text-center text-zinc-500">
                            <flux:icon name="banknotes" class="w-8 h-8 mx-auto text-zinc-400 mb-2" />
                            <flux:text>No payment history</flux:text>
                        </div>
                    @endif
                </flux:card>
            </div>
        </div>
    @endif

    {{-- Assign Payment Method Modal --}}
    <flux:modal name="assign-payment-method" class="w-full max-w-lg">
        <div x-data="{ get d() { return $wire.assignModalDetails } }">
            <template x-if="d && d.recurring_id">
                <div class="space-y-6">
                    {{-- Header --}}
                    <div>
                        <flux:heading size="lg">Assign Payment Method</flux:heading>
                        <flux:text class="text-zinc-500 mt-1">
                            Select a saved payment method for this
                            <span x-text="d.recurring_frequency" class="font-medium"></span>
                            recurring payment of
                            <span class="font-medium">$<span x-text="d.recurring_amount"></span></span>.
                        </flux:text>
                        <template x-if="d.recurring_description">
                            <flux:text class="text-zinc-400 text-sm mt-1" x-text="d.recurring_description"></flux:text>
                        </template>
                    </div>

                    {{-- Payment Methods List --}}
                    <template x-if="d.methods && d.methods.length > 0">
                        <div class="space-y-2">
                            <template x-for="method in d.methods" :key="method.id">
                                <button
                                    type="button"
                                    x-on:click="$wire.assignPaymentMethod(method.id)"
                                    class="w-full flex items-center justify-between p-3 border border-zinc-200 dark:border-zinc-700 rounded-lg hover:bg-zinc-50 dark:hover:bg-zinc-800/50 transition-colors text-left"
                                >
                                    <div class="flex items-center gap-3 min-w-0">
                                        {{-- Icon --}}
                                        <template x-if="method.type === 'card'">
                                            <flux:icon name="credit-card" class="w-5 h-5 text-zinc-400 flex-shrink-0" />
                                        </template>
                                        <template x-if="method.type === 'ach'">
                                            <flux:icon name="building-library" class="w-5 h-5 text-zinc-400 flex-shrink-0" />
                                        </template>

                                        {{-- Details --}}
                                        <div class="min-w-0">
                                            <div class="flex items-center gap-2">
                                                <span class="font-medium text-sm truncate" x-text="method.display_name"></span>
                                                <template x-if="method.is_default">
                                                    <span class="text-xs bg-blue-100 text-blue-700 dark:bg-blue-900 dark:text-blue-300 px-1.5 py-0.5 rounded flex-shrink-0">Default</span>
                                                </template>
                                            </div>
                                            <template x-if="method.exp_display">
                                                <div class="flex items-center gap-1 mt-0.5">
                                                    <span class="text-xs text-zinc-400">Exp: <span x-text="method.exp_display"></span></span>
                                                    <template x-if="method.is_expiring_soon">
                                                        <span class="text-xs text-amber-500">(expiring soon)</span>
                                                    </template>
                                                </div>
                                            </template>
                                        </div>
                                    </div>

                                    {{-- Arrow --}}
                                    <flux:icon name="chevron-right" class="w-4 h-4 text-zinc-400 flex-shrink-0" />
                                </button>
                            </template>
                        </div>
                    </template>

                    {{-- No methods fallback (shouldn't happen since we check before opening) --}}
                    <template x-if="!d.methods || d.methods.length === 0">
                        <div class="text-center py-4">
                            <flux:text class="text-zinc-500">No payment methods available.</flux:text>
                        </div>
                    </template>

                    {{-- Footer --}}
                    <div class="flex justify-end">
                        <flux:button wire:click="closeAssignModal" variant="ghost">Cancel</flux:button>
                    </div>
                </div>
            </template>
        </div>
    </flux:modal>
</div>
