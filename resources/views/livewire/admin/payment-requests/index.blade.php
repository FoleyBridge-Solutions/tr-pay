<div>
    <div class="flex items-center justify-between mb-8">
        <div>
            <flux:heading size="xl">Payment Requests</flux:heading>
            <flux:subheading>Manage payment request links sent to clients</flux:subheading>
        </div>
    </div>

    {{-- Lazy-loaded requests table with filters and actions --}}
    <livewire:admin.payment-requests.requests-table />
</div>
