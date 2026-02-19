<div wire:poll.30s>
    <div class="flex items-center justify-between mb-4">
        <flux:heading size="lg">Recent Payment Plans</flux:heading>
        <flux:button href="{{ route('admin.payment-plans') }}" variant="ghost" size="sm">
            View All
        </flux:button>
    </div>

    <flux:card>
        @if($recentPlans->isEmpty())
            <div class="p-6 text-center">
                <flux:text class="text-zinc-500">No payment plans yet</flux:text>
            </div>
        @else
            <flux:table>
                <flux:table.columns>
                    <flux:table.column>Plan</flux:table.column>
                    <flux:table.column>Status</flux:table.column>
                    <flux:table.column>Progress</flux:table.column>
                </flux:table.columns>
                <flux:table.rows>
                    @foreach($recentPlans as $plan)
                        <flux:table.row>
                            <flux:table.cell>
                                <div>
                                    <span class="font-medium">${{ number_format($plan->total_amount, 2) }}</span>
                                    <span class="text-zinc-500 text-sm block">{{ $plan->duration_months }} months</span>
                                </div>
                            </flux:table.cell>
                            <flux:table.cell>
                                @if($plan->status === 'active')
                                    <flux:badge color="green" size="sm">Active</flux:badge>
                                @elseif($plan->status === 'completed')
                                    <flux:badge color="blue" size="sm">Completed</flux:badge>
                                @elseif($plan->status === 'past_due')
                                    <flux:badge color="amber" size="sm">Past Due</flux:badge>
                                @elseif($plan->status === 'cancelled')
                                    <flux:badge color="zinc" size="sm">Cancelled</flux:badge>
                                @else
                                    <flux:badge color="red" size="sm">{{ ucfirst($plan->status) }}</flux:badge>
                                @endif
                            </flux:table.cell>
                            <flux:table.cell class="text-zinc-500">
                                {{ $plan->payments_completed }}/{{ $plan->duration_months }}
                            </flux:table.cell>
                        </flux:table.row>
                    @endforeach
                </flux:table.rows>
            </flux:table>
        @endif
    </flux:card>
</div>
