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
        <flux:button href="{{ route('admin.ach.files.index') }}" variant="ghost" icon="arrow-left">
            Back to Files
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
                    <flux:heading size="lg">{{ $batch->effective_entry_date?->format('M j, Y') }}</flux:heading>
                </div>
                <div>
                    <flux:text class="text-zinc-500 text-sm">Created</flux:text>
                    <flux:heading size="lg">{{ $batch->created_at->format('M j, Y') }}</flux:heading>
                </div>
            </div>

            {{-- Actions --}}
            <div class="mt-6 pt-6 border-t border-zinc-200 dark:border-zinc-700 flex flex-wrap gap-3">
                @if (in_array($batch->status, ['pending', 'ready']) && !$batch->ach_file_id && $batch->entry_count > 0)
                    <flux:button 
                        wire:click="generateFile"
                        wire:confirm="Generate NACHA file for this batch?"
                        variant="primary"
                        icon="document-arrow-down"
                    >
                        Generate NACHA File
                    </flux:button>
                @endif

                @if ($batch->file)
                    <flux:button 
                        wire:click="downloadFile"
                        variant="filled"
                        icon="arrow-down-tray"
                    >
                        Download File
                    </flux:button>

                    @if ($batch->status === 'generated')
                        <flux:button 
                            wire:click="markAsSubmitted"
                            wire:confirm="Mark this batch as submitted to Kotapay?"
                            variant="filled"
                            icon="paper-airplane"
                        >
                            Mark as Submitted
                        </flux:button>
                    @endif
                @endif
            </div>
        </div>
    </flux:card>

    {{-- File Info (if generated) --}}
    @if ($batch->file)
        <flux:card class="mb-6">
            <div class="p-6">
                <flux:heading size="lg" class="mb-4">Generated File</flux:heading>
                <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
                    <div>
                        <flux:text class="text-zinc-500 text-sm">Filename</flux:text>
                        <flux:text class="font-mono text-sm">{{ $batch->file->filename }}</flux:text>
                    </div>
                    <div>
                        <flux:text class="text-zinc-500 text-sm">Generated</flux:text>
                        <flux:text>{{ $batch->file->generated_at?->format('M j, Y g:i A') }}</flux:text>
                    </div>
                    <div>
                        <flux:text class="text-zinc-500 text-sm">File Status</flux:text>
                        <flux:text>{{ ucfirst($batch->file->status) }}</flux:text>
                    </div>
                    @if ($batch->file->submitted_at)
                        <div>
                            <flux:text class="text-zinc-500 text-sm">Submitted</flux:text>
                            <flux:text>{{ $batch->file->submitted_at->format('M j, Y g:i A') }}</flux:text>
                        </div>
                    @endif
                </div>
            </div>
        </flux:card>
    @endif

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
