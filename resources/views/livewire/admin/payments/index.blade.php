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

    {{-- Payments table with filters (lazy loaded) --}}
    <livewire:admin.payments.payments-table />
</div>
