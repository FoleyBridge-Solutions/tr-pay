<?php

// app/Livewire/Admin/Ach/Batches/BatchDetails.php

namespace App\Livewire\Admin\Ach\Batches;

use App\Models\Ach\AchBatch;
use Livewire\Attributes\Lazy;
use Livewire\Component;

/**
 * Lazy-loaded batch details component.
 *
 * Displays summary cards and entries table for an ACH batch.
 */
#[Lazy]
class BatchDetails extends Component
{
    public AchBatch $batch;

    /**
     * Mount the component with a batch model.
     */
    public function mount(AchBatch $batch): void
    {
        $this->batch = $batch->load(['entries']);
    }

    /**
     * Skeleton placeholder shown while component loads.
     */
    public function placeholder(): string
    {
        return <<<'HTML'
        <div>
            {{-- Summary card skeleton --}}
            <flux:card class="mb-6">
                <div class="p-6">
                    <flux:skeleton.group animate="shimmer">
                        <div class="grid grid-cols-2 md:grid-cols-4 gap-6">
                            @for ($i = 0; $i < 4; $i++)
                                <div class="space-y-2">
                                    <flux:skeleton.line class="w-20" />
                                    <flux:skeleton class="h-7 w-24 rounded" />
                                </div>
                            @endfor
                        </div>
                    </flux:skeleton.group>
                </div>
            </flux:card>

            {{-- Entries table skeleton --}}
            <flux:card>
                <div class="p-4 border-b border-zinc-200 dark:border-zinc-700">
                    <flux:skeleton class="h-6 w-24 rounded" />
                </div>
                <flux:skeleton.group animate="shimmer">
                    <div class="divide-y divide-zinc-200 dark:divide-zinc-700">
                        {{-- Header row --}}
                        <div class="px-4 py-3 flex items-center gap-6">
                            <flux:skeleton.line class="w-24" />
                            <flux:skeleton.line class="w-32" />
                            <flux:skeleton.line class="w-16" />
                            <flux:skeleton.line class="w-20" />
                            <flux:skeleton.line class="w-16" />
                            <flux:skeleton.line class="w-28" />
                        </div>
                        {{-- Data rows --}}
                        @for ($i = 0; $i < 5; $i++)
                            <div class="px-4 py-3 flex items-center gap-6">
                                <flux:skeleton.line class="w-24" />
                                <flux:skeleton.line class="w-32" />
                                <flux:skeleton.line class="w-16" />
                                <flux:skeleton.line class="w-20" />
                                <flux:skeleton class="h-5 w-16 rounded-full" />
                                <flux:skeleton.line class="w-28" />
                            </div>
                        @endfor
                    </div>
                </flux:skeleton.group>
            </flux:card>
        </div>
        HTML;
    }

    public function render()
    {
        return view('livewire.admin.ach.batches.batch-details');
    }
}
