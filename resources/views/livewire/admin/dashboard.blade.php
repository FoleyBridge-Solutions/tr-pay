<div>
    <div class="mb-8">
        <flux:heading size="xl">Dashboard</flux:heading>
        <flux:subheading>Welcome back, {{ Auth::user()->name }}</flux:subheading>
        <div class="mt-2 flex items-center gap-2 text-xs text-zinc-400">
            <flux:icon name="clock" class="w-3.5 h-3.5" />
            <local-time datetime="{{ now()->toIso8601String() }}" format="datetime"></local-time>
        </div>
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

    {{-- Active Alerts (lazy loaded) --}}
    <livewire:admin.dashboard.alerts-list />

    {{-- Stats Grid (lazy loaded) --}}
    <livewire:admin.dashboard.stats-grid />

    <div class="grid grid-cols-1 lg:grid-cols-2 gap-8">
        {{-- Recent Payments (lazy loaded) --}}
        <livewire:admin.dashboard.recent-payments />

        {{-- Recent Payment Plans (lazy loaded) --}}
        <livewire:admin.dashboard.recent-plans />
    </div>
</div>
