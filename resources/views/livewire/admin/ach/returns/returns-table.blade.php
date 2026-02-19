<div>
    {{-- Filters --}}
    <flux:card class="mb-6">
        <div class="flex flex-col md:flex-row gap-4 p-4">
            <div class="flex-1">
                <flux:input 
                    wire:model.live.debounce.300ms="search" 
                    placeholder="Search by code or trace number..." 
                    icon="magnifying-glass"
                />
            </div>
            <div class="w-full md:w-48">
                <flux:select wire:model.live="typeFilter">
                    <option value="">All Types</option>
                    @foreach ($returnTypes as $value => $label)
                        <option value="{{ $value }}">{{ $label }}</option>
                    @endforeach
                </flux:select>
            </div>
            <div class="w-full md:w-48">
                <flux:select wire:model.live="statusFilter">
                    <option value="">All Statuses</option>
                    @foreach ($statuses as $value => $label)
                        <option value="{{ $value }}">{{ $label }}</option>
                    @endforeach
                </flux:select>
            </div>
        </div>
    </flux:card>

    {{-- Returns Table --}}
    <flux:card>
        @if($returns->isEmpty())
            <div class="p-12 text-center">
                <flux:icon name="arrow-uturn-left" class="w-12 h-12 mx-auto text-zinc-400 mb-4" />
                <flux:heading size="lg">No returns or NOCs found</flux:heading>
                <flux:text class="text-zinc-500">Returns will appear here when received from Kotapay</flux:text>
            </div>
        @else
            <flux:table>
                <flux:table.columns>
                    <flux:table.column>Date</flux:table.column>
                    <flux:table.column>Type</flux:table.column>
                    <flux:table.column>Code</flux:table.column>
                    <flux:table.column>Description</flux:table.column>
                    <flux:table.column>Name</flux:table.column>
                    <flux:table.column>Amount</flux:table.column>
                    <flux:table.column>Status</flux:table.column>
                    <flux:table.column></flux:table.column>
                </flux:table.columns>
                <flux:table.rows>
                    @foreach ($returns as $return)
                        <flux:table.row wire:key="return-{{ $return->id }}">
                            <flux:table.cell class="text-zinc-500">
                                @if($return->return_date)
                                    <local-time datetime="{{ $return->return_date->toIso8601String() }}" format="date"></local-time>
                                @else
                                    -
                                @endif
                            </flux:table.cell>
                            <flux:table.cell>
                                @if($return->isNoc())
                                    <flux:badge color="blue" size="sm">NOC</flux:badge>
                                @else
                                    <flux:badge color="red" size="sm">Return</flux:badge>
                                @endif
                            </flux:table.cell>
                            <flux:table.cell>
                                <span class="font-mono font-medium">{{ $return->return_code }}</span>
                            </flux:table.cell>
                            <flux:table.cell>
                                <span class="text-zinc-500" title="{{ $return->return_code_description }}">
                                    {{ Str::limit($return->return_code_description, 30) }}
                                </span>
                            </flux:table.cell>
                            <flux:table.cell>
                                {{ $return->individual_name }}
                            </flux:table.cell>
                            <flux:table.cell>
                                @if ($return->original_amount)
                                    <span class="font-medium">${{ number_format($return->original_amount_dollars, 2) }}</span>
                                @else
                                    <span class="text-zinc-400">-</span>
                                @endif
                            </flux:table.cell>
                            <flux:table.cell>
                                @switch($return->status)
                                    @case('received')
                                        <flux:badge color="amber" size="sm">Received</flux:badge>
                                        @break
                                    @case('processing')
                                        <flux:badge color="blue" size="sm">Processing</flux:badge>
                                        @break
                                    @case('applied')
                                        <flux:badge color="green" size="sm">Applied</flux:badge>
                                        @break
                                    @case('reviewed')
                                        <flux:badge color="purple" size="sm">Reviewed</flux:badge>
                                        @break
                                    @case('resolved')
                                        <flux:badge color="green" size="sm">Resolved</flux:badge>
                                        @break
                                    @default
                                        <flux:badge size="sm">{{ ucfirst($return->status) }}</flux:badge>
                                @endswitch
                            </flux:table.cell>
                            <flux:table.cell>
                                @if (in_array($return->status, ['received', 'processing']))
                                    <flux:button 
                                        wire:click="markAsReviewed({{ $return->id }})"
                                        variant="ghost" 
                                        size="sm" 
                                        icon="check"
                                    >
                                        Mark Reviewed
                                    </flux:button>
                                @endif
                            </flux:table.cell>
                        </flux:table.row>
                    @endforeach
                </flux:table.rows>
            </flux:table>

            <div class="p-4 border-t border-zinc-200 dark:border-zinc-700">
                {{ $returns->links() }}
            </div>
        @endif
    </flux:card>
</div>
