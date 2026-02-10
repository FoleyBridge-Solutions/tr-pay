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
                                        {{ $payment->next_payment_date->format('M j, Y') }}
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
    <flux:modal wire:model="showDetails" class="max-w-2xl">
        @if($selectedPayment)
            <div class="p-6">
                <flux:heading size="lg" class="mb-4">Recurring Payment Details</flux:heading>

                <div class="grid grid-cols-2 gap-4 mb-6">
                    <div class="space-y-3">
                        <div>
                            <flux:text class="text-sm text-zinc-500">Client</flux:text>
                            <a href="{{ route('admin.clients.show', $selectedPayment->client_id) }}" 
                               class="font-medium text-blue-600 hover:text-blue-800 hover:underline dark:text-blue-400 dark:hover:text-blue-300">
                                {{ $clientNames[$selectedPayment->client_id] ?? $selectedPayment->client_name }}
                            </a>
                        </div>
                        <div>
                            <flux:text class="text-sm text-zinc-500">Amount</flux:text>
                            <flux:text class="font-medium">${{ number_format($selectedPayment->amount, 2) }}</flux:text>
                        </div>
                        <div>
                            <flux:text class="text-sm text-zinc-500">Frequency</flux:text>
                            <flux:text>{{ $selectedPayment->frequency_label }}</flux:text>
                        </div>
                        <div>
                            <flux:text class="text-sm text-zinc-500">Payment Method</flux:text>
                            <flux:text class="capitalize">{{ $selectedPayment->payment_method_type }} ****{{ $selectedPayment->payment_method_last_four }}</flux:text>
                        </div>
                    </div>
                    <div class="space-y-3">
                        <div>
                            <flux:text class="text-sm text-zinc-500">Start Date</flux:text>
                            <flux:text>{{ $selectedPayment->start_date->format('M j, Y') }}</flux:text>
                        </div>
                        <div>
                            <flux:text class="text-sm text-zinc-500">End Date</flux:text>
                            <flux:text>{{ $selectedPayment->end_date?->format('M j, Y') ?? 'No end date' }}</flux:text>
                        </div>
                        @if($selectedPayment->max_occurrences)
                        <div>
                            <flux:text class="text-sm text-zinc-500">Occurrences</flux:text>
                            <flux:text>{{ $selectedPayment->payments_completed }}/{{ $selectedPayment->max_occurrences }} ({{ $selectedPayment->remaining_occurrences }} remaining)</flux:text>
                        </div>
                        @endif
                        <div>
                            <flux:text class="text-sm text-zinc-500">Next Payment</flux:text>
                            <flux:text>{{ $selectedPayment->next_payment_date?->format('M j, Y') ?? '-' }}</flux:text>
                        </div>
                        <div>
                            <flux:text class="text-sm text-zinc-500">Total Collected</flux:text>
                            <flux:text class="font-medium">${{ number_format($selectedPayment->total_collected, 2) }} ({{ $selectedPayment->payments_completed }} payments)</flux:text>
                        </div>
                    </div>
                </div>

                @if($selectedPayment->description)
                    <div class="mb-4">
                        <flux:text class="text-sm text-zinc-500">Description</flux:text>
                        <flux:text>{{ $selectedPayment->description }}</flux:text>
                    </div>
                @endif

                {{-- Payment History --}}
                @if($selectedPayment->payments->count() > 0)
                    <flux:separator class="my-4" />
                    <flux:heading size="md" class="mb-3">Payment History</flux:heading>
                    <div class="max-h-48 overflow-y-auto">
                        <flux:table>
                            <flux:table.columns>
                                <flux:table.column>Date</flux:table.column>
                                <flux:table.column>Amount</flux:table.column>
                                <flux:table.column>Status</flux:table.column>
                            </flux:table.columns>
                            <flux:table.rows>
                                @foreach($selectedPayment->payments->sortByDesc('created_at')->take(10) as $historyPayment)
                                    <flux:table.row>
                                        <flux:table.cell>{{ $historyPayment->created_at->format('M j, Y') }}</flux:table.cell>
                                        <flux:table.cell>${{ number_format($historyPayment->amount, 2) }}</flux:table.cell>
                                        <flux:table.cell>
                                            @if($historyPayment->status === 'completed')
                                                <flux:badge color="green" size="sm">Completed</flux:badge>
                                            @elseif($historyPayment->status === 'failed')
                                                <flux:badge color="red" size="sm">Failed</flux:badge>
                                            @else
                                                <flux:badge size="sm">{{ ucfirst($historyPayment->status) }}</flux:badge>
                                            @endif
                                        </flux:table.cell>
                                    </flux:table.row>
                                @endforeach
                            </flux:table.rows>
                        </flux:table>
                    </div>
                @endif

                <div class="mt-6 flex justify-end gap-2">
                    @if($selectedPayment->status === 'active')
                        <flux:button wire:click="pausePayment({{ $selectedPayment->id }})" variant="ghost">
                            Pause
                        </flux:button>
                    @elseif($selectedPayment->status === 'paused')
                        <flux:button wire:click="resumePayment({{ $selectedPayment->id }})" variant="primary">
                            Resume
                        </flux:button>
                    @endif
                    <flux:button wire:click="closeDetails" variant="ghost">Close</flux:button>
                </div>
            </div>
        @endif
    </flux:modal>

    {{-- Cancel Confirmation Modal --}}
    <flux:modal wire:model="showCancelModal" class="max-w-md">
        <div class="p-6">
            <flux:heading size="lg" class="mb-2">Cancel Recurring Payment</flux:heading>
            <flux:text class="text-zinc-500 mb-4">
                Are you sure you want to cancel this recurring payment? No future payments will be processed.
            </flux:text>

            <div class="flex justify-end gap-2">
                <flux:button wire:click="$set('showCancelModal', false)" variant="ghost">
                    Keep Active
                </flux:button>
                <flux:button wire:click="cancelPayment" variant="danger">
                    Cancel Payment
                </flux:button>
            </div>
        </div>
    </flux:modal>
</div>
