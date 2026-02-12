<div>
    <div class="mb-8">
        <flux:heading size="xl">Dashboard</flux:heading>
        <flux:subheading>Welcome back, {{ Auth::user()->name }}</flux:subheading>
        <div class="mt-2 flex items-center gap-2 text-xs text-zinc-400">
            <flux:icon name="clock" class="w-3.5 h-3.5" />
            <local-time datetime="{{ now()->toIso8601String() }}" format="datetime"></local-time>
        </div>
    </div>

    {{-- Stats Grid --}}
    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4 mb-8">
        {{-- Payments Today --}}
        <flux:card class="p-4">
            <div class="flex items-center justify-between">
                <div>
                    <flux:text class="text-sm text-zinc-500">Payments Today</flux:text>
                    <flux:heading size="xl" class="mt-1">{{ $stats['payments_today'] }}</flux:heading>
                    <flux:text class="text-sm text-zinc-500">${{ number_format($stats['payments_today_amount'], 2) }}</flux:text>
                </div>
                <div class="p-3 bg-green-100 dark:bg-green-900 rounded-full">
                    <flux:icon name="currency-dollar" class="w-6 h-6 text-green-600 dark:text-green-400" />
                </div>
            </div>
        </flux:card>

        {{-- Active Payment Plans --}}
        <flux:card class="p-4">
            <div class="flex items-center justify-between">
                <div>
                    <flux:text class="text-sm text-zinc-500">Active Plans</flux:text>
                    <flux:heading size="xl" class="mt-1">{{ $stats['active_plans'] }}</flux:heading>
                    <flux:text class="text-sm text-zinc-500">{{ $stats['past_due_plans'] }} past due</flux:text>
                </div>
                <div class="p-3 bg-blue-100 dark:bg-blue-900 rounded-full">
                    <flux:icon name="calendar" class="w-6 h-6 text-blue-600 dark:text-blue-400" />
                </div>
            </div>
        </flux:card>

        {{-- Payments This Month --}}
        <flux:card class="p-4">
            <div class="flex items-center justify-between">
                <div>
                    <flux:text class="text-sm text-zinc-500">This Month</flux:text>
                    <flux:heading size="xl" class="mt-1">{{ $stats['payments_this_month'] }}</flux:heading>
                    <flux:text class="text-sm text-zinc-500">${{ number_format($stats['payments_this_month_amount'], 2) }}</flux:text>
                </div>
                <div class="p-3 bg-purple-100 dark:bg-purple-900 rounded-full">
                    <flux:icon name="chart-bar" class="w-6 h-6 text-purple-600 dark:text-purple-400" />
                </div>
            </div>
        </flux:card>

        {{-- Due This Week --}}
        <flux:card class="p-4">
            <div class="flex items-center justify-between">
                <div>
                    <flux:text class="text-sm text-zinc-500">Due This Week</flux:text>
                    <flux:heading size="xl" class="mt-1">{{ $stats['payments_due_this_week'] }}</flux:heading>
                    <flux:text class="text-sm text-zinc-500">{{ $stats['failed_payments'] }} failed this month</flux:text>
                </div>
                <div class="p-3 bg-amber-100 dark:bg-amber-900 rounded-full">
                    <flux:icon name="clock" class="w-6 h-6 text-amber-600 dark:text-amber-400" />
                </div>
            </div>
        </flux:card>
    </div>

    {{-- Quick Actions --}}
    <div class="mb-8">
        <flux:heading size="lg" class="mb-4">Quick Actions</flux:heading>
        <div class="flex flex-wrap gap-3">
            <flux:button href="{{ route('admin.payments.create') }}" variant="primary" icon="currency-dollar">
                Create Single Payment
            </flux:button>
            <flux:button href="{{ route('admin.payment-plans.create') }}" variant="primary" icon="plus">
                Create Payment Plan
            </flux:button>
            <flux:button href="{{ route('admin.clients') }}" variant="ghost" icon="magnifying-glass">
                Search Clients
            </flux:button>
            <flux:button href="{{ route('admin.payments') }}" variant="ghost" icon="document-text">
                View All Payments
            </flux:button>
        </div>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-2 gap-8">
        {{-- Recent Payments --}}
        <div>
            <div class="flex items-center justify-between mb-4">
                <flux:heading size="lg">Recent Payments</flux:heading>
                <flux:button href="{{ route('admin.payments') }}" variant="ghost" size="sm">
                    View All
                </flux:button>
            </div>
            
            <flux:card>
                @if($recentPayments->isEmpty())
                    <div class="p-6 text-center">
                        <flux:text class="text-zinc-500">No payments yet</flux:text>
                    </div>
                @else
                    <flux:table>
                        <flux:table.columns>
                            <flux:table.column>Amount</flux:table.column>
                            <flux:table.column>Status</flux:table.column>
                            <flux:table.column>Date</flux:table.column>
                        </flux:table.columns>
                        <flux:table.rows>
                            @foreach($recentPayments as $payment)
                                <flux:table.row>
                                    <flux:table.cell>
                                        <span class="font-medium">${{ number_format($payment->amount, 2) }}</span>
                                    </flux:table.cell>
                                    <flux:table.cell>
                                        @if($payment->status === 'completed')
                                            <flux:badge color="green" size="sm">Completed</flux:badge>
                                        @elseif($payment->status === 'pending')
                                            <flux:badge color="amber" size="sm">Pending</flux:badge>
                                        @elseif($payment->status === 'failed')
                                            <flux:badge color="red" size="sm">Failed</flux:badge>
                                        @else
                                            <flux:badge size="sm">{{ ucfirst($payment->status) }}</flux:badge>
                                        @endif
                                    </flux:table.cell>
                                    <flux:table.cell class="text-zinc-500">
                                        <local-time datetime="{{ $payment->created_at->toIso8601String() }}" format="short"></local-time>
                                    </flux:table.cell>
                                </flux:table.row>
                            @endforeach
                        </flux:table.rows>
                    </flux:table>
                @endif
            </flux:card>
        </div>

        {{-- Recent Payment Plans --}}
        <div>
            <div class="flex items-center justify-between mb-4">
                <flux:heading size="lg">Recent Payment Plans</flux:heading>
                <flux:button href="{{ route('admin.payment-plans') }}" variant="ghost" size="sm">
                    View All
                </flux:button>
            </div>
            
            <flux:card>
                @if($recentPlans->isEmpty())
                    <div class="p-6 text-center">
                        <flux:text class="text-zinc-500">No payment plans yet</flux:text>
                    </div>
                @else
                    <flux:table>
                        <flux:table.columns>
                            <flux:table.column>Plan</flux:table.column>
                            <flux:table.column>Status</flux:table.column>
                            <flux:table.column>Progress</flux:table.column>
                        </flux:table.columns>
                        <flux:table.rows>
                            @foreach($recentPlans as $plan)
                                <flux:table.row>
                                    <flux:table.cell>
                                        <div>
                                            <span class="font-medium">${{ number_format($plan->total_amount, 2) }}</span>
                                            <span class="text-zinc-500 text-sm block">{{ $plan->duration_months }} months</span>
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
                                        @else
                                            <flux:badge color="red" size="sm">{{ ucfirst($plan->status) }}</flux:badge>
                                        @endif
                                    </flux:table.cell>
                                    <flux:table.cell class="text-zinc-500">
                                        {{ $plan->payments_completed }}/{{ $plan->duration_months }}
                                    </flux:table.cell>
                                </flux:table.row>
                            @endforeach
                        </flux:table.rows>
                    </flux:table>
                @endif
            </flux:card>
        </div>
    </div>
</div>
