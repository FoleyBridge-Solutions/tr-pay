<div>
    {{-- Header --}}
    <div class="flex items-center justify-between mb-8">
        <div>
            <flux:heading size="xl">Client Details</flux:heading>
            <flux:subheading>Client ID: {{ $clientId }}</flux:subheading>
        </div>
        <div class="flex gap-2">
            <flux:button href="{{ route('admin.clients') }}" variant="ghost" icon="arrow-left">
                Back to Clients
            </flux:button>
        </div>
    </div>

    {{-- Lazy-loaded client details --}}
    <livewire:admin.clients.client-details :client-id="$clientId" />
</div>
