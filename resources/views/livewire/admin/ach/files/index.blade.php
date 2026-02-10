<div>
    <div class="flex items-center justify-between mb-8">
        <div>
            <flux:heading size="xl">ACH Files</flux:heading>
            <flux:subheading>Manage NACHA files and their batches</flux:subheading>
        </div>
        <div class="flex gap-2">
            @if($pendingBatchesCount > 0)
                <flux:button 
                    wire:click="generateFileForAllPending"
                    wire:confirm="Generate a NACHA file for all {{ $pendingBatchesCount }} pending batch(es)?"
                    variant="primary"
                    icon="document-plus"
                >
                    Generate File ({{ $pendingBatchesCount }} pending)
                </flux:button>
            @endif
        </div>
    </div>

    {{-- Filters --}}
    <flux:card class="mb-6">
        <div class="flex flex-col md:flex-row gap-4 p-4">
            <div class="flex-1">
                <flux:input 
                    wire:model.live.debounce.300ms="search" 
                    placeholder="Search by filename..." 
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

    {{-- Files Table --}}
    <flux:card>
        @if($files->isEmpty())
            <div class="p-12 text-center">
                <flux:icon name="document-text" class="w-12 h-12 mx-auto text-zinc-400 mb-4" />
                <flux:heading size="lg">No ACH files found</flux:heading>
                <flux:text class="text-zinc-500 mb-4">
                    @if($pendingBatchesCount > 0)
                        You have {{ $pendingBatchesCount }} pending batch(es) ready to generate.
                    @else
                        Create batches and generate files to get started.
                    @endif
                </flux:text>
            </div>
        @else
            <flux:table>
                <flux:table.columns>
                    <flux:table.column class="w-8"></flux:table.column>
                    <flux:table.column sortable :sorted="$sortField === 'filename'" :direction="$sortDirection" wire:click="sortBy('filename')">Filename</flux:table.column>
                    <flux:table.column>Status</flux:table.column>
                    <flux:table.column>Batches</flux:table.column>
                    <flux:table.column>Entries</flux:table.column>
                    <flux:table.column>Total Debit</flux:table.column>
                    <flux:table.column sortable :sorted="$sortField === 'created_at'" :direction="$sortDirection" wire:click="sortBy('created_at')">Created</flux:table.column>
                    <flux:table.column></flux:table.column>
                </flux:table.columns>
                <flux:table.rows>
                    @foreach ($files as $file)
                        <tbody x-data="{ expanded: false }" wire:key="file-{{ $file->id }}">
                            {{-- Compact Row --}}
                            <flux:table.row class="cursor-pointer hover:bg-zinc-50 dark:hover:bg-zinc-800/50" @click="expanded = !expanded">
                                <flux:table.cell>
                                    <button type="button" class="p-1 rounded hover:bg-zinc-200 dark:hover:bg-zinc-700 transition-transform" :class="{ 'rotate-90': expanded }">
                                        <flux:icon name="chevron-right" class="w-4 h-4 text-zinc-400" />
                                    </button>
                                </flux:table.cell>
                                <flux:table.cell>
                                    <span class="font-mono text-sm">{{ $file->filename }}</span>
                                </flux:table.cell>
                                <flux:table.cell>
                                    @switch($file->status)
                                        @case('pending')
                                            <flux:badge color="amber" size="sm">Pending</flux:badge>
                                            @break
                                        @case('generated')
                                            <flux:badge color="blue" size="sm">Generated</flux:badge>
                                            @break
                                        @case('submitted')
                                            <flux:badge color="purple" size="sm">Submitted</flux:badge>
                                            @break
                                        @case('accepted')
                                            <flux:badge color="indigo" size="sm">Accepted</flux:badge>
                                            @break
                                        @case('processing')
                                            <flux:badge color="cyan" size="sm">Processing</flux:badge>
                                            @break
                                        @case('completed')
                                            <flux:badge color="green" size="sm">Completed</flux:badge>
                                            @break
                                        @case('rejected')
                                            <flux:badge color="red" size="sm">Rejected</flux:badge>
                                            @break
                                        @case('failed')
                                            <flux:badge color="red" size="sm">Failed</flux:badge>
                                            @break
                                        @default
                                            <flux:badge size="sm">{{ ucfirst($file->status) }}</flux:badge>
                                    @endswitch
                                </flux:table.cell>
                                <flux:table.cell>
                                    <div class="flex gap-1">
                                        @foreach($file->batches as $batch)
                                            @if($batch->sec_code === 'PPD')
                                                <flux:badge color="sky" size="sm">PPD</flux:badge>
                                            @elseif($batch->sec_code === 'CCD')
                                                <flux:badge color="violet" size="sm">CCD</flux:badge>
                                            @else
                                                <flux:badge size="sm">{{ $batch->sec_code }}</flux:badge>
                                            @endif
                                        @endforeach
                                    </div>
                                </flux:table.cell>
                                <flux:table.cell>{{ $file->entry_addenda_count }}</flux:table.cell>
                                <flux:table.cell>
                                    <span class="font-medium">${{ number_format($file->total_debit_dollars, 2) }}</span>
                                </flux:table.cell>
                                <flux:table.cell class="text-zinc-500">
                                    {{ $file->created_at->format('M j, Y g:i A') }}
                                </flux:table.cell>
                                <flux:table.cell>
                                    <div class="flex gap-1">
                                        @if(in_array($file->status, ['generated', 'failed']))
                                            <flux:button 
                                                wire:click.stop="uploadToKotapay({{ $file->id }})"
                                                wire:confirm="Upload this file to Kotapay for processing?"
                                                variant="primary" 
                                                size="sm" 
                                                icon="cloud-arrow-up"
                                            >
                                                Upload
                                            </flux:button>
                                        @endif
                                        <flux:button 
                                            wire:click.stop="downloadFile({{ $file->id }})"
                                            variant="ghost" 
                                            size="sm" 
                                            icon="arrow-down-tray"
                                        >
                                            Download
                                        </flux:button>
                                    </div>
                                </flux:table.cell>
                            </flux:table.row>

                            {{-- Expanded Details --}}
                            <tr x-show="expanded" x-collapse>
                                <td colspan="8" class="bg-zinc-50 dark:bg-zinc-800/50 px-4 py-4">
                                    <div class="ml-8">
                                        {{-- File Info --}}
                                        <div class="grid grid-cols-5 gap-6 mb-4 text-sm">
                                            <div>
                                                <div class="text-xs text-zinc-500 uppercase tracking-wide mb-1">File ID</div>
                                                <div class="font-mono">{{ $file->id }}</div>
                                            </div>
                                            <div>
                                                <div class="text-xs text-zinc-500 uppercase tracking-wide mb-1">Kotapay Ref</div>
                                                <div class="font-mono text-sm">{{ $file->kotapay_reference ?? '-' }}</div>
                                            </div>
                                            <div>
                                                <div class="text-xs text-zinc-500 uppercase tracking-wide mb-1">Total Credit</div>
                                                <div class="font-medium text-blue-600">${{ number_format($file->total_credit_dollars, 2) }}</div>
                                            </div>
                                            <div>
                                                <div class="text-xs text-zinc-500 uppercase tracking-wide mb-1">Generated</div>
                                                <div>{{ $file->generated_at?->format('M j, Y g:i A') ?? '-' }}</div>
                                            </div>
                                            <div>
                                                <div class="text-xs text-zinc-500 uppercase tracking-wide mb-1">Submitted</div>
                                                <div>{{ $file->submitted_at?->format('M j, Y g:i A') ?? '-' }}</div>
                                            </div>
                                        </div>

                                        {{-- Rejection Reason (if any) --}}
                                        @if($file->rejection_reason)
                                            <div class="mb-4 p-3 bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 rounded-lg">
                                                <div class="text-xs text-red-600 dark:text-red-400 uppercase tracking-wide mb-1">Rejection Reason</div>
                                                <div class="text-red-700 dark:text-red-300">{{ $file->rejection_reason }}</div>
                                            </div>
                                        @endif

                                        {{-- Action Buttons --}}
                                        @if(in_array($file->status, ['generated', 'failed']))
                                            <div class="mb-4 p-3 bg-amber-50 dark:bg-amber-900/20 border border-amber-200 dark:border-amber-800 rounded-lg flex items-center justify-between">
                                                <div>
                                                    <div class="font-medium text-amber-800 dark:text-amber-200">Ready to Submit</div>
                                                    <div class="text-sm text-amber-600 dark:text-amber-400">This file has been generated and is ready to upload to Kotapay.</div>
                                                </div>
                                                <div class="flex gap-2">
                                                    <flux:button 
                                                        wire:click="uploadToKotapay({{ $file->id }}, true)"
                                                        wire:confirm="Upload this file to Kotapay as a TEST submission? (No actual processing will occur)"
                                                        variant="ghost"
                                                        size="sm"
                                                        icon="beaker"
                                                    >
                                                        Test Upload
                                                    </flux:button>
                                                    <flux:button 
                                                        wire:click="uploadToKotapay({{ $file->id }})"
                                                        wire:confirm="Upload this file to Kotapay for LIVE processing? This will initiate real ACH transactions."
                                                        variant="primary"
                                                        size="sm"
                                                        icon="cloud-arrow-up"
                                                    >
                                                        Upload to Kotapay
                                                    </flux:button>
                                                </div>
                                            </div>
                                        @endif

                                        {{-- Batches --}}
                                        @if($file->batches->count() > 0)
                                            <div class="text-xs text-zinc-500 uppercase tracking-wide mb-2">Batches in this File</div>
                                            <div class="border border-zinc-200 dark:border-zinc-700 rounded-lg overflow-hidden">
                                                <table class="w-full text-sm">
                                                    <thead class="bg-zinc-100 dark:bg-zinc-700">
                                                        <tr>
                                                            <th class="px-3 py-2 text-left text-xs font-medium text-zinc-500 uppercase">Batch #</th>
                                                            <th class="px-3 py-2 text-left text-xs font-medium text-zinc-500 uppercase">SEC</th>
                                                            <th class="px-3 py-2 text-left text-xs font-medium text-zinc-500 uppercase">Description</th>
                                                            <th class="px-3 py-2 text-left text-xs font-medium text-zinc-500 uppercase">Entries</th>
                                                            <th class="px-3 py-2 text-left text-xs font-medium text-zinc-500 uppercase">Debit</th>
                                                            <th class="px-3 py-2 text-left text-xs font-medium text-zinc-500 uppercase">Effective</th>
                                                            <th class="px-3 py-2 text-right text-xs font-medium text-zinc-500 uppercase"></th>
                                                        </tr>
                                                    </thead>
                                                    <tbody class="divide-y divide-zinc-200 dark:divide-zinc-600 bg-white dark:bg-zinc-800">
                                                        @foreach($file->batches as $batch)
                                                            <tr>
                                                                <td class="px-3 py-2 font-mono text-xs">{{ $batch->batch_number }}</td>
                                                                <td class="px-3 py-2">
                                                                    @if($batch->sec_code === 'PPD')
                                                                        <flux:badge color="sky" size="sm">PPD</flux:badge>
                                                                    @elseif($batch->sec_code === 'CCD')
                                                                        <flux:badge color="violet" size="sm">CCD</flux:badge>
                                                                    @else
                                                                        <flux:badge size="sm">{{ $batch->sec_code }}</flux:badge>
                                                                    @endif
                                                                </td>
                                                                <td class="px-3 py-2 text-zinc-600 dark:text-zinc-400">{{ $batch->company_entry_description }}</td>
                                                                <td class="px-3 py-2">{{ $batch->entries_count }}</td>
                                                                <td class="px-3 py-2 font-medium">${{ number_format($batch->total_debit_dollars, 2) }}</td>
                                                                <td class="px-3 py-2 text-zinc-500">{{ $batch->effective_entry_date?->format('M j') }}</td>
                                                                <td class="px-3 py-2 text-right">
                                                                    <flux:button 
                                                                        href="{{ route('admin.ach.batches.show', $batch) }}" 
                                                                        variant="ghost" 
                                                                        size="xs"
                                                                    >
                                                                        View Entries
                                                                    </flux:button>
                                                                </td>
                                                            </tr>
                                                        @endforeach
                                                    </tbody>
                                                </table>
                                            </div>
                                        @endif
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                    @endforeach
                </flux:table.rows>
            </flux:table>

            <div class="p-4 border-t border-zinc-200 dark:border-zinc-700">
                {{ $files->links() }}
            </div>
        @endif
    </flux:card>

    {{-- Pending Batches Section --}}
    @php
        $pendingBatches = \App\Models\Ach\AchBatch::whereIn('status', ['pending', 'ready'])
            ->whereNull('ach_file_id')
            ->withCount('entries')
            ->orderBy('created_at', 'desc')
            ->get();
    @endphp

    @if($pendingBatches->count() > 0)
        <flux:card class="mt-6">
            <div class="p-4 border-b border-zinc-200 dark:border-zinc-700 flex items-center justify-between">
                <div>
                    <flux:heading size="lg">Pending Batches</flux:heading>
                    <flux:subheading>Batches ready to be included in a NACHA file</flux:subheading>
                </div>
                <flux:badge color="amber">{{ $pendingBatches->count() }} pending</flux:badge>
            </div>
            <flux:table>
                <flux:table.columns>
                    <flux:table.column>Batch #</flux:table.column>
                    <flux:table.column>Status</flux:table.column>
                    <flux:table.column>SEC Code</flux:table.column>
                    <flux:table.column>Entries</flux:table.column>
                    <flux:table.column>Debit Total</flux:table.column>
                    <flux:table.column>Effective Date</flux:table.column>
                    <flux:table.column>Created</flux:table.column>
                </flux:table.columns>
                <flux:table.rows>
                    @foreach($pendingBatches as $batch)
                        <flux:table.row wire:key="pending-{{ $batch->id }}">
                            <flux:table.cell>
                                <a href="{{ route('admin.ach.batches.show', $batch) }}" class="font-mono text-sm text-blue-600 hover:underline">
                                    {{ $batch->batch_number }}
                                </a>
                            </flux:table.cell>
                            <flux:table.cell>
                                @if($batch->status === 'pending')
                                    <flux:badge color="amber" size="sm">Pending</flux:badge>
                                @else
                                    <flux:badge color="blue" size="sm">Ready</flux:badge>
                                @endif
                            </flux:table.cell>
                            <flux:table.cell>
                                @if($batch->sec_code === 'PPD')
                                    <flux:badge color="sky" size="sm">PPD</flux:badge>
                                @elseif($batch->sec_code === 'CCD')
                                    <flux:badge color="violet" size="sm">CCD</flux:badge>
                                @else
                                    <flux:badge size="sm">{{ $batch->sec_code }}</flux:badge>
                                @endif
                            </flux:table.cell>
                            <flux:table.cell>{{ $batch->entries_count }}</flux:table.cell>
                            <flux:table.cell>
                                <span class="font-medium">${{ number_format($batch->total_debit_dollars, 2) }}</span>
                            </flux:table.cell>
                            <flux:table.cell class="text-zinc-500">{{ $batch->effective_entry_date?->format('M j, Y') }}</flux:table.cell>
                            <flux:table.cell class="text-zinc-500">{{ $batch->created_at->format('M j, Y g:i A') }}</flux:table.cell>
                        </flux:table.row>
                    @endforeach
                </flux:table.rows>
            </flux:table>
        </flux:card>
    @endif
</div>
