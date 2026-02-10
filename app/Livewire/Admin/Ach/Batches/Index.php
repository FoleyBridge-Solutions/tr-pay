<?php

namespace App\Livewire\Admin\Ach\Batches;

use App\Models\Ach\AchBatch;
use App\Services\Ach\AchFileService;
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
    public string $statusFilter = '';

    public string $sortField = 'created_at';

    public string $sortDirection = 'desc';

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedStatusFilter(): void
    {
        $this->resetPage();
    }

    public function sortBy(string $field): void
    {
        if ($this->sortField === $field) {
            $this->sortDirection = $this->sortDirection === 'asc' ? 'desc' : 'asc';
        } else {
            $this->sortField = $field;
            $this->sortDirection = 'asc';
        }
    }

    public function generateFile(int $batchId): void
    {
        $batch = AchBatch::findOrFail($batchId);

        if ($batch->ach_file_id) {
            Flux::toast('Batch already has a file generated.', variant: 'danger');

            return;
        }

        if ($batch->entries()->count() === 0) {
            Flux::toast('Batch has no entries.', variant: 'danger');

            return;
        }

        try {
            $achService = app(AchFileService::class);
            $achFile = $achService->generateFile($batch);
            Flux::toast("NACHA file generated: {$achFile->filename}", variant: 'success');
        } catch (\Exception $e) {
            Flux::toast("Generation failed: {$e->getMessage()}", variant: 'danger');
        }
    }

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

        return view('livewire.admin.ach.batches.index', [
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
