<div>
    {{-- Filters --}}
    <flux:card class="mb-6">
        <div class="flex flex-col md:flex-row gap-4 p-4">
            <div class="flex-1">
                <flux:input 
                    wire:model.live.debounce.300ms="search" 
                    placeholder="Search by client name, ID, or email..." 
                    icon="magnifying-glass"
                />
            </div>
            <div class="w-full md:w-48">
                <flux:select wire:model.live="status" multiple placeholder="All Statuses">
                    <flux:select.option value="pending">Pending</flux:select.option>
                    <flux:select.option value="paid">Paid</flux:select.option>
                    <flux:select.option value="expired">Expired</flux:select.option>
                    <flux:select.option value="revoked">Revoked</flux:select.option>
                </flux:select>
            </div>
        </div>
    </flux:card>

    {{-- Payment Requests Table --}}
    <flux:card>
        @if($paymentRequests->isEmpty())
            <div class="p-12 text-center">
                <flux:icon name="envelope" class="w-12 h-12 mx-auto text-zinc-400 mb-4" />
                <flux:heading size="lg">No payment requests found</flux:heading>
                <flux:text class="text-zinc-500">Try adjusting your search or filters</flux:text>
            </div>
        @else
            <flux:table>
                <flux:table.columns>
                    <flux:table.column>Client</flux:table.column>
                    <flux:table.column>Email</flux:table.column>
                    <flux:table.column>Amount</flux:table.column>
                    <flux:table.column>Status</flux:table.column>
                    <flux:table.column>Sent By</flux:table.column>
                    <flux:table.column>Sent</flux:table.column>
                    <flux:table.column>Paid</flux:table.column>
                    <flux:table.column></flux:table.column>
                </flux:table.columns>
                <flux:table.rows>
                    @foreach($paymentRequests as $request)
                        <flux:table.row wire:key="request-{{ $request->id }}">
                            <flux:table.cell>
                                <a href="{{ route('admin.clients.show', $request->client_id) }}" class="text-blue-600 hover:text-blue-800 dark:text-blue-400 dark:hover:text-blue-300 hover:underline">
                                    <span class="font-medium">{{ Str::limit($request->client_name, 25) }}</span>
                                    <span class="text-zinc-500 text-sm block">{{ $request->client_id }}</span>
                                </a>
                            </flux:table.cell>
                            <flux:table.cell>
                                <span class="text-sm">{{ $request->email }}</span>
                            </flux:table.cell>
                            <flux:table.cell>
                                <span class="font-medium">${{ number_format($request->amount, 2) }}</span>
                            </flux:table.cell>
                            <flux:table.cell>
                                @if($request->status === 'paid')
                                    <flux:badge color="green" size="sm">Paid</flux:badge>
                                @elseif($request->status === 'pending')
                                    <flux:badge color="amber" size="sm">Pending</flux:badge>
                                @elseif($request->status === 'expired')
                                    <flux:badge color="zinc" size="sm">Expired</flux:badge>
                                @elseif($request->status === 'revoked')
                                    <flux:badge color="red" size="sm">Revoked</flux:badge>
                                @endif
                            </flux:table.cell>
                            <flux:table.cell>
                                <span class="text-sm">{{ $request->sender?->name ?? '-' }}</span>
                            </flux:table.cell>
                            <flux:table.cell class="text-zinc-500">
                                <local-time datetime="{{ $request->created_at->toIso8601String() }}"></local-time>
                            </flux:table.cell>
                            <flux:table.cell class="text-zinc-500">
                                @if($request->paid_at)
                                    <local-time datetime="{{ $request->paid_at->toIso8601String() }}"></local-time>
                                @else
                                    <span class="text-zinc-400">-</span>
                                @endif
                            </flux:table.cell>
                            <flux:table.cell>
                                <div class="flex items-center gap-1">
                                    @if($request->status === 'paid' && $request->payment_id)
                                        <flux:button href="{{ route('admin.payments', ['q' => $request->payment?->transaction_id]) }}" variant="ghost" size="sm" icon="eye">
                                            Payment
                                        </flux:button>
                                    @endif
                                    @if($request->status === 'pending')
                                        <flux:button wire:click="resend({{ $request->id }})" wire:confirm="Resend this payment request email?" variant="ghost" size="sm" icon="envelope">
                                            Resend
                                        </flux:button>
                                        <flux:button wire:click="revoke({{ $request->id }})" wire:confirm="Are you sure you want to revoke this payment request? The link will no longer work." variant="ghost" size="sm" icon="x-mark" class="text-red-600 hover:text-red-700">
                                            Revoke
                                        </flux:button>
                                    @endif
                                </div>
                            </flux:table.cell>
                        </flux:table.row>
                    @endforeach
                </flux:table.rows>
            </flux:table>

            <div class="p-4 border-t border-zinc-200 dark:border-zinc-700">
                {{ $paymentRequests->links() }}
            </div>
        @endif
    </flux:card>
</div>
