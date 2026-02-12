<div>
    <div class="flex items-center justify-between mb-8">
        <div>
            <flux:heading size="xl">Payment Plans</flux:heading>
            <flux:subheading>Manage recurring payment plans</flux:subheading>
        </div>
        <flux:button href="{{ route('admin.payment-plans.create') }}" variant="primary" icon="plus">
            Create Plan
        </flux:button>
    </div>

    {{-- Filters --}}
    <flux:card class="mb-6">
        <div class="flex flex-col md:flex-row gap-4 p-4">
            <div class="flex-1">
                <flux:input 
                    wire:model.live.debounce.300ms="search" 
                    placeholder="Search by plan ID..." 
                    icon="magnifying-glass"
                />
            </div>
            <div class="w-full md:w-48">
                <flux:select wire:model.live="status">
                    <option value="">All Statuses</option>
                    <option value="active">Active</option>
                    <option value="completed">Completed</option>
                    <option value="past_due">Past Due</option>
                    <option value="cancelled">Cancelled</option>
                    <option value="failed">Failed</option>
                </flux:select>
            </div>
        </div>
    </flux:card>

    {{-- Plans Table --}}
    <flux:card>
        @if($plans->isEmpty())
            <div class="p-12 text-center">
                <flux:icon name="calendar" class="w-12 h-12 mx-auto text-zinc-400 mb-4" />
                <flux:heading size="lg">No payment plans found</flux:heading>
                <flux:text class="text-zinc-500 mb-4">Try adjusting your search or filters</flux:text>
                <flux:button href="{{ route('admin.payment-plans.create') }}" variant="primary" icon="plus">
                    Create Payment Plan
                </flux:button>
            </div>
        @else
            <flux:table>
                <flux:table.columns>
                    <flux:table.column>Plan ID</flux:table.column>
                    <flux:table.column>Amount</flux:table.column>
                    <flux:table.column>Duration</flux:table.column>
                    <flux:table.column>Progress</flux:table.column>
                    <flux:table.column>Status</flux:table.column>
                    <flux:table.column>Next Payment</flux:table.column>
                    <flux:table.column></flux:table.column>
                </flux:table.columns>
                <flux:table.rows>
                    @foreach($plans as $plan)
                        <flux:table.row wire:key="plan-{{ $plan->id }}">
                            <flux:table.cell>
                                <span class="font-mono text-sm">{{ Str::limit($plan->plan_id, 20) }}</span>
                            </flux:table.cell>
                            <flux:table.cell>
                                <div>
                                    <span class="font-medium">${{ number_format($plan->total_amount, 2) }}</span>
                                    <span class="text-zinc-500 text-sm block">${{ number_format($plan->monthly_payment, 2) }}/mo</span>
                                </div>
                            </flux:table.cell>
                            <flux:table.cell>
                                {{ $plan->duration_months }} months
                            </flux:table.cell>
                            <flux:table.cell>
                                <div class="flex items-center gap-2">
                                    <div class="flex-1 bg-zinc-200 dark:bg-zinc-700 rounded-full h-2 w-20">
                                        <div 
                                            class="bg-green-500 h-2 rounded-full" 
                                            style="width: {{ ($plan->payments_completed / $plan->duration_months) * 100 }}%"
                                        ></div>
                                    </div>
                                    <span class="text-sm text-zinc-500">
                                        {{ $plan->payments_completed }}/{{ $plan->duration_months }}
                                    </span>
                                </div>
                            </flux:table.cell>
                            <flux:table.cell>
                                @if($plan->status === 'active')
                                    <flux:badge color="green" size="sm">Active</flux:badge>
                                @elseif($plan->status === 'completed')
                                    <flux:badge color="blue" size="sm">Completed</flux:badge>
                                @elseif($plan->status === 'past_due')
                                    <flux:badge color="amber" size="sm">Past Due</flux:badge>
                                @elseif($plan->status === 'cancelled')
                                    <flux:badge color="zinc" size="sm">Cancelled</flux:badge>
                                @elseif($plan->status === 'failed')
                                    <flux:badge color="red" size="sm">Failed</flux:badge>
                                @else
                                    <flux:badge size="sm">{{ ucfirst($plan->status) }}</flux:badge>
                                @endif
                            </flux:table.cell>
                            <flux:table.cell class="text-zinc-500">
                                @if($plan->next_payment_date)
                                    <local-time datetime="{{ $plan->next_payment_date->toIso8601String() }}" format="date"></local-time>
                                @else
                                    -
                                @endif
                            </flux:table.cell>
                            <flux:table.cell>
                                <div class="flex gap-1">
                                    <flux:button wire:click="viewPlan({{ $plan->id }})" variant="ghost" size="sm" icon="eye">
                                        View
                                    </flux:button>
                                    @if($plan->status === 'active' || $plan->status === 'past_due')
                                        <flux:button wire:click="confirmCancel({{ $plan->id }})" variant="ghost" size="sm" icon="x-mark" class="text-red-600 hover:text-red-700">
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
                {{ $plans->links() }}
            </div>
        @endif
    </flux:card>

    {{-- Plan Details Modal --}}
    <flux:modal name="plan-details" class="max-w-2xl" @close="closeDetails">
        <div class="p-6" x-data="{ get d() { return $wire.planDetails }, get payments() { return $wire.planPayments } }">
            <flux:heading size="lg" class="mb-4">Payment Plan Details</flux:heading>
            
            <div class="grid grid-cols-2 gap-4 mb-6">
                <div class="space-y-3">
                    <div>
                        <span class="text-zinc-500 text-sm">Plan ID</span>
                        <p class="font-mono text-sm" x-text="d.plan_id ?? '-'"></p>
                    </div>
                    <div>
                        <span class="text-zinc-500 text-sm">Total Amount</span>
                        <p class="font-medium" x-text="'$' + (d.total_amount ?? '0.00')"></p>
                    </div>
                    <div>
                        <span class="text-zinc-500 text-sm">Invoice Amount</span>
                        <p x-text="'$' + (d.invoice_amount ?? '0.00')"></p>
                    </div>
                    <div>
                        <span class="text-zinc-500 text-sm">Plan Fee</span>
                        <p x-text="'$' + (d.plan_fee ?? '0.00')"></p>
                    </div>
                </div>
                <div class="space-y-3">
                    <div>
                        <span class="text-zinc-500 text-sm">Monthly Payment</span>
                        <p class="font-medium" x-text="'$' + (d.monthly_payment ?? '0.00')"></p>
                    </div>
                    <div>
                        <span class="text-zinc-500 text-sm">Duration</span>
                        <p x-text="(d.duration_months ?? '-') + ' months'"></p>
                    </div>
                    <div>
                        <span class="text-zinc-500 text-sm">Amount Paid</span>
                        <p x-text="'$' + (d.amount_paid ?? '0.00')"></p>
                    </div>
                    <div>
                        <span class="text-zinc-500 text-sm">Amount Remaining</span>
                        <p x-text="'$' + (d.amount_remaining ?? '0.00')"></p>
                    </div>
                </div>
            </div>

            <flux:separator class="my-4" />

            <flux:heading size="md" class="mb-3">Payment Schedule</flux:heading>
            <div class="max-h-64 overflow-y-auto">
                <table class="w-full text-sm">
                    <thead class="text-left text-zinc-500 border-b border-zinc-200 dark:border-zinc-700">
                        <tr>
                            <th class="pb-2 font-medium">#</th>
                            <th class="pb-2 font-medium">Amount</th>
                            <th class="pb-2 font-medium">Scheduled</th>
                            <th class="pb-2 font-medium">Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <template x-for="(p, idx) in payments" :key="idx">
                            <tr class="border-b border-zinc-100 dark:border-zinc-800">
                                <td class="py-2" x-text="p.payment_number"></td>
                                <td class="py-2" x-text="'$' + p.amount"></td>
                                <td class="py-2">
                                    <template x-if="p.scheduled_date">
                                        <local-time x-bind:datetime="p.scheduled_date" format="date"></local-time>
                                    </template>
                                    <template x-if="!p.scheduled_date">
                                        <span>-</span>
                                    </template>
                                </td>
                                <td class="py-2">
                                    <template x-if="p.status === 'completed'">
                                        <span class="inline-flex items-center rounded-md bg-green-50 dark:bg-green-500/10 px-2 py-1 text-xs font-medium text-green-700 dark:text-green-400 ring-1 ring-inset ring-green-600/20">Completed</span>
                                    </template>
                                    <template x-if="p.status === 'pending'">
                                        <span class="inline-flex items-center rounded-md bg-amber-50 dark:bg-amber-500/10 px-2 py-1 text-xs font-medium text-amber-700 dark:text-amber-400 ring-1 ring-inset ring-amber-600/20">Pending</span>
                                    </template>
                                    <template x-if="p.status === 'failed'">
                                        <span class="inline-flex items-center rounded-md bg-red-50 dark:bg-red-500/10 px-2 py-1 text-xs font-medium text-red-700 dark:text-red-400 ring-1 ring-inset ring-red-600/20">Failed</span>
                                    </template>
                                    <template x-if="p.status !== 'completed' && p.status !== 'pending' && p.status !== 'failed'">
                                        <span class="inline-flex items-center rounded-md bg-zinc-50 dark:bg-zinc-500/10 px-2 py-1 text-xs font-medium text-zinc-700 dark:text-zinc-400 ring-1 ring-inset ring-zinc-600/20" x-text="p.status ? p.status.charAt(0).toUpperCase() + p.status.slice(1) : ''"></span>
                                    </template>
                                </td>
                            </tr>
                        </template>
                    </tbody>
                </table>
            </div>

            <div class="mt-6 flex justify-end gap-2">
                <template x-if="d.can_cancel">
                    <flux:button x-on:click="$wire.confirmCancel(d.id)" variant="danger">
                        Cancel Plan
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
            <flux:heading size="lg" class="mb-2">Cancel Payment Plan</flux:heading>
            <flux:text class="text-zinc-500 mb-4">
                Are you sure you want to cancel this payment plan? This action cannot be undone.
            </flux:text>

            <flux:field class="mb-4">
                <flux:label>Reason (optional)</flux:label>
                <flux:textarea wire:model="cancelReason" placeholder="Enter cancellation reason..." rows="3" />
            </flux:field>

            <div class="flex justify-end gap-2">
                <flux:modal.close>
                    <flux:button variant="ghost">Keep Plan</flux:button>
                </flux:modal.close>
                <flux:button wire:click="cancelPlan" variant="danger">
                    Cancel Plan
                </flux:button>
            </div>
        </div>
    </flux:modal>
</div>
