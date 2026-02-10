<?php

namespace App\Livewire\Admin\Ach\Batches;

use App\Models\Ach\AchBatch;
use App\Services\Ach\AchFileService;
use Flux\Flux;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Symfony\Component\HttpFoundation\StreamedResponse;

#[Layout('layouts.admin')]
class Show extends Component
{
    public AchBatch $batch;

    public function mount(AchBatch $batch): void
    {
        $this->batch = $batch->load(['entries', 'file']);
    }

    public function generateFile(): void
    {
        if ($this->batch->ach_file_id) {
            Flux::toast('Batch already has a file generated.', variant: 'danger');

            return;
        }

        if ($this->batch->entries()->count() === 0) {
            Flux::toast('Batch has no entries.', variant: 'danger');

            return;
        }

        try {
            $achService = app(AchFileService::class);
            $achFile = $achService->generateFile($this->batch);
            $this->batch->refresh();
            Flux::toast("NACHA file generated: {$achFile->filename}", variant: 'success');
        } catch (\Exception $e) {
            Flux::toast("Generation failed: {$e->getMessage()}", variant: 'danger');
        }
    }

    public function downloadFile(): StreamedResponse
    {
        if (! $this->batch->file) {
            abort(404, 'No file generated for this batch.');
        }

        $achService = app(AchFileService::class);
        $contents = $achService->getFileContents($this->batch->file);

        return response()->streamDownload(function () use ($contents) {
            echo $contents;
        }, $this->batch->file->filename, [
            'Content-Type' => 'text/plain',
        ]);
    }

    public function markAsSubmitted(): void
    {
        if (! $this->batch->file) {
            Flux::toast('No file generated for this batch.', variant: 'danger');

            return;
        }

        $this->batch->file->markAsSubmitted();
        $this->batch->update(['status' => AchBatch::STATUS_SUBMITTED]);
        $this->batch->refresh();

        Flux::toast('Batch marked as submitted to Kotapay.', variant: 'success');
    }

    public function render()
    {
        return view('livewire.admin.ach.batches.show');
    }
}
