<?php

namespace App\Livewire\Admin\RecurringPayments;

use App\Models\AdminActivity;
use App\Models\RecurringPayment;
use Flux\Flux;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Recurring Payments Index Component
 *
 * Lists all recurring payments with filtering and management actions.
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
    public string $frequency = '';

    public string $sortField = 'next_payment_date';

    public string $sortDirection = 'asc';

    public ?int $selectedPaymentId = null;

    /**
     * Pre-formatted recurring payment details for Alpine display (bypasses modal morph issues).
     *
     * @var array<string, mixed>
     */
    public array $recurringDetails = [];

    /**
     * Serialized payment history rows for Alpine x-for display.
     *
     * @var array<int, array<string, mixed>>
     */
    public array $recurringHistory = [];

    public bool $showCancelModal = false;

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

    public function updatedFrequency(): void
    {
        $this->resetPage();
    }

    /**
     * Sort by the given field.
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
     * View payment details.
     */
    public function viewPayment(int $id): void
    {
        $this->selectedPaymentId = $id;
        $payment = RecurringPayment::with('payments')->find($id);
        $this->recurringDetails = $this->formatRecurringDetails($payment);
        $this->recurringHistory = $this->formatRecurringHistory($payment);
        $this->modal('recurring-payment-details')->show();
    }

    /**
     * Close details modal.
     */
    public function closeDetails(): void
    {
        $this->selectedPaymentId = null;
        $this->recurringDetails = [];
        $this->recurringHistory = [];
    }

    /**
     * Reset cancel modal state when closed.
     */
    public function resetCancelModal(): void
    {
        $this->showCancelModal = false;
        $this->selectedPaymentId = null;
    }

    /**
     * Pause a recurring payment.
     */
    public function pausePayment(int $id): void
    {
        $payment = RecurringPayment::find($id);
        if ($payment && $payment->isActive()) {
            $payment->pause();

            AdminActivity::log(
                AdminActivity::ACTION_PAUSED,
                $payment,
                description: "Paused recurring payment for {$payment->client_name} - \$".number_format($payment->amount, 2)." {$payment->frequency}",
                newValues: [
                    'id' => $payment->id,
                    'client_name' => $payment->client_name,
                    'amount' => $payment->amount,
                    'frequency' => $payment->frequency,
                    'status' => 'paused',
                    'previous_status' => 'active',
                ]
            );

            Flux::toast('Recurring payment paused.');
        }
    }

    /**
     * Resume a recurring payment.
     */
    public function resumePayment(int $id): void
    {
        $payment = RecurringPayment::find($id);
        if ($payment && $payment->status === RecurringPayment::STATUS_PAUSED) {
            $payment->resume();

            AdminActivity::log(
                AdminActivity::ACTION_RESUMED,
                $payment,
                description: "Resumed recurring payment for {$payment->client_name} - \$".number_format($payment->amount, 2)." {$payment->frequency}",
                newValues: [
                    'id' => $payment->id,
                    'client_name' => $payment->client_name,
                    'amount' => $payment->amount,
                    'frequency' => $payment->frequency,
                    'status' => 'active',
                    'previous_status' => 'paused',
                    'next_payment_date' => $payment->next_payment_date?->format('Y-m-d'),
                ]
            );

            Flux::toast('Recurring payment resumed.');
        }
    }

    /**
     * Format recurring payment data for Alpine display inside the modal.
     *
     * @return array<string, mixed>
     */
    protected function formatRecurringDetails(?RecurringPayment $payment): array
    {
        if (! $payment) {
            return [];
        }

        // Look up client name from PracticeCS
        $clientName = $payment->client_name;
        if ($payment->client_id) {
            try {
                $result = DB::connection('sqlsrv')->selectOne(
                    'SELECT description AS client_name FROM Client WHERE client_id = ?',
                    [$payment->client_id]
                );
                if ($result) {
                    $clientName = $result->client_name;
                }
            } catch (\Exception $e) {
                // Fall back to stored client_name on the model
            }
        }

        return [
            'id' => $payment->id,
            'client_id' => $payment->client_id,
            'client_name' => $clientName ?? '-',
            'client_url' => $payment->client_id ? route('admin.clients.show', $payment->client_id) : null,
            'amount' => number_format($payment->amount, 2),
            'frequency_label' => $payment->frequency_label ?? '-',
            'payment_method_type' => $payment->payment_method_type ?? '-',
            'payment_method_last_four' => $payment->payment_method_last_four,
            'start_date' => $payment->start_date?->toIso8601String(),
            'end_date' => $payment->end_date?->toIso8601String(),
            'has_max_occurrences' => (bool) $payment->max_occurrences,
            'payments_completed' => $payment->payments_completed ?? 0,
            'max_occurrences' => $payment->max_occurrences,
            'remaining_occurrences' => $payment->remaining_occurrences ?? 0,
            'next_payment_date' => $payment->next_payment_date?->toIso8601String(),
            'total_collected' => number_format($payment->total_collected ?? 0, 2),
            'description' => $payment->description,
            'status' => $payment->status,
            'has_history' => $payment->payments && $payment->payments->count() > 0,
        ];
    }

    /**
     * Format payment history for Alpine x-for display.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function formatRecurringHistory(?RecurringPayment $payment): array
    {
        if (! $payment || ! $payment->payments || $payment->payments->isEmpty()) {
            return [];
        }

        return $payment->payments->sortByDesc('created_at')->take(10)->map(fn ($p) => [
            'created_at' => $p->created_at?->toIso8601String(),
            'amount' => number_format($p->amount, 2),
            'status' => $p->status,
        ])->values()->toArray();
    }

    /**
     * Open cancel confirmation modal.
     */
    public function confirmCancel(int $id): void
    {
        $this->selectedPaymentId = $id;
        $this->showCancelModal = true;
    }

    /**
     * Cancel the recurring payment.
     */
    public function cancelPayment(): void
    {
        $payment = $this->selectedPaymentId ? RecurringPayment::find($this->selectedPaymentId) : null;

        if ($payment) {
            $clientName = $payment->client_name;
            $previousStatus = $payment->status;
            $payment->cancel();

            AdminActivity::log(
                AdminActivity::ACTION_CANCELLED,
                $payment,
                description: "Cancelled recurring payment for {$clientName} - \$".number_format($payment->amount, 2)." {$payment->frequency}",
                newValues: [
                    'id' => $payment->id,
                    'client_name' => $clientName,
                    'amount' => $payment->amount,
                    'frequency' => $payment->frequency,
                    'status' => 'cancelled',
                    'previous_status' => $previousStatus,
                    'payments_made' => $payment->occurrences_count ?? 0,
                ]
            );

            $this->showCancelModal = false;
            $this->selectedPaymentId = null;
            Flux::toast('Recurring payment cancelled.');
        }
    }

    /**
     * Get filtered recurring payments.
     */
    public function getPayments()
    {
        $query = RecurringPayment::query();

        if ($this->search) {
            $query->where(function ($q) {
                $q->where('client_name', 'like', "%{$this->search}%")
                    ->orWhere('description', 'like', "%{$this->search}%");
            });
        }

        if ($this->status) {
            $query->where('status', $this->status);
        }

        if ($this->frequency) {
            $query->where('frequency', $this->frequency);
        }

        return $query->orderBy($this->sortField, $this->sortDirection)->paginate(20);
    }

    /**
     * Fetch live client names from PracticeCS for the given payments.
     *
     * @param  \Illuminate\Pagination\LengthAwarePaginator  $payments
     * @return array<string, string> Map of client_id => client_name
     */
    protected function getClientNames($payments): array
    {
        // Get unique client IDs from the current page of payments
        $clientIds = $payments->pluck('client_id')->unique()->filter()->values()->toArray();

        if (empty($clientIds)) {
            return [];
        }

        try {
            // Build placeholders for IN clause
            $placeholders = implode(',', array_fill(0, count($clientIds), '?'));

            $results = DB::connection('sqlsrv')->select("
                SELECT client_id, description AS client_name
                FROM Client
                WHERE client_id IN ({$placeholders})
            ", $clientIds);

            // Convert to associative array: client_id => client_name
            $clientNames = [];
            foreach ($results as $row) {
                $clientNames[$row->client_id] = $row->client_name;
            }

            return $clientNames;
        } catch (\Exception $e) {
            Log::warning('Failed to fetch live client names from PracticeCS', [
                'error' => $e->getMessage(),
            ]);

            return [];
        }
    }

    public function render()
    {
        $payments = $this->getPayments();
        $clientNames = $this->getClientNames($payments);

        return view('livewire.admin.recurring-payments.index', [
            'payments' => $payments,
            'clientNames' => $clientNames,
            'frequencies' => RecurringPayment::getFrequencies(),
        ]);
    }
}
