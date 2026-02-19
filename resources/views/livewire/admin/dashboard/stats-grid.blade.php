<div wire:poll.30s>
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
</div>
