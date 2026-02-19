<div>
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
                <flux:select wire:model.live="status" multiple placeholder="All Statuses">
                    <flux:select.option value="completed">Completed</flux:select.option>
                    <flux:select.option value="processing">Processing</flux:select.option>
                    <flux:select.option value="pending">Pending</flux:select.option>
                    <flux:select.option value="failed">Failed</flux:select.option>
                    <flux:select.option value="refunded">Refunded</flux:select.option>
                    <flux:select.option value="returned">Returned</flux:select.option>
                    <flux:select.option value="voided">Voided</flux:select.option>
                    <flux:select.option value="skipped">Skipped</flux:select.option>
                </flux:select>
            </div>
            <div class="w-full md:w-48">
                <flux:select wire:model.live="dateRange" multiple placeholder="All Time">
                    <flux:select.option value="today">Today</flux:select.option>
                    <flux:select.option value="week">This Week</flux:select.option>
                    <flux:select.option value="month">This Month</flux:select.option>
                    <flux:select.option value="year">This Year</flux:select.option>
                </flux:select>
            </div>
            <div class="w-full md:w-48">
                <flux:select wire:model.live="source" multiple placeholder="All Sources">
                    <flux:select.option value="tr-pay">Public Portal</flux:select.option>
                    <flux:select.option value="tr-pay-admin">Admin</flux:select.option>
                    <flux:select.option value="tr-pay-email">Email Request</flux:select.option>
                    <flux:select.option value="admin-scheduled">Scheduled</flux:select.option>
                    <flux:select.option value="tr-pay-recurring">Recurring</flux:select.option>
                    <flux:select.option value="plan-installment">Plan Installment</flux:select.option>
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
                    <flux:table.column>Source</flux:table.column>
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
                                <flux:badge color="{{ $payment->source_badge_color }}" size="sm">{{ $payment->source_label }}</flux:badge>
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
                                @elseif($payment->status === 'returned')
                                    <flux:badge color="orange" size="sm">Returned</flux:badge>
                                @elseif($payment->status === 'voided')
                                    <flux:badge color="purple" size="sm">Voided</flux:badge>
                                @elseif($payment->status === 'skipped')
                                    <flux:badge color="zinc" size="sm" variant="outline">Skipped</flux:badge>
                                @else
                                    <flux:badge size="sm">{{ ucfirst($payment->status) }}</flux:badge>
                                @endif
                            </flux:table.cell>
                            <flux:table.cell>
                                @if($payment->payment_plan_id)
                                    <div>
                                        <flux:badge color="blue" size="sm">{{ Str::limit($payment->paymentPlan?->plan_id ?? 'Plan', 16) }}</flux:badge>
                                        <span class="text-zinc-500 text-xs block mt-0.5">
                                            @if($payment->payment_number)
                                                {{ $payment->payment_number }} of {{ $payment->paymentPlan?->duration_months ?? '?' }}
                                            @else
                                                Down Payment
                                            @endif
                                        </span>
                                    </div>
                                @else
                                    <span class="text-zinc-400">-</span>
                                @endif
                            </flux:table.cell>
                            <flux:table.cell class="text-zinc-500">
                                <local-time datetime="{{ $payment->created_at->toIso8601String() }}"></local-time>
                            </flux:table.cell>
                            <flux:table.cell>
                                <div class="flex items-center gap-1">
                                    <flux:button wire:click="viewPayment({{ $payment->id }})" variant="ghost" size="sm" icon="eye">
                                        View
                                    </flux:button>
                                    @if($payment->status === 'processing' && $payment->payment_vendor === 'kotapay')
                                        <flux:button 
                                            wire:click="voidPayment({{ $payment->id }})" 
                                            wire:confirm="Are you sure you want to void this ACH payment of ${{ number_format($payment->amount, 2) }}?"
                                            variant="ghost" 
                                            size="sm" 
                                            icon="x-circle"
                                            class="text-red-600 hover:text-red-800 dark:text-red-400"
                                        >
                                            Void
                                        </flux:button>
                                    @elseif($payment->status === 'completed' && !$payment->payment_vendor)
                                        <flux:button 
                                            wire:click="refundPayment({{ $payment->id }})" 
                                            wire:confirm="Are you sure you want to refund this card payment of ${{ number_format($payment->amount, 2) }}?"
                                            variant="ghost" 
                                            size="sm" 
                                            icon="arrow-uturn-left"
                                            class="text-red-600 hover:text-red-800 dark:text-red-400"
                                        >
                                            Refund
                                        </flux:button>
                                    @endif
                                </div>
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
                    <span class="text-zinc-500">Source</span>
                    <span x-text="d.source_label ?? '-'"></span>
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
                        <template x-if="d.status === 'returned'">
                            <flux:badge color="orange" size="sm">Returned</flux:badge>
                        </template>
                        <template x-if="d.status === 'voided'">
                            <flux:badge color="purple" size="sm">Voided</flux:badge>
                        </template>
                        <template x-if="d.status === 'skipped'">
                            <flux:badge color="zinc" size="sm" variant="outline">Skipped</flux:badge>
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
                        <span x-text="d.payment_number ? ('Installment ' + d.payment_number + ' of ' + (d.plan_duration ?? '')) : 'Down Payment'"></span>
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

            <div class="mt-6 flex justify-between">
                <div>
                    @if($selectedPayment?->status === 'processing' && $selectedPayment?->payment_vendor === 'kotapay')
                        <flux:button 
                            wire:click="voidPayment({{ $selectedPayment->id }})" 
                            wire:confirm="Are you sure you want to void this ACH payment of ${{ number_format($selectedPayment->amount, 2) }}?"
                            variant="danger" 
                            size="sm" 
                            icon="x-circle"
                        >
                            Void Payment
                        </flux:button>
                    @elseif($selectedPayment?->status === 'completed' && !$selectedPayment?->payment_vendor)
                        <flux:button 
                            wire:click="refundPayment({{ $selectedPayment->id }})" 
                            wire:confirm="Are you sure you want to refund this card payment of ${{ number_format($selectedPayment->amount, 2) }}?"
                            variant="danger" 
                            size="sm" 
                            icon="arrow-uturn-left"
                        >
                            Refund Payment
                        </flux:button>
                    @endif
                </div>
                <flux:modal.close>
                    <flux:button variant="ghost">Close</flux:button>
                </flux:modal.close>
            </div>
        </div>
    </flux:modal>
</div>
