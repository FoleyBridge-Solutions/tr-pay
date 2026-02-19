<?php

// app/Livewire/Admin/RecurringPayments/RecurringTable.php

namespace App\Livewire\Admin\RecurringPayments;

use App\Models\AdminActivity;
use App\Models\RecurringPayment;
use App\Repositories\PaymentRepository;
use Carbon\Carbon;
use Flux\Flux;
use Illuminate\Support\Facades\Log;
use Livewire\Attributes\Lazy;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Lazy-loaded recurring payments table with filters, sorting, and management actions.
 *
 * Displays all recurring payments with search, status/frequency filters,
 * sortable columns, and modals for details, cancel, skip, and date adjustment.
 */
#[Lazy]
class RecurringTable extends Component
{
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

    public bool $showSkipModal = false;

    public bool $showAdjustDateModal = false;

    public string $adjustDate = '';

    protected PaymentRepository $paymentRepo;

    public function boot(PaymentRepository $paymentRepo): void
    {
        $this->paymentRepo = $paymentRepo;
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
                $liveName = $this->paymentRepo->getClientName($payment->client_id);
                if ($liveName) {
                    $clientName = $liveName;
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
            'can_skip' => $payment->isActive() && $payment->next_payment_date !== null,
            'can_adjust_date' => $payment->next_payment_date !== null && in_array($payment->status, ['active', 'paused']),
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
     * Open skip confirmation modal for a recurring payment.
     */
    public function confirmSkip(int $id): void
    {
        $this->selectedPaymentId = $id;
        $this->showSkipModal = true;
    }

    /**
     * Reset skip modal state when closed.
     */
    public function resetSkipModal(): void
    {
        $this->showSkipModal = false;
    }

    /**
     * Skip the next payment for the selected recurring payment.
     */
    public function skipPayment(): void
    {
        $payment = $this->selectedPaymentId ? RecurringPayment::find($this->selectedPaymentId) : null;

        if (! $payment || ! $payment->isActive() || ! $payment->next_payment_date) {
            Flux::toast('This recurring payment cannot be skipped.', variant: 'danger');

            return;
        }

        $clientName = $payment->client_name;
        $skippedDate = $payment->next_payment_date->format('Y-m-d');

        try {
            $payment->skipNextPayment();

            AdminActivity::log(
                AdminActivity::ACTION_SKIPPED,
                $payment,
                description: "Skipped recurring payment for {$clientName} (was due {$skippedDate})",
                newValues: [
                    'id' => $payment->id,
                    'client_name' => $clientName,
                    'skipped_date' => $skippedDate,
                    'amount' => $payment->amount,
                    'frequency' => $payment->frequency,
                    'payments_completed' => $payment->payments_completed,
                    'next_payment_date' => $payment->next_payment_date?->format('Y-m-d'),
                    'status' => $payment->status,
                ]
            );

            $this->showSkipModal = false;
            $this->selectedPaymentId = null;

            Flux::toast("Skipped payment for {$clientName}. Next payment date updated.");
        } catch (\Exception $e) {
            Log::error('Failed to skip recurring payment', [
                'id' => $payment->id,
                'error' => $e->getMessage(),
            ]);
            Flux::toast('Failed to skip payment: '.$e->getMessage(), variant: 'danger');
        }
    }

    /**
     * Open the adjust date modal for a recurring payment.
     */
    public function showAdjustDate(int $id): void
    {
        $this->selectedPaymentId = $id;
        $payment = RecurringPayment::find($id);
        $this->adjustDate = $payment?->next_payment_date?->format('Y-m-d') ?? '';
        $this->showAdjustDateModal = true;
    }

    /**
     * Reset adjust date modal state when closed.
     */
    public function resetAdjustDateModal(): void
    {
        $this->showAdjustDateModal = false;
        $this->adjustDate = '';
    }

    /**
     * Adjust the next payment date for the selected recurring payment.
     */
    public function adjustPaymentDate(): void
    {
        $payment = $this->selectedPaymentId ? RecurringPayment::find($this->selectedPaymentId) : null;

        if (! $payment) {
            return;
        }

        $this->validate([
            'adjustDate' => 'required|date|after:today',
        ], [
            'adjustDate.after' => 'The new payment date must be in the future.',
        ]);

        $clientName = $payment->client_name;
        $oldDate = $payment->next_payment_date?->format('Y-m-d');
        $newDate = Carbon::parse($this->adjustDate);

        try {
            $payment->adjustNextPaymentDate($newDate);

            AdminActivity::log(
                AdminActivity::ACTION_UPDATED,
                $payment,
                description: "Adjusted next payment date for {$clientName} from {$oldDate} to {$this->adjustDate}",
                oldValues: ['next_payment_date' => $oldDate],
                newValues: [
                    'id' => $payment->id,
                    'client_name' => $clientName,
                    'next_payment_date' => $this->adjustDate,
                ]
            );

            $this->showAdjustDateModal = false;
            $this->adjustDate = '';
            $this->selectedPaymentId = null;

            Flux::toast("Payment date adjusted for {$clientName}.");
        } catch (\Exception $e) {
            Log::error('Failed to adjust recurring payment date', [
                'id' => $payment->id,
                'error' => $e->getMessage(),
            ]);
            Flux::toast('Failed to adjust date: '.$e->getMessage(), variant: 'danger');
        }
    }

    /**
     * Get filtered recurring payments.
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, RecurringPayment>
     */
    public function getPayments(): \Illuminate\Database\Eloquent\Collection
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

        return $query->orderBy($this->sortField, $this->sortDirection)->get();
    }

    /**
     * Fetch live client names from PracticeCS for the given payments.
     *
     * @param  \Illuminate\Support\Collection<int, RecurringPayment>  $payments
     * @return array<string, string> Map of client_id => client_name
     */
    protected function getClientNames($payments): array
    {
        $clientIds = $payments->pluck('client_id')->unique()->filter()->values()->toArray();

        if (empty($clientIds)) {
            return [];
        }

        try {
            return $this->paymentRepo->getClientNames($clientIds);
        } catch (\Exception $e) {
            Log::warning('Failed to fetch live client names from PracticeCS', [
                'error' => $e->getMessage(),
            ]);

            return [];
        }
    }

    /**
     * Skeleton placeholder shown while component loads.
     */
    public function placeholder(): string
    {
        return <<<'HTML'
        <div>
            {{-- Filter skeleton --}}
            <flux:card class="mb-6">
                <div class="flex flex-col md:flex-row gap-4 p-4">
                    <flux:skeleton.group animate="shimmer">
                        <div class="flex-1">
                            <flux:skeleton class="h-10 w-full rounded" />
                        </div>
                        <div class="w-full md:w-40">
                            <flux:skeleton class="h-10 w-full rounded" />
                        </div>
                        <div class="w-full md:w-40">
                            <flux:skeleton class="h-10 w-full rounded" />
                        </div>
                    </flux:skeleton.group>
                </div>
            </flux:card>

            {{-- Table skeleton --}}
            <flux:card>
                <flux:skeleton.group animate="shimmer">
                    <div class="divide-y divide-zinc-200 dark:divide-zinc-700">
                        {{-- Header row --}}
                        <div class="px-4 py-3 flex items-center gap-6">
                            <flux:skeleton.line class="w-28" />
                            <flux:skeleton.line class="w-16" />
                            <flux:skeleton.line class="w-20" />
                            <flux:skeleton.line class="w-24" />
                            <flux:skeleton.line class="w-16" />
                            <flux:skeleton.line class="w-20" />
                            <flux:skeleton.line class="w-24" />
                        </div>
                        {{-- Data rows --}}
                        @for ($i = 0; $i < 5; $i++)
                            <div class="px-4 py-3 flex items-center gap-6">
                                <div class="w-28 space-y-1">
                                    <flux:skeleton.line class="w-24" />
                                    <flux:skeleton.line class="w-16" />
                                </div>
                                <flux:skeleton.line class="w-16" />
                                <flux:skeleton.line class="w-20" />
                                <flux:skeleton.line class="w-24" />
                                <flux:skeleton class="h-5 w-16 rounded-full" />
                                <div class="w-20 space-y-1">
                                    <flux:skeleton.line class="w-16" />
                                    <flux:skeleton.line class="w-20" />
                                </div>
                                <div class="flex gap-1">
                                    <flux:skeleton class="h-7 w-14 rounded" />
                                    <flux:skeleton class="h-7 w-14 rounded" />
                                </div>
                            </div>
                        @endfor
                    </div>
                </flux:skeleton.group>
            </flux:card>
        </div>
        HTML;
    }

    public function render()
    {
        $payments = $this->getPayments();
        $clientNames = $this->getClientNames($payments);

        return view('livewire.admin.recurring-payments.recurring-table', [
            'payments' => $payments,
            'clientNames' => $clientNames,
            'frequencies' => RecurringPayment::getFrequencies(),
        ]);
    }
}
