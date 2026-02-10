<?php

namespace App\Livewire\Admin\Ach\Returns;

use App\Models\Ach\AchReturn;
use Flux\Flux;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('layouts.admin')]
class Index extends Component
{
    use WithPagination;

    #[Url(as: 'q')]
    public string $search = '';

    #[Url]
    public string $typeFilter = '';

    #[Url]
    public string $statusFilter = '';

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedTypeFilter(): void
    {
        $this->resetPage();
    }

    public function updatedStatusFilter(): void
    {
        $this->resetPage();
    }

    public function markAsReviewed(int $returnId): void
    {
        $return = AchReturn::findOrFail($returnId);
        $return->markAsReviewed(auth()->id());
        Flux::toast("Return {$return->return_code} marked as reviewed.", variant: 'success');
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

        return view('livewire.admin.ach.returns.index', [
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
