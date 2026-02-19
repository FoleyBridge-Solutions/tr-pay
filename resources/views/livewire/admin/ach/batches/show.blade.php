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

    {{-- Batch details (lazy loaded) --}}
    <livewire:admin.ach.batches.batch-details :batch="$batch" />
</div>
