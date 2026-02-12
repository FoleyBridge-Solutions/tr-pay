<div>
    <div class="flex items-center justify-between mb-8">
        <div>
            <flux:heading size="xl">Payments</flux:heading>
            <flux:subheading>View and manage all payment transactions</flux:subheading>
        </div>
        <flux:button href="{{ route('admin.payments.create') }}" variant="primary" icon="plus">
            Create Single Payment
        </flux:button>
    </div>

    {{-- Filters --}}
    <flux:card class="mb-6">
        <div class="flex flex-col md:flex-row gap-4 p-4">
            <div class="flex-1">
                <flux:input 
                    wire:model.live.debounce.300ms="search" 
                    placeholder="Search by transaction ID..." 
                    icon="magnifying-glass"
                />
            </div>
            <div class="w-full md:w-48">
                <flux:select wire:model.live="status">
                    <option value="">All Statuses</option>
                    <option value="completed">Completed</option>
                    <option value="processing">Processing</option>
                    <option value="pending">Pending</option>
                    <option value="failed">Failed</option>
                    <option value="refunded">Refunded</option>
                </flux:select>
            </div>
            <div class="w-full md:w-48">
                <flux:select wire:model.live="dateRange">
                    <option value="">All Time</option>
                    <option value="today">Today</option>
                    <option value="week">This Week</option>
                    <option value="month">This Month</option>
                    <option value="year">This Year</option>
                </flux:select>
            </div>
        </div>
    </flux:card>

    {{-- Payments Table --}}
    <flux:card>
        @if($payments->isEmpty())
            <div class="p-12 text-center">
                <flux:icon name="credit-card" class="w-12 h-12 mx-auto text-zinc-400 mb-4" />
                <flux:heading size="lg">No payments found</flux:heading>
                <flux:text class="text-zinc-500">Try adjusting your search or filters</flux:text>
            </div>
        @else
            <flux:table>
                <flux:table.columns>
                    <flux:table.column>Transaction ID</flux:table.column>
                    <flux:table.column>Client</flux:table.column>
                    <flux:table.column>Amount</flux:table.column>
                    <flux:table.column>Method</flux:table.column>
                    <flux:table.column>Status</flux:table.column>
                    <flux:table.column>Plan</flux:table.column>
                    <flux:table.column>Date</flux:table.column>
                    <flux:table.column></flux:table.column>
                </flux:table.columns>
                <flux:table.rows>
                    @foreach($payments as $payment)
                        <flux:table.row wire:key="payment-{{ $payment->id }}">
                            <flux:table.cell>
                                <span class="font-mono text-sm">{{ Str::limit($payment->transaction_id, 20) }}</span>
                            </flux:table.cell>
                            <flux:table.cell>
                                @php
                                    $metadata = $payment->metadata ?? [];
                                    $clientName = $metadata['client_name'] ?? $payment->customer?->name ?? null;
                                    $clientId = $metadata['client_id'] ?? $payment->client_id ?? null;
                                @endphp
                                @if($clientName && $clientId)
                                    <a href="{{ route('admin.clients.show', $clientId) }}" class="text-blue-600 hover:text-blue-800 dark:text-blue-400 dark:hover:text-blue-300 hover:underline">
                                        <span class="font-medium">{{ Str::limit($clientName, 20) }}</span>
                                        <span class="text-zinc-500 text-sm block">{{ $clientId }}</span>
                                    </a>
                                @elseif($clientName)
                                    <span class="font-medium">{{ Str::limit($clientName, 20) }}</span>
                                @elseif($clientId)
                                    <a href="{{ route('admin.clients.show', $clientId) }}" class="text-blue-600 hover:text-blue-800 dark:text-blue-400 dark:hover:text-blue-300 hover:underline">
                                        {{ $clientId }}
                                    </a>
                                @else
                                    <span class="text-zinc-400">-</span>
                                @endif
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
                                @if($payment->payment_plan_id)
                                    <flux:badge color="blue" size="sm">Plan #{{ $payment->payment_number }}</flux:badge>
                                @else
                                    <span class="text-zinc-400">-</span>
                                @endif
                            </flux:table.cell>
                            <flux:table.cell class="text-zinc-500">
                                <local-time datetime="{{ $payment->created_at->toIso8601String() }}"></local-time>
                            </flux:table.cell>
                            <flux:table.cell>
                                <flux:button wire:click="viewPayment({{ $payment->id }})" variant="ghost" size="sm" icon="eye">
                                    View
                                </flux:button>
                            </flux:table.cell>
                        </flux:table.row>
                    @endforeach
                </flux:table.rows>
            </flux:table>

            <div class="p-4 border-t border-zinc-200 dark:border-zinc-700">
                {{ $payments->links() }}
            </div>
        @endif
    </flux:card>

    {{-- Payment Details Modal --}}
    <flux:modal name="payment-details" class="max-w-lg" @close="closeDetails">
        <div class="p-6" x-data="{ get d() { return $wire.paymentDetails } }">
            <flux:heading size="lg" class="mb-4">Payment Details</flux:heading>
            
            <div class="space-y-4">
                <div class="flex justify-between py-2 border-b border-zinc-200 dark:border-zinc-700">
                    <span class="text-zinc-500">Transaction ID</span>
                    <span class="font-mono text-sm" x-text="d.transaction_id ?? '-'"></span>
                </div>
                <template x-if="d.client_name || d.client_id">
                    <div class="flex justify-between py-2 border-b border-zinc-200 dark:border-zinc-700">
                        <span class="text-zinc-500">Client</span>
                        <span class="text-right">
                            <template x-if="d.client_name">
                                <span class="font-medium" x-text="d.client_name"></span>
                            </template>
                            <template x-if="d.client_id">
                                <span class="text-zinc-500 text-sm block" x-text="d.client_id"></span>
                            </template>
                        </span>
                    </div>
                </template>
                <div class="flex justify-between py-2 border-b border-zinc-200 dark:border-zinc-700">
                    <span class="text-zinc-500">Amount</span>
                    <span class="font-medium" x-text="'$' + (d.amount ?? '0.00')"></span>
                </div>
                <template x-if="d.has_fee">
                    <div>
                        <div class="flex justify-between py-2 border-b border-zinc-200 dark:border-zinc-700">
                            <span class="text-zinc-500">Fee</span>
                            <span x-text="'$' + (d.fee ?? '0.00')"></span>
                        </div>
                        <div class="flex justify-between py-2 border-b border-zinc-200 dark:border-zinc-700">
                            <span class="text-zinc-500">Total</span>
                            <span class="font-medium" x-text="'$' + (d.total_amount ?? '0.00')"></span>
                        </div>
                    </div>
                </template>
                <div class="flex justify-between py-2 border-b border-zinc-200 dark:border-zinc-700">
                    <span class="text-zinc-500">Payment Method</span>
                    <span>
                        <span class="capitalize" x-text="d.payment_method ?? '-'"></span><span x-show="d.payment_method_last_four" x-text="'****' + (d.payment_method_last_four ?? '')"></span>
                    </span>
                </div>
                <div class="flex justify-between py-2 border-b border-zinc-200 dark:border-zinc-700">
                    <span class="text-zinc-500">Status</span>
                    <span>
                        <template x-if="d.status === 'completed'">
                            <flux:badge color="green" size="sm">Completed</flux:badge>
                        </template>
                        <template x-if="d.status === 'processing'">
                            <flux:badge color="blue" size="sm">Processing</flux:badge>
                        </template>
                        <template x-if="d.status === 'pending'">
                            <flux:badge color="amber" size="sm">Pending</flux:badge>
                        </template>
                        <template x-if="d.status === 'failed'">
                            <flux:badge color="red" size="sm">Failed</flux:badge>
                        </template>
                        <template x-if="d.status === 'refunded'">
                            <flux:badge color="zinc" size="sm">Refunded</flux:badge>
                        </template>
                        <template x-if="!d.status">
                            <span class="text-zinc-400">-</span>
                        </template>
                    </span>
                </div>
                <template x-if="d.failure_reason">
                    <div class="flex justify-between py-2 border-b border-zinc-200 dark:border-zinc-700">
                        <span class="text-zinc-500">Failure Reason</span>
                        <span class="text-red-600" x-text="d.failure_reason"></span>
                    </div>
                </template>
                <template x-if="d.has_plan">
                    <div class="flex justify-between py-2 border-b border-zinc-200 dark:border-zinc-700">
                        <span class="text-zinc-500">Payment Plan</span>
                        <span x-text="'Payment ' + (d.payment_number ?? '') + ' of ' + (d.plan_duration ?? '')"></span>
                    </div>
                </template>
                <div class="flex justify-between py-2 border-b border-zinc-200 dark:border-zinc-700">
                    <span class="text-zinc-500">Created</span>
                    <span>
                        <template x-if="d.created_at">
                            <local-time x-bind:datetime="d.created_at"></local-time>
                        </template>
                        <template x-if="!d.created_at">
                            <span>-</span>
                        </template>
                    </span>
                </div>
                <template x-if="d.processed_at">
                    <div class="flex justify-between py-2 border-b border-zinc-200 dark:border-zinc-700">
                        <span class="text-zinc-500">Processed</span>
                        <span><local-time x-bind:datetime="d.processed_at"></local-time></span>
                    </div>
                </template>
            </div>

            <div class="mt-6 flex justify-end">
                <flux:modal.close>
                    <flux:button variant="ghost">Close</flux:button>
                </flux:modal.close>
            </div>
        </div>
    </flux:modal>
</div>
