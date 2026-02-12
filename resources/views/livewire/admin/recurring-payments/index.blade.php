<div>
    <div class="flex items-center justify-between mb-8">
        <div>
            <flux:heading size="xl">Recurring Payments</flux:heading>
            <flux:subheading>Manage scheduled recurring payments</flux:subheading>
        </div>
        <div class="flex gap-2">
            <flux:button href="{{ route('admin.recurring-payments.import') }}" variant="ghost" icon="arrow-up-tray">
                Import CSV
            </flux:button>
            <flux:button href="{{ route('admin.recurring-payments.create') }}" variant="primary" icon="plus">
                Add Payment
            </flux:button>
        </div>
    </div>

    {{-- Filters --}}
    <flux:card class="mb-6">
        <div class="flex flex-col md:flex-row gap-4 p-4">
            <div class="flex-1">
                <flux:input
                    wire:model.live.debounce.300ms="search"
                    placeholder="Search by client name or description..."
                    icon="magnifying-glass"
                />
            </div>
            <div class="w-full md:w-40">
                <flux:select wire:model.live="status">
                    <option value="">All Statuses</option>
                    <option value="active">Active</option>
                    <option value="pending">Pending</option>
                    <option value="paused">Paused</option>
                    <option value="cancelled">Cancelled</option>
                    <option value="completed">Completed</option>
                </flux:select>
            </div>
            <div class="w-full md:w-40">
                <flux:select wire:model.live="frequency">
                    <option value="">All Frequencies</option>
                    @foreach($frequencies as $value => $label)
                        <option value="{{ $value }}">{{ $label }}</option>
                    @endforeach
                </flux:select>
            </div>
        </div>
    </flux:card>

    {{-- Recurring Payments Table --}}
    <flux:card>
        @if($payments->isEmpty())
            <div class="p-12 text-center">
                <flux:icon name="arrow-path" class="w-12 h-12 mx-auto text-zinc-400 mb-4" />
                <flux:heading size="lg">No recurring payments found</flux:heading>
                <flux:text class="text-zinc-500 mb-4">Create a new recurring payment or import from CSV</flux:text>
                <div class="flex justify-center gap-2">
                    <flux:button href="{{ route('admin.recurring-payments.import') }}" variant="ghost" icon="arrow-up-tray">
                        Import CSV
                    </flux:button>
                    <flux:button href="{{ route('admin.recurring-payments.create') }}" variant="primary" icon="plus">
                        Add Payment
                    </flux:button>
                </div>
            </div>
        @else
            <flux:table>
                <flux:table.columns>
                    <flux:table.column sortable :sorted="$sortField === 'client_id'" :direction="$sortDirection" wire:click="sortBy('client_id')">Client</flux:table.column>
                    <flux:table.column sortable :sorted="$sortField === 'amount'" :direction="$sortDirection" wire:click="sortBy('amount')">Amount</flux:table.column>
                    <flux:table.column>Frequency</flux:table.column>
                    <flux:table.column sortable :sorted="$sortField === 'next_payment_date'" :direction="$sortDirection" wire:click="sortBy('next_payment_date')">Next Payment</flux:table.column>
                    <flux:table.column sortable :sorted="$sortField === 'status'" :direction="$sortDirection" wire:click="sortBy('status')">Status</flux:table.column>
                    <flux:table.column>Collected</flux:table.column>
                    <flux:table.column></flux:table.column>
                </flux:table.columns>
                <flux:table.rows>
                    @foreach($payments as $payment)
                        <flux:table.row wire:key="recurring-{{ $payment->id }}">
                            <flux:table.cell>
                                <div>
                                    <a href="{{ route('admin.clients.show', $payment->client_id) }}" 
                                       class="font-medium text-blue-600 hover:text-blue-800 hover:underline dark:text-blue-400 dark:hover:text-blue-300">
                                        {{ $clientNames[$payment->client_id] ?? $payment->client_name }}
                                    </a>
                                    @if($payment->description)
                                        <span class="text-zinc-500 text-sm block">{{ Str::limit($payment->description, 30) }}</span>
                                    @endif
                                </div>
                            </flux:table.cell>
                            <flux:table.cell>
                                <span class="font-medium">${{ number_format($payment->amount, 2) }}</span>
                            </flux:table.cell>
                            <flux:table.cell>
                                {{ $payment->frequency_label }}
                            </flux:table.cell>
                            <flux:table.cell>
                                @if($payment->next_payment_date)
                                    <span class="{{ $payment->next_payment_date->isPast() ? 'text-amber-600' : '' }}">
                                        <local-time datetime="{{ $payment->next_payment_date->toIso8601String() }}" format="date"></local-time>
                                    </span>
                                @else
                                    <span class="text-zinc-400">-</span>
                                @endif
                            </flux:table.cell>
                            <flux:table.cell>
                                @if($payment->status === 'active')
                                    <flux:badge color="green" size="sm">Active</flux:badge>
                                @elseif($payment->status === 'pending')
                                    <flux:badge color="amber" size="sm">Pending</flux:badge>
                                @elseif($payment->status === 'paused')
                                    <flux:badge color="amber" size="sm">Paused</flux:badge>
                                @elseif($payment->status === 'cancelled')
                                    <flux:badge color="zinc" size="sm">Cancelled</flux:badge>
                                @elseif($payment->status === 'completed')
                                    <flux:badge color="blue" size="sm">Completed</flux:badge>
                                @else
                                    <flux:badge size="sm">{{ ucfirst($payment->status) }}</flux:badge>
                                @endif
                            </flux:table.cell>
                            <flux:table.cell>
                                <span class="text-zinc-500">${{ number_format($payment->total_collected, 2) }}</span>
                                <span class="text-zinc-400 text-sm block">
                                    {{ $payment->payments_completed }}{{ $payment->max_occurrences ? '/'.$payment->max_occurrences : '' }} payments
                                </span>
                            </flux:table.cell>
                            <flux:table.cell>
                                <div class="flex gap-1">
                                    <flux:button wire:click="viewPayment({{ $payment->id }})" variant="ghost" size="sm" icon="eye">
                                        View
                                    </flux:button>
                                    @if($payment->status === 'active')
                                        <flux:button wire:click="pausePayment({{ $payment->id }})" variant="ghost" size="sm" icon="pause">
                                            Pause
                                        </flux:button>
                                    @elseif($payment->status === 'paused')
                                        <flux:button wire:click="resumePayment({{ $payment->id }})" variant="ghost" size="sm" icon="play">
                                            Resume
                                        </flux:button>
                                    @endif
                                    @if(in_array($payment->status, ['active', 'paused']))
                                        <flux:button wire:click="confirmCancel({{ $payment->id }})" variant="ghost" size="sm" icon="x-mark" class="text-red-600 hover:text-red-700">
                                            Cancel
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

    {{-- Details Modal --}}
    <flux:modal name="recurring-payment-details" class="max-w-2xl" @close="closeDetails">
        <div class="p-6" x-data="{ get d() { return $wire.recurringDetails }, get history() { return $wire.recurringHistory } }">
            <flux:heading size="lg" class="mb-4">Recurring Payment Details</flux:heading>

            <div class="grid grid-cols-2 gap-4 mb-6">
                <div class="space-y-3">
                    <div>
                        <flux:text class="text-sm text-zinc-500">Client</flux:text>
                        <template x-if="d.client_url">
                            <a x-bind:href="d.client_url"
                               class="font-medium text-blue-600 hover:text-blue-800 hover:underline dark:text-blue-400 dark:hover:text-blue-300"
                               x-text="d.client_name ?? '-'"></a>
                        </template>
                        <template x-if="!d.client_url">
                            <flux:text>-</flux:text>
                        </template>
                    </div>
                    <div>
                        <flux:text class="text-sm text-zinc-500">Amount</flux:text>
                        <flux:text class="font-medium"><span x-text="'$' + (d.amount ?? '0.00')"></span></flux:text>
                    </div>
                    <div>
                        <flux:text class="text-sm text-zinc-500">Frequency</flux:text>
                        <flux:text><span x-text="d.frequency_label ?? '-'"></span></flux:text>
                    </div>
                    <div>
                        <flux:text class="text-sm text-zinc-500">Payment Method</flux:text>
                        <flux:text class="capitalize"><span x-text="(d.payment_method_type ?? '-')"></span><span x-show="d.payment_method_last_four" x-text="'****' + (d.payment_method_last_four ?? '')"></span></flux:text>
                    </div>
                </div>
                <div class="space-y-3">
                    <div>
                        <flux:text class="text-sm text-zinc-500">Start Date</flux:text>
                        <flux:text>
                            <template x-if="d.start_date">
                                <local-time x-bind:datetime="d.start_date" format="date"></local-time>
                            </template>
                            <template x-if="!d.start_date">
                                <span>-</span>
                            </template>
                        </flux:text>
                    </div>
                    <div>
                        <flux:text class="text-sm text-zinc-500">End Date</flux:text>
                        <flux:text>
                            <template x-if="d.end_date">
                                <local-time x-bind:datetime="d.end_date" format="date"></local-time>
                            </template>
                            <template x-if="!d.end_date">
                                <span>No end date</span>
                            </template>
                        </flux:text>
                    </div>
                    <template x-if="d.has_max_occurrences">
                        <div>
                            <flux:text class="text-sm text-zinc-500">Occurrences</flux:text>
                            <flux:text><span x-text="d.payments_completed + '/' + d.max_occurrences + ' (' + d.remaining_occurrences + ' remaining)'"></span></flux:text>
                        </div>
                    </template>
                    <div>
                        <flux:text class="text-sm text-zinc-500">Next Payment</flux:text>
                        <flux:text>
                            <template x-if="d.next_payment_date">
                                <local-time x-bind:datetime="d.next_payment_date" format="date"></local-time>
                            </template>
                            <template x-if="!d.next_payment_date">
                                <span>-</span>
                            </template>
                        </flux:text>
                    </div>
                    <div>
                        <flux:text class="text-sm text-zinc-500">Total Collected</flux:text>
                        <flux:text class="font-medium"><span x-text="'$' + (d.total_collected ?? '0.00') + ' (' + (d.payments_completed ?? 0) + ' payments)'"></span></flux:text>
                    </div>
                </div>
            </div>

            <template x-if="d.description">
                <div class="mb-4">
                    <flux:text class="text-sm text-zinc-500">Description</flux:text>
                    <flux:text><span x-text="d.description"></span></flux:text>
                </div>
            </template>

            {{-- Payment History --}}
            <template x-if="d.has_history">
                <div>
                    <flux:separator class="my-4" />
                    <flux:heading size="md" class="mb-3">Payment History</flux:heading>
                    <div class="max-h-48 overflow-y-auto">
                        <table class="w-full text-sm">
                            <thead class="text-left text-zinc-500 border-b border-zinc-200 dark:border-zinc-700">
                                <tr>
                                    <th class="pb-2 font-medium">Date</th>
                                    <th class="pb-2 font-medium">Amount</th>
                                    <th class="pb-2 font-medium">Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <template x-for="(h, idx) in history" :key="idx">
                                    <tr class="border-b border-zinc-100 dark:border-zinc-800">
                                        <td class="py-2"><local-time x-bind:datetime="h.created_at" format="date"></local-time></td>
                                        <td class="py-2" x-text="'$' + h.amount"></td>
                                        <td class="py-2">
                                            <template x-if="h.status === 'completed'">
                                                <span class="inline-flex items-center rounded-md bg-green-50 dark:bg-green-500/10 px-2 py-1 text-xs font-medium text-green-700 dark:text-green-400 ring-1 ring-inset ring-green-600/20">Completed</span>
                                            </template>
                                            <template x-if="h.status === 'failed'">
                                                <span class="inline-flex items-center rounded-md bg-red-50 dark:bg-red-500/10 px-2 py-1 text-xs font-medium text-red-700 dark:text-red-400 ring-1 ring-inset ring-red-600/20">Failed</span>
                                            </template>
                                            <template x-if="h.status !== 'completed' && h.status !== 'failed'">
                                                <span class="inline-flex items-center rounded-md bg-zinc-50 dark:bg-zinc-500/10 px-2 py-1 text-xs font-medium text-zinc-700 dark:text-zinc-400 ring-1 ring-inset ring-zinc-600/20" x-text="h.status ? h.status.charAt(0).toUpperCase() + h.status.slice(1) : ''"></span>
                                            </template>
                                        </td>
                                    </tr>
                                </template>
                            </tbody>
                        </table>
                    </div>
                </div>
            </template>

            <div class="mt-6 flex justify-end gap-2">
                <template x-if="d.status === 'active'">
                    <flux:button x-on:click="$wire.pausePayment(d.id)" variant="ghost">
                        Pause
                    </flux:button>
                </template>
                <template x-if="d.status === 'paused'">
                    <flux:button x-on:click="$wire.resumePayment(d.id)" variant="primary">
                        Resume
                    </flux:button>
                </template>
                <flux:modal.close>
                    <flux:button variant="ghost">Close</flux:button>
                </flux:modal.close>
            </div>
        </div>
    </flux:modal>

    {{-- Cancel Confirmation Modal --}}
    <flux:modal wire:model.self="showCancelModal" class="max-w-md" :dismissible="false" @close="resetCancelModal">
        <div class="p-6">
            <flux:heading size="lg" class="mb-2">Cancel Recurring Payment</flux:heading>
            <flux:text class="text-zinc-500 mb-4">
                Are you sure you want to cancel this recurring payment? No future payments will be processed.
            </flux:text>

            <div class="flex justify-end gap-2">
                <flux:modal.close>
                    <flux:button variant="ghost">Keep Active</flux:button>
                </flux:modal.close>
                <flux:button wire:click="cancelPayment" variant="danger">
                    Cancel Payment
                </flux:button>
            </div>
        </div>
    </flux:modal>
</div>
