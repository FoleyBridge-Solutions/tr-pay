<?php

namespace App\Livewire\Admin\FileUpload;

use Flux\Flux;
use Illuminate\Support\Facades\Storage;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Validate;
use Livewire\Component;
use Livewire\WithFileUploads;

/**
 * File Upload Component
 *
 * Handles general file uploads to the server storage.
 */
#[Layout('layouts::admin')]
class Index extends Component
{
    use WithFileUploads;

    #[Validate('required|file|max:51200')]
    public $uploadFile;

    public bool $processing = false;

    /** @var array<int, array{name: string, size: string, uploaded_at: string, path: string}> */
    public array $uploadedFiles = [];

    /**
     * Mount the component and load existing uploaded files.
     */
    public function mount(): void
    {
        $this->loadUploadedFiles();
    }

    /**
     * Load the list of previously uploaded files from storage.
     */
    public function loadUploadedFiles(): void
    {
        $this->uploadedFiles = [];
        $disk = Storage::disk('local');
        $files = $disk->files('uploads');

        foreach ($files as $filePath) {
            $this->uploadedFiles[] = [
                'name' => basename($filePath),
                'size' => $this->formatBytes($disk->size($filePath)),
                'uploaded_at' => date('M j, Y g:i A', $disk->lastModified($filePath)),
                'path' => $filePath,
            ];
        }

        // Sort newest first
        usort($this->uploadedFiles, fn ($a, $b) => $disk->lastModified('uploads/' . $b['name']) <=> $disk->lastModified('uploads/' . $a['name']));
    }

    /**
     * Save the uploaded file to storage.
     */
    public function save(): void
    {
        $this->validate();
        $this->processing = true;

        try {
            $originalName = $this->uploadFile->getClientOriginalName();

            // Avoid overwriting: append timestamp if file exists
            $disk = Storage::disk('local');
            $name = $originalName;

            if ($disk->exists('uploads/' . $name)) {
                $extension = pathinfo($name, PATHINFO_EXTENSION);
                $basename = pathinfo($name, PATHINFO_FILENAME);
                $name = $basename . '_' . time() . ($extension ? '.' . $extension : '');
            }

            $this->uploadFile->storeAs('uploads', $name, 'local');

            Flux::toast('File uploaded successfully: ' . $name);
            $this->reset('uploadFile');
            $this->loadUploadedFiles();
        } catch (\Exception $e) {
            Flux::toast('Upload failed: ' . $e->getMessage(), variant: 'danger');
        } finally {
            $this->processing = false;
        }
    }

    /**
     * Delete an uploaded file.
     *
     * @param string $path The storage path of the file to delete
     */
    public function deleteFile(string $path): void
    {
        try {
            Storage::disk('local')->delete($path);
            Flux::toast('File deleted.');
            $this->loadUploadedFiles();
        } catch (\Exception $e) {
            Flux::toast('Failed to delete file: ' . $e->getMessage(), variant: 'danger');
        }
    }

    /**
     * Download an uploaded file.
     *
     * @param string $path The storage path of the file to download
     * @return \Symfony\Component\HttpFoundation\StreamedResponse
     */
    public function downloadFile(string $path)
    {
        return Storage::disk('local')->download($path, basename($path));
    }

    /**
     * Format bytes into a human-readable string.
     *
     * @param int $bytes The file size in bytes
     * @return string
     */
    private function formatBytes(int $bytes): string
    {
        if ($bytes === 0) {
            return '0 B';
        }

        $units = ['B', 'KB', 'MB', 'GB'];
        $i = (int) floor(log($bytes, 1024));

        return round($bytes / pow(1024, $i), 1) . ' ' . $units[$i];
    }

    public function render()
    {
        return view('livewire.admin.file-upload.index');
    }
}
