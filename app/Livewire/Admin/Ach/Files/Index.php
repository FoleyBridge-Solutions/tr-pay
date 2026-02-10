<?php

namespace App\Livewire\Admin\Ach\Files;

use App\Models\Ach\AchBatch;
use App\Models\Ach\AchFile;
use App\Services\Ach\AchFileService;
use App\Services\Kotapay\KotapayApiService;
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

    /**
     * Generate a file for all pending batches.
     */
    public function generateFileForAllPending(): void
    {
        $batches = AchBatch::whereIn('status', [AchBatch::STATUS_PENDING, AchBatch::STATUS_READY])
            ->whereNull('ach_file_id')
            ->where('entry_count', '>', 0)
            ->get()
            ->all();

        if (empty($batches)) {
            Flux::toast('No pending batches with entries found.', variant: 'warning');

            return;
        }

        try {
            $achService = app(AchFileService::class);
            $achFile = $achService->generateFileForBatches($batches);
            Flux::toast("NACHA file generated: {$achFile->filename} with ".count($batches).' batch(es)', variant: 'success');
        } catch (\Exception $e) {
            Flux::toast("Generation failed: {$e->getMessage()}", variant: 'danger');
        }
    }

    /**
     * Download a file.
     */
    public function downloadFile(int $fileId): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        $achFile = AchFile::findOrFail($fileId);
        $achService = app(AchFileService::class);

        return response()->streamDownload(function () use ($achService, $achFile) {
            echo $achService->getFileContents($achFile);
        }, $achFile->filename, [
            'Content-Type' => 'text/plain',
        ]);
    }

    /**
     * Upload a file to Kotapay.
     */
    public function uploadToKotapay(int $fileId, bool $isTest = false): void
    {
        $achFile = AchFile::findOrFail($fileId);

        // Check if file is in a valid state to upload
        if (! in_array($achFile->status, [AchFile::STATUS_GENERATED, AchFile::STATUS_FAILED])) {
            Flux::toast("File cannot be uploaded - status is '{$achFile->status}'", variant: 'warning');

            return;
        }

        // Check if Kotapay API is enabled
        if (! config('kotapay.api.enabled')) {
            Flux::toast('Kotapay API is not enabled. Please configure API credentials in .env', variant: 'danger');

            return;
        }

        try {
            $kotapayService = app(KotapayApiService::class);
            $result = $kotapayService->uploadAchFile($achFile, $isTest);

            $message = $isTest
                ? "Test upload successful! Reference: {$result['ref_num']}"
                : "File uploaded to Kotapay. Reference: {$result['ref_num']}";

            if ($result['is_duplicate'] ?? false) {
                $message .= ' (Duplicate file detected)';
            }

            Flux::toast($message, variant: 'success');
        } catch (\Exception $e) {
            Flux::toast("Upload failed: {$e->getMessage()}", variant: 'danger');
        }
    }

    public function render()
    {
        // Get files with their batches
        $files = AchFile::query()
            ->withCount('batches')
            ->with(['batches' => function ($query) {
                $query->withCount('entries');
            }])
            ->when($this->search, function ($query) {
                $query->where('filename', 'like', "%{$this->search}%");
            })
            ->when($this->statusFilter, function ($query) {
                $query->where('status', $this->statusFilter);
            })
            ->orderBy($this->sortField, $this->sortDirection)
            ->paginate(15);

        // Get pending batches count for the "Generate File" button
        $pendingBatchesCount = AchBatch::whereIn('status', [AchBatch::STATUS_PENDING, AchBatch::STATUS_READY])
            ->whereNull('ach_file_id')
            ->where('entry_count', '>', 0)
            ->count();

        return view('livewire.admin.ach.files.index', [
            'files' => $files,
            'pendingBatchesCount' => $pendingBatchesCount,
            'statuses' => [
                AchFile::STATUS_PENDING => 'Pending',
                AchFile::STATUS_GENERATED => 'Generated',
                AchFile::STATUS_SUBMITTED => 'Submitted',
                AchFile::STATUS_ACCEPTED => 'Accepted',
                AchFile::STATUS_REJECTED => 'Rejected',
                AchFile::STATUS_PROCESSING => 'Processing',
                AchFile::STATUS_COMPLETED => 'Completed',
                AchFile::STATUS_FAILED => 'Failed',
            ],
        ]);
    }
}
