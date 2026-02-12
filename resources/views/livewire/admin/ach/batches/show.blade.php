<div>
    <div class="flex items-center justify-between mb-8">
        <div>
            <div class="flex items-center gap-3 mb-1">
                <flux:heading size="xl">Batch {{ $batch->batch_number }}</flux:heading>
                @switch($batch->status)
                    @case('pending')
                        <flux:badge color="amber">Pending</flux:badge>
                        @break
                    @case('ready')
                        <flux:badge color="blue">Ready</flux:badge>
                        @break
                    @case('generated')
                        <flux:badge color="green">Generated</flux:badge>
                        @break
                    @case('submitted')
                        <flux:badge color="purple">Submitted</flux:badge>
                        @break
                    @case('accepted')
                        <flux:badge color="indigo">Accepted</flux:badge>
                        @break
                    @case('settled')
                        <flux:badge color="green">Settled</flux:badge>
                        @break
                    @default
                        <flux:badge>{{ ucfirst($batch->status) }}</flux:badge>
                @endswitch
            </div>
            <flux:subheading>{{ $batch->company_entry_description }} - SEC Code: {{ $batch->sec_code }}</flux:subheading>
        </div>
        <flux:button href="{{ route('admin.ach.batches.index') }}" variant="ghost" icon="arrow-left">
            Back to Batches
        </flux:button>
    </div>

    {{-- Batch Summary --}}
    <flux:card class="mb-6">
        <div class="p-6">
            <div class="grid grid-cols-2 md:grid-cols-4 gap-6">
                <div>
                    <flux:text class="text-zinc-500 text-sm">Entries</flux:text>
                    <flux:heading size="lg">{{ $batch->entry_count }}</flux:heading>
                </div>
                <div>
                    <flux:text class="text-zinc-500 text-sm">Total Debit</flux:text>
                    <flux:heading size="lg">${{ number_format($batch->total_debit_dollars, 2) }}</flux:heading>
                </div>
                <div>
                    <flux:text class="text-zinc-500 text-sm">Effective Date</flux:text>
                    <flux:heading size="lg">
                        @if($batch->effective_entry_date)
                            <local-time datetime="{{ $batch->effective_entry_date->toIso8601String() }}" format="date"></local-time>
                        @else
                            -
                        @endif
                    </flux:heading>
                </div>
                <div>
                    <flux:text class="text-zinc-500 text-sm">Created</flux:text>
                    <flux:heading size="lg"><local-time datetime="{{ $batch->created_at->toIso8601String() }}" format="date"></local-time></flux:heading>
                </div>
            </div>
        </div>
    </flux:card>

    {{-- Entries Table --}}
    <flux:card>
        <div class="p-4 border-b border-zinc-200 dark:border-zinc-700">
            <flux:heading size="lg">Entries</flux:heading>
        </div>
        
        @if($batch->entries->isEmpty())
            <div class="p-12 text-center">
                <flux:icon name="document-text" class="w-12 h-12 mx-auto text-zinc-400 mb-4" />
                <flux:heading size="lg">No entries in this batch</flux:heading>
                <flux:text class="text-zinc-500">Add entries using the command line</flux:text>
            </div>
        @else
            <flux:table>
                <flux:table.columns>
                    <flux:table.column>Name</flux:table.column>
                    <flux:table.column>Account</flux:table.column>
                    <flux:table.column>Type</flux:table.column>
                    <flux:table.column>Amount</flux:table.column>
                    <flux:table.column>Status</flux:table.column>
                    <flux:table.column>Trace #</flux:table.column>
                </flux:table.columns>
                <flux:table.rows>
                    @foreach ($batch->entries as $entry)
                        <flux:table.row wire:key="entry-{{ $entry->id }}">
                            <flux:table.cell>
                                <span class="font-medium">{{ $entry->individual_name }}</span>
                            </flux:table.cell>
                            <flux:table.cell class="text-zinc-500">
                                ****{{ $entry->routing_number_last_four }} / ****{{ $entry->account_number_last_four }}
                            </flux:table.cell>
                            <flux:table.cell>
                                {{ ucfirst($entry->account_type) }}
                            </flux:table.cell>
                            <flux:table.cell>
                                <span class="font-medium">${{ number_format($entry->amount / 100, 2) }}</span>
                            </flux:table.cell>
                            <flux:table.cell>
                                @switch($entry->status)
                                    @case('pending')
                                        <flux:badge color="amber" size="sm">Pending</flux:badge>
                                        @break
                                    @case('submitted')
                                        <flux:badge color="purple" size="sm">Submitted</flux:badge>
                                        @break
                                    @case('settled')
                                        <flux:badge color="green" size="sm">Settled</flux:badge>
                                        @break
                                    @case('returned')
                                        <flux:badge color="red" size="sm">Returned</flux:badge>
                                        @break
                                    @default
                                        <flux:badge size="sm">{{ ucfirst($entry->status) }}</flux:badge>
                                @endswitch
                            </flux:table.cell>
                            <flux:table.cell>
                                <span class="font-mono text-sm text-zinc-500">{{ $entry->trace_number }}</span>
                            </flux:table.cell>
                        </flux:table.row>
                    @endforeach
                </flux:table.rows>
            </flux:table>
        @endif
    </flux:card>
</div>
