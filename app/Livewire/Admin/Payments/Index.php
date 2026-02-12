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
#[Layout('layouts::admin')]
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

    /**
     * Pre-formatted payment details for Alpine display (bypasses modal morph issues).
     *
     * @var array<string, mixed>
     */
    public array $paymentDetails = [];

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
        $this->paymentDetails = $this->formatPaymentDetails($this->selectedPayment);
        $this->modal('payment-details')->show();
    }

    /**
     * Close details modal.
     */
    public function closeDetails(): void
    {
        $this->selectedPayment = null;
        $this->paymentDetails = [];
    }

    /**
     * Format payment data for Alpine display inside the modal.
     *
     * @return array<string, mixed>
     */
    protected function formatPaymentDetails(?Payment $payment): array
    {
        if (! $payment) {
            return [];
        }

        $metadata = $payment->metadata ?? [];
        $clientName = $metadata['client_name'] ?? $payment->customer?->name ?? null;
        $clientId = $metadata['client_id'] ?? $payment->client_id ?? null;

        return [
            'transaction_id' => $payment->transaction_id ?? '-',
            'client_name' => $clientName,
            'client_id' => $clientId,
            'client_url' => $clientId ? route('admin.clients.show', $clientId) : null,
            'amount' => number_format($payment->amount, 2),
            'fee' => number_format($payment->fee, 2),
            'has_fee' => $payment->fee > 0,
            'total_amount' => number_format($payment->total_amount, 2),
            'payment_method' => $payment->payment_method ?? '-',
            'payment_method_last_four' => $payment->payment_method_last_four,
            'status' => $payment->status,
            'failure_reason' => $payment->failure_reason,
            'has_plan' => (bool) $payment->paymentPlan,
            'payment_number' => $payment->payment_number,
            'plan_duration' => $payment->paymentPlan?->duration_months,
            'created_at' => $payment->created_at?->toIso8601String(),
            'processed_at' => $payment->processed_at?->toIso8601String(),
        ];
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
