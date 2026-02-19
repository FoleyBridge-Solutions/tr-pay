<div>
    <div class="mb-8">
        <div class="flex items-center gap-2 text-sm text-zinc-500 mb-2">
            <a href="{{ route('admin.clients') }}" class="hover:text-zinc-700 dark:hover:text-zinc-300">Clients</a>
            <flux:icon name="chevron-right" class="w-4 h-4" />
            <span>Payment Methods</span>
        </div>
        <div class="flex items-center justify-between">
            <div>
                <flux:heading size="xl">Manage Payment Methods</flux:heading>
                @if($clientInfo)
                    <flux:subheading>{{ $clientInfo['client_name'] }} ({{ $clientInfo['client_id'] }})</flux:subheading>
                @endif
            </div>
        </div>
    </div>

    {{-- No Client Selected --}}
    @if(!$clientId)
        <flux:card class="max-w-lg mx-auto">
            <div class="p-8 text-center">
                <flux:icon name="user" class="w-12 h-12 mx-auto text-zinc-400 mb-4" />
                <flux:heading size="lg" class="mb-2">No Client Selected</flux:heading>
                <flux:text class="text-zinc-500 mb-4">
                    Please select a client from the clients list to manage their payment methods.
                </flux:text>
                <flux:button href="{{ route('admin.clients') }}" variant="primary">
                    Go to Clients
                </flux:button>
            </div>
        </flux:card>
    @else
        {{-- Lazy-loaded content --}}
        <livewire:admin.clients.payment-methods-content
            :customer="$customer"
            :client-info="$clientInfo"
        />
    @endif
</div>
