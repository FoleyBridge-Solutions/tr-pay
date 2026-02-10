<div>
    <div class="flex items-center justify-between mb-8">
        <div>
            <flux:heading size="xl">ACH Batches</flux:heading>
            <flux:subheading>Manage ACH batches and generate NACHA files</flux:subheading>
        </div>
    </div>

    {{-- Filters --}}
    <flux:card class="mb-6">
        <div class="flex flex-col md:flex-row gap-4 p-4">
            <div class="flex-1">
                <flux:input 
                    wire:model.live.debounce.300ms="search" 
                    placeholder="Search by batch number..." 
                    icon="magnifying-glass"
                />
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

    {{-- Batches Table --}}
    <flux:card>
        @if($batches->isEmpty())
            <div class="p-12 text-center">
                <flux:icon name="building-library" class="w-12 h-12 mx-auto text-zinc-400 mb-4" />
                <flux:heading size="lg">No ACH batches found</flux:heading>
                <flux:text class="text-zinc-500 mb-4">Create batches using the command line</flux:text>
                <flux:text class="text-zinc-400 font-mono text-sm">php artisan ach:create-batch</flux:text>
            </div>
        @else
            <flux:table>
                <flux:table.columns>
                    <flux:table.column sortable :sorted="$sortField === 'batch_number'" :direction="$sortDirection" wire:click="sortBy('batch_number')">Batch #</flux:table.column>
                    <flux:table.column>Status</flux:table.column>
                    <flux:table.column>Entries</flux:table.column>
                    <flux:table.column>Debit Total</flux:table.column>
                    <flux:table.column sortable :sorted="$sortField === 'effective_entry_date'" :direction="$sortDirection" wire:click="sortBy('effective_entry_date')">Effective Date</flux:table.column>
                    <flux:table.column sortable :sorted="$sortField === 'created_at'" :direction="$sortDirection" wire:click="sortBy('created_at')">Created</flux:table.column>
                    <flux:table.column></flux:table.column>
                </flux:table.columns>
                <flux:table.rows>
                    @foreach ($batches as $batch)
                        <flux:table.row wire:key="batch-{{ $batch->id }}">
                            <flux:table.cell>
                                <a href="{{ route('admin.ach.batches.show', $batch) }}" class="font-mono text-sm text-blue-600 hover:underline">
                                    {{ $batch->batch_number }}
                                </a>
                            </flux:table.cell>
                            <flux:table.cell>
                                @switch($batch->status)
                                    @case('pending')
                                        <flux:badge color="amber" size="sm">Pending</flux:badge>
                                        @break
                                    @case('ready')
                                        <flux:badge color="blue" size="sm">Ready</flux:badge>
                                        @break
                                    @case('generated')
                                        <flux:badge color="green" size="sm">Generated</flux:badge>
                                        @break
                                    @case('submitted')
                                        <flux:badge color="purple" size="sm">Submitted</flux:badge>
                                        @break
                                    @case('accepted')
                                        <flux:badge color="indigo" size="sm">Accepted</flux:badge>
                                        @break
                                    @case('settled')
                                        <flux:badge color="green" size="sm">Settled</flux:badge>
                                        @break
                                    @case('cancelled')
                                        <flux:badge color="zinc" size="sm">Cancelled</flux:badge>
                                        @break
                                    @default
                                        <flux:badge size="sm">{{ ucfirst($batch->status) }}</flux:badge>
                                @endswitch
                            </flux:table.cell>
                            <flux:table.cell>
                                {{ $batch->entries_count }}
                            </flux:table.cell>
                            <flux:table.cell>
                                <span class="font-medium">${{ number_format($batch->total_debit_dollars, 2) }}</span>
                            </flux:table.cell>
                            <flux:table.cell class="text-zinc-500">
                                {{ $batch->effective_entry_date?->format('M j, Y') }}
                            </flux:table.cell>
                            <flux:table.cell class="text-zinc-500">
                                {{ $batch->created_at->format('M j, Y g:i A') }}
                            </flux:table.cell>
                            <flux:table.cell>
                                <div class="flex gap-1">
                                    <flux:button href="{{ route('admin.ach.batches.show', $batch) }}" variant="ghost" size="sm" icon="eye">
                                        View
                                    </flux:button>
                                    @if (in_array($batch->status, ['pending', 'ready']) && !$batch->ach_file_id && $batch->entries_count > 0)
                                        <flux:button 
                                            wire:click="generateFile({{ $batch->id }})"
                                            wire:confirm="Generate NACHA file for batch {{ $batch->batch_number }}?"
                                            variant="ghost" 
                                            size="sm" 
                                            icon="document-arrow-down"
                                            class="text-green-600 hover:text-green-700"
                                        >
                                            Generate
                                        </flux:button>
                                    @endif
                                </div>
                            </flux:table.cell>
                        </flux:table.row>
                    @endforeach
                </flux:table.rows>
            </flux:table>

            <div class="p-4 border-t border-zinc-200 dark:border-zinc-700">
                {{ $batches->links() }}
            </div>
        @endif
    </flux:card>
</div>
