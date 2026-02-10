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
                                    $clientId = $metadata['client_id'] ?? $payment->client_key ?? null;
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
                                {{ $payment->created_at->format('M j, Y g:i A') }}
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
    <flux:modal wire:model="showDetails" class="max-w-lg">
        @if($selectedPayment)
            <div class="p-6">
                <flux:heading size="lg" class="mb-4">Payment Details</flux:heading>
                
                <div class="space-y-4">
                    <div class="flex justify-between py-2 border-b border-zinc-200 dark:border-zinc-700">
                        <span class="text-zinc-500">Transaction ID</span>
                        <span class="font-mono text-sm">{{ $selectedPayment->transaction_id }}</span>
                    </div>
                    @php
                        $metadata = $selectedPayment->metadata ?? [];
                        $clientName = $metadata['client_name'] ?? $selectedPayment->customer?->name ?? null;
                        $clientId = $metadata['client_id'] ?? $selectedPayment->client_key ?? null;
                    @endphp
                    @if($clientName || $clientId)
                        <div class="flex justify-between py-2 border-b border-zinc-200 dark:border-zinc-700">
                            <span class="text-zinc-500">Client</span>
                            <span class="text-right">
                                @if($clientName)
                                    <span class="font-medium">{{ $clientName }}</span>
                                @endif
                                @if($clientId)
                                    <span class="text-zinc-500 text-sm block">{{ $clientId }}</span>
                                @endif
                            </span>
                        </div>
                    @endif
                    <div class="flex justify-between py-2 border-b border-zinc-200 dark:border-zinc-700">
                        <span class="text-zinc-500">Amount</span>
                        <span class="font-medium">${{ number_format($selectedPayment->amount, 2) }}</span>
                    </div>
                    @if($selectedPayment->fee > 0)
                        <div class="flex justify-between py-2 border-b border-zinc-200 dark:border-zinc-700">
                            <span class="text-zinc-500">Fee</span>
                            <span>${{ number_format($selectedPayment->fee, 2) }}</span>
                        </div>
                        <div class="flex justify-between py-2 border-b border-zinc-200 dark:border-zinc-700">
                            <span class="text-zinc-500">Total</span>
                            <span class="font-medium">${{ number_format($selectedPayment->total_amount, 2) }}</span>
                        </div>
                    @endif
                    <div class="flex justify-between py-2 border-b border-zinc-200 dark:border-zinc-700">
                        <span class="text-zinc-500">Payment Method</span>
                        <span class="capitalize">{{ $selectedPayment->payment_method }} @if($selectedPayment->payment_method_last_four)****{{ $selectedPayment->payment_method_last_four }}@endif</span>
                    </div>
                    <div class="flex justify-between py-2 border-b border-zinc-200 dark:border-zinc-700">
                        <span class="text-zinc-500">Status</span>
                        <span>
                            @if($selectedPayment->status === 'completed')
                                <flux:badge color="green" size="sm">Completed</flux:badge>
                            @elseif($selectedPayment->status === 'processing')
                                <flux:badge color="blue" size="sm">Processing</flux:badge>
                            @elseif($selectedPayment->status === 'pending')
                                <flux:badge color="amber" size="sm">Pending</flux:badge>
                            @elseif($selectedPayment->status === 'failed')
                                <flux:badge color="red" size="sm">Failed</flux:badge>
                            @else
                                <flux:badge size="sm">{{ ucfirst($selectedPayment->status) }}</flux:badge>
                            @endif
                        </span>
                    </div>
                    @if($selectedPayment->failure_reason)
                        <div class="flex justify-between py-2 border-b border-zinc-200 dark:border-zinc-700">
                            <span class="text-zinc-500">Failure Reason</span>
                            <span class="text-red-600">{{ $selectedPayment->failure_reason }}</span>
                        </div>
                    @endif
                    @if($selectedPayment->paymentPlan)
                        <div class="flex justify-between py-2 border-b border-zinc-200 dark:border-zinc-700">
                            <span class="text-zinc-500">Payment Plan</span>
                            <span>Payment {{ $selectedPayment->payment_number }} of {{ $selectedPayment->paymentPlan->duration_months }}</span>
                        </div>
                    @endif
                    <div class="flex justify-between py-2 border-b border-zinc-200 dark:border-zinc-700">
                        <span class="text-zinc-500">Created</span>
                        <span>{{ $selectedPayment->created_at->format('M j, Y g:i A') }}</span>
                    </div>
                    @if($selectedPayment->processed_at)
                        <div class="flex justify-between py-2 border-b border-zinc-200 dark:border-zinc-700">
                            <span class="text-zinc-500">Processed</span>
                            <span>{{ $selectedPayment->processed_at->format('M j, Y g:i A') }}</span>
                        </div>
                    @endif
                </div>

                <div class="mt-6 flex justify-end">
                    <flux:button wire:click="closeDetails" variant="ghost">Close</flux:button>
                </div>
            </div>
        @endif
    </flux:modal>
</div>
