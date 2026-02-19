<?php

// app/Livewire/Admin/Ach/Returns/ReturnsTable.php

namespace App\Livewire\Admin\Ach\Returns;

use App\Models\Ach\AchReturn;
use Flux\Flux;
use Livewire\Attributes\Lazy;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Lazy-loaded ACH returns and NOCs table.
 *
 * Displays filterable, paginated list of ACH returns and notifications of change.
 */
#[Lazy]
class ReturnsTable extends Component
{
    use WithPagination;

    #[Url(as: 'q')]
    public string $search = '';

    #[Url]
    public string $typeFilter = '';

    #[Url]
    public string $statusFilter = '';

    /**
     * Reset pagination when the search query changes.
     */
    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    /**
     * Reset pagination when the type filter changes.
     */
    public function updatedTypeFilter(): void
    {
        $this->resetPage();
    }

    /**
     * Reset pagination when the status filter changes.
     */
    public function updatedStatusFilter(): void
    {
        $this->resetPage();
    }

    /**
     * Mark an ACH return as reviewed.
     *
     * @param  int  $returnId  The ACH return ID to mark as reviewed
     */
    public function markAsReviewed(int $returnId): void
    {
        $return = AchReturn::findOrFail($returnId);
        $return->markAsReviewed(auth()->id());
        Flux::toast("Return {$return->return_code} marked as reviewed.", variant: 'success');
    }

    /**
     * Skeleton placeholder shown while component loads.
     */
    public function placeholder(): string
    {
        return <<<'HTML'
        <div>
            {{-- Filter skeleton --}}
            <flux:card class="mb-6">
                <div class="flex flex-col md:flex-row gap-4 p-4">
                    <flux:skeleton.group animate="shimmer">
                        <div class="flex-1">
                            <flux:skeleton class="h-10 w-full rounded" />
                        </div>
                        <div class="w-full md:w-48">
                            <flux:skeleton class="h-10 w-full rounded" />
                        </div>
                        <div class="w-full md:w-48">
                            <flux:skeleton class="h-10 w-full rounded" />
                        </div>
                    </flux:skeleton.group>
                </div>
            </flux:card>

            {{-- Table skeleton --}}
            <flux:card>
                <flux:skeleton.group animate="shimmer">
                    <div class="divide-y divide-zinc-200 dark:divide-zinc-700">
                        {{-- Header row --}}
                        <div class="px-4 py-3 flex items-center gap-4">
                            <flux:skeleton.line class="w-20" />
                            <flux:skeleton.line class="w-14" />
                            <flux:skeleton.line class="w-14" />
                            <flux:skeleton.line class="w-32" />
                            <flux:skeleton.line class="w-24" />
                            <flux:skeleton.line class="w-16" />
                            <flux:skeleton.line class="w-20" />
                            <flux:skeleton.line class="w-24" />
                        </div>
                        {{-- Data rows --}}
                        @for ($i = 0; $i < 5; $i++)
                            <div class="px-4 py-3 flex items-center gap-4">
                                <flux:skeleton.line class="w-20" />
                                <flux:skeleton class="h-5 w-14 rounded-full" />
                                <flux:skeleton.line class="w-14" />
                                <flux:skeleton.line class="w-32" />
                                <flux:skeleton.line class="w-24" />
                                <flux:skeleton.line class="w-16" />
                                <flux:skeleton class="h-5 w-20 rounded-full" />
                                <flux:skeleton class="h-7 w-24 rounded" />
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
        $returns = AchReturn::query()
            ->with(['entry', 'entry.batch', 'file'])
            ->when($this->search, function ($query) {
                $query->where('return_code', 'like', "%{$this->search}%")
                    ->orWhere('original_trace_number', 'like', "%{$this->search}%");
            })
            ->when($this->typeFilter, function ($query) {
                $query->where('return_type', $this->typeFilter);
            })
            ->when($this->statusFilter, function ($query) {
                $query->where('status', $this->statusFilter);
            })
            ->orderBy('created_at', 'desc')
            ->paginate(15);

        return view('livewire.admin.ach.returns.returns-table', [
            'returns' => $returns,
            'returnTypes' => [
                'return' => 'Return',
                'noc' => 'NOC (Notification of Change)',
            ],
            'statuses' => [
                AchReturn::STATUS_RECEIVED => 'Received',
                AchReturn::STATUS_PROCESSING => 'Processing',
                AchReturn::STATUS_APPLIED => 'Applied',
                AchReturn::STATUS_REVIEWED => 'Reviewed',
                AchReturn::STATUS_RESOLVED => 'Resolved',
            ],
        ]);
    }
}
