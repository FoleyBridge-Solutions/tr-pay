<?php

namespace App\Livewire\Admin\RecurringPayments;

use App\Models\AdminActivity;
use App\Models\RecurringPayment;
use App\Services\RecurringPaymentImportService;
use Flux\Flux;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithFileUploads;

/**
 * Recurring Payments Import Component
 *
 * Handles CSV file upload and import of recurring payments.
 */
#[Layout('layouts.admin')]
class Import extends Component
{
    use WithFileUploads;

    public $csvFile;

    public string $csvContent = '';

    public bool $useTextInput = false;

    public bool $processing = false;

    public ?array $importResult = null;

    protected $rules = [
        'csvFile' => 'nullable|file|mimes:csv,txt,xlsx,xls|max:5120', // 5MB max
        'csvContent' => 'nullable|string',
    ];

    /**
     * Toggle between file upload and text input.
     */
    public function toggleInputMethod(): void
    {
        $this->useTextInput = ! $this->useTextInput;
        $this->reset(['csvFile', 'csvContent', 'importResult']);
    }

    /**
     * Download sample CSV template.
     */
    public function downloadTemplate()
    {
        $content = RecurringPaymentImportService::getSampleCsv();

        return response()->streamDownload(function () use ($content) {
            echo $content;
        }, 'recurring_payments_template.csv', [
            'Content-Type' => 'text/csv',
        ]);
    }

    /**
     * Process the import.
     */
    public function import(): void
    {
        $this->importResult = null;
        $this->processing = true;

        try {
            $service = new RecurringPaymentImportService;

            if ($this->csvFile) {
                $extension = strtolower($this->csvFile->getClientOriginalExtension());
                $filePath = $this->csvFile->getRealPath();

                // Use Excel import for .xlsx and .xls files
                if (in_array($extension, ['xlsx', 'xls'])) {
                    $this->importResult = $service->importFromExcel($filePath);
                } else {
                    // CSV/TXT file - read content and use text import
                    $content = file_get_contents($filePath);
                    $this->importResult = $service->import($content);
                }
            } elseif ($this->csvContent) {
                // Pasted text content
                $this->importResult = $service->import($this->csvContent);
            } else {
                $this->importResult = [
                    'success' => false,
                    'error' => 'Please upload a file (CSV, XLSX, XLS) or paste CSV content.',
                    'imported' => 0,
                    'errors' => [],
                ];
                $this->processing = false;

                return;
            }

            if ($this->importResult['success']) {
                // Log the import activity
                $warningCount = count($this->importResult['warnings'] ?? []);
                AdminActivity::log(
                    AdminActivity::ACTION_IMPORTED,
                    RecurringPayment::class,
                    description: "Imported {$this->importResult['imported']} recurring payments from CSV".($warningCount > 0 ? " with {$warningCount} warnings" : ''),
                    newValues: [
                        'imported_count' => $this->importResult['imported'],
                        'error_count' => count($this->importResult['errors'] ?? []),
                        'warning_count' => $warningCount,
                    ]
                );

                $message = "Successfully imported {$this->importResult['imported']} recurring payments.";
                if ($warningCount > 0) {
                    $message .= " ({$warningCount} items need review)";
                }
                Flux::toast($message);
                $this->reset(['csvFile', 'csvContent']);
            }
        } catch (\Exception $e) {
            $this->importResult = [
                'success' => false,
                'error' => 'Import failed: '.$e->getMessage(),
                'imported' => 0,
                'errors' => [],
            ];
        } finally {
            $this->processing = false;
        }
    }

    /**
     * Reset the form.
     */
    public function resetForm(): void
    {
        $this->reset(['csvFile', 'csvContent', 'importResult']);
    }

    public function render()
    {
        return view('livewire.admin.recurring-payments.import');
    }
}
