<?php

// app/Livewire/Admin/Ach/Batches/BatchesTable.php

namespace App\Livewire\Admin\Ach\Batches;

use App\Models\Ach\AchBatch;
use Livewire\Attributes\Lazy;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Lazy-loaded ACH batches table with filters, sorting, and pagination.
 */
#[Lazy]
class BatchesTable extends Component
{
    use WithPagination;

    #[Url(as: 'q')]
    public string $search = '';

    #[Url]
    public string $statusFilter = '';

    public string $sortField = 'created_at';

    public string $sortDirection = 'desc';

    /**
     * Reset pagination when search changes.
     */
    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    /**
     * Reset pagination when status filter changes.
     */
    public function updatedStatusFilter(): void
    {
        $this->resetPage();
    }

    /**
     * Sort by the given field, toggling direction if already active.
     */
    public function sortBy(string $field): void
    {
        if ($this->sortField === $field) {
            $this->sortDirection = $this->sortDirection === 'asc' ? 'desc' : 'asc';
        } else {
            $this->sortField = $field;
            $this->sortDirection = 'asc';
        }
    }

    /**
     * Skeleton placeholder shown while component loads.
     */
    public function placeholder(): string
    {
        return <<<'HTML'
        <div>
            <flux:card class="mb-6">
                <div class="flex flex-col md:flex-row gap-4 p-4">
                    <div class="flex-1">
                        <flux:skeleton class="h-10 w-full rounded" />
                    </div>
                    <div class="w-full md:w-48">
                        <flux:skeleton class="h-10 w-full rounded" />
                    </div>
                </div>
            </flux:card>
            <flux:card>
                <flux:skeleton.group animate="shimmer">
                    <div class="divide-y divide-zinc-200 dark:divide-zinc-700">
                        <div class="px-4 py-3 flex items-center gap-4">
                            <flux:skeleton.line class="w-24" />
                            <flux:skeleton.line class="w-16" />
                            <flux:skeleton.line class="w-12" />
                            <flux:skeleton.line class="w-20" />
                            <flux:skeleton.line class="w-24" />
                            <flux:skeleton.line class="w-24" />
                            <flux:skeleton.line class="w-16" />
                        </div>
                        @for ($i = 0; $i < 5; $i++)
                            <div class="px-4 py-3 flex items-center gap-4">
                                <flux:skeleton.line class="w-24" />
                                <flux:skeleton class="h-5 w-16 rounded-full" />
                                <flux:skeleton.line class="w-12" />
                                <flux:skeleton.line class="w-20" />
                                <flux:skeleton.line class="w-24" />
                                <flux:skeleton.line class="w-24" />
                                <flux:skeleton class="h-8 w-16 rounded" />
                            </div>
                        @endfor
                    </div>
                </flux:skeleton.group>
            </flux:card>
        </div>
        HTML;
    }

    /**
     * Render the component.
     */
    public function render()
    {
        $batches = AchBatch::query()
            ->withCount('entries')
            ->when($this->search, function ($query) {
                $query->where('batch_number', 'like', "%{$this->search}%");
            })
            ->when($this->statusFilter, function ($query) {
                $query->where('status', $this->statusFilter);
            })
            ->orderBy($this->sortField, $this->sortDirection)
            ->paginate(15);

        return view('livewire.admin.ach.batches.batches-table', [
            'batches' => $batches,
            'statuses' => [
                AchBatch::STATUS_PENDING => 'Pending',
                AchBatch::STATUS_READY => 'Ready',
                AchBatch::STATUS_GENERATED => 'Generated',
                AchBatch::STATUS_SUBMITTED => 'Submitted',
                AchBatch::STATUS_ACCEPTED => 'Accepted',
                AchBatch::STATUS_SETTLED => 'Settled',
                AchBatch::STATUS_CANCELLED => 'Cancelled',
            ],
        ]);
    }
}
