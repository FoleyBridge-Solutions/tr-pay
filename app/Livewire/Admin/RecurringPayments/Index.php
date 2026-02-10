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
#[Layout('layouts.admin')]
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

    public ?RecurringPayment $selectedPayment = null;

    public bool $showDetails = false;

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
        $this->selectedPayment = RecurringPayment::with('payments')->find($id);
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
     * Open cancel confirmation modal.
     */
    public function confirmCancel(int $id): void
    {
        $this->selectedPayment = RecurringPayment::find($id);
        $this->showCancelModal = true;
    }

    /**
     * Cancel the recurring payment.
     */
    public function cancelPayment(): void
    {
        if ($this->selectedPayment) {
            $payment = $this->selectedPayment;
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
            $this->selectedPayment = null;
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
