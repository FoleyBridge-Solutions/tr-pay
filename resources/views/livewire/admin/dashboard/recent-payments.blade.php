<div wire:poll.30s>
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
