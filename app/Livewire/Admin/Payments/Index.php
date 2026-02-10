<?php

namespace App\Livewire\Admin\Payments;

use App\Models\Payment;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Payments Index Component
 *
 * Lists all payments with search and filtering.
 */
#[Layout('layouts.admin')]
class Index extends Component
{
    use WithPagination;

    #[Url(as: 'q')]
    public string $search = '';

    #[Url]
    public string $status = '';

    #[Url]
    public string $dateRange = '';

    public ?Payment $selectedPayment = null;

    public bool $showDetails = false;

    /**
     * Reset pagination when filters change.
     */
    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedStatus(): void
    {
        $this->resetPage();
    }

    public function updatedDateRange(): void
    {
        $this->resetPage();
    }

    /**
     * Show payment details modal.
     */
    public function viewPayment(int $id): void
    {
        $this->selectedPayment = Payment::with(['paymentPlan', 'customer'])->find($id);
        $this->showDetails = true;
    }

    /**
     * Close details modal.
     */
    public function closeDetails(): void
    {
        $this->showDetails = false;
        $this->selectedPayment = null;
    }

    /**
     * Get filtered payments.
     */
    public function getPayments()
    {
        $query = Payment::query()->with(['paymentPlan', 'customer']);

        // Search by transaction ID or metadata
        if ($this->search) {
            $query->where(function ($q) {
                $q->where('transaction_id', 'like', "%{$this->search}%")
                    ->orWhere('description', 'like', "%{$this->search}%");
            });
        }

        // Filter by status
        if ($this->status) {
            $query->where('status', $this->status);
        }

        // Filter by date range
        if ($this->dateRange) {
            $today = now();
            switch ($this->dateRange) {
                case 'today':
                    $query->whereDate('created_at', $today);
                    break;
                case 'week':
                    $query->where('created_at', '>=', $today->copy()->startOfWeek());
                    break;
                case 'month':
                    $query->where('created_at', '>=', $today->copy()->startOfMonth());
                    break;
                case 'year':
                    $query->where('created_at', '>=', $today->copy()->startOfYear());
                    break;
            }
        }

        return $query->orderBy('created_at', 'desc')->paginate(20);
    }

    public function render()
    {
        return view('livewire.admin.payments.index', [
            'payments' => $this->getPayments(),
        ]);
    }
}
