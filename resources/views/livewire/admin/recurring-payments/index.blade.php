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

    {{-- Recurring Payments Table (lazy loaded) --}}
    <livewire:admin.recurring-payments.recurring-table />
</div>
