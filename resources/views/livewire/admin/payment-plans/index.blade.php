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

    {{-- Plans table with filters, pagination, and modals (lazy loaded) --}}
    <livewire:admin.payment-plans.plans-table />
</div>
