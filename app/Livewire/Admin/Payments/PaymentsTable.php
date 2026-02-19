<?php

// app/Livewire/Admin/Payments/PaymentsTable.php

namespace App\Livewire\Admin\Payments;

use App\Models\Payment;
use App\Services\PaymentService;
use Livewire\Attributes\Lazy;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Lazy-loaded payments table with filters and detail modal.
 *
 * Displays paginated payments with search, status, and date range filtering.
 * By default, failed payments are hidden unless explicitly selected.
 */
#[Lazy]
class PaymentsTable extends Component
{
    use WithPagination;

    /**
     * Default statuses shown when no filter is applied.
     * Excludes 'failed' so users only see them when explicitly filtering.
     */
    public const DEFAULT_STATUSES = ['completed', 'processing', 'pending', 'refunded', 'returned', 'voided', 'skipped'];

    #[Url(as: 'q')]
    public string $search = '';

    /**
     * Selected status filters (multi-select).
     *
     * @var array<int, string>
     */
    #[Url]
    public array $status = [];

    /**
     * Selected date range filters (multi-select).
     *
     * @var array<int, string>
     */
    #[Url]
    public array $dateRange = [];

    /**
     * Selected source filters (multi-select).
     *
     * @var array<int, string>
     */
    #[Url]
    public array $source = [];

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

    /**
     * Reset pagination when status filter changes.
     */
    public function updatedStatus(): void
    {
        $this->resetPage();
    }

    /**
     * Reset pagination when date range filter changes.
     */
    public function updatedDateRange(): void
    {
        $this->resetPage();
    }

    /**
     * Reset pagination when source filter changes.
     */
    public function updatedSource(): void
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
     * Void a processing ACH payment.
     */
    public function voidPayment(int $id): void
    {
        $payment = Payment::find($id);

        if (! $payment) {
            $this->dispatch('notify', type: 'error', message: 'Payment not found.');

            return;
        }

        $service = app(PaymentService::class);
        $result = $service->voidAchPayment($payment, 'Voided by admin');

        if ($result['success']) {
            $this->dispatch('notify', type: 'success', message: "Payment #{$id} voided successfully (\${$result['amount']}).");
        } else {
            $this->dispatch('notify', type: 'error', message: "Failed to void: {$result['error']}");
        }

        // Close modal if open
        $this->closeDetails();
    }

    /**
     * Refund a completed card payment.
     */
    public function refundPayment(int $id): void
    {
        $payment = Payment::find($id);

        if (! $payment) {
            $this->dispatch('notify', type: 'error', message: 'Payment not found.');

            return;
        }

        $service = app(PaymentService::class);
        $result = $service->refundCardPayment($payment, 'Refunded by admin');

        if ($result['success']) {
            $this->dispatch('notify', type: 'success', message: "Payment #{$id} refunded successfully (\${$result['amount']}).");
        } else {
            $this->dispatch('notify', type: 'error', message: "Failed to refund: {$result['error']}");
        }

        // Close modal if open
        $this->closeDetails();
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
            'source_label' => $payment->source_label,
            'source_badge_color' => $payment->source_badge_color,
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

        // Filter by status — default excludes 'failed' unless explicitly selected
        $statuses = ! empty($this->status) ? $this->status : self::DEFAULT_STATUSES;
        $query->whereIn('status', $statuses);

        // Filter by date range (multi-select: union of selected ranges)
        if (! empty($this->dateRange)) {
            $today = now();
            $query->where(function ($q) use ($today) {
                foreach ($this->dateRange as $range) {
                    $q->orWhere(function ($sub) use ($range, $today) {
                        match ($range) {
                            'today' => $sub->whereDate('created_at', $today),
                            'week' => $sub->where('created_at', '>=', $today->copy()->startOfWeek()),
                            'month' => $sub->where('created_at', '>=', $today->copy()->startOfMonth()),
                            'year' => $sub->where('created_at', '>=', $today->copy()->startOfYear()),
                            default => null,
                        };
                    });
                }
            });
        }

        // Filter by source (multi-select, queries metadata JSON)
        if (! empty($this->source)) {
            $query->where(function ($q) {
                $selectedSources = $this->source;

                // 'plan-installment' is a virtual source: tr-pay + has payment_plan_id
                if (in_array('plan-installment', $selectedSources)) {
                    $q->orWhere(function ($sub) {
                        $sub->whereRaw("json_extract(metadata, '$.source') = ?", [Payment::SOURCE_PUBLIC])
                            ->whereNotNull('payment_plan_id');
                    });
                    $selectedSources = array_diff($selectedSources, ['plan-installment']);
                }

                // 'public' needs to exclude plan installments
                if (in_array(Payment::SOURCE_PUBLIC, $selectedSources)) {
                    $q->orWhere(function ($sub) {
                        $sub->whereRaw("json_extract(metadata, '$.source') = ?", [Payment::SOURCE_PUBLIC])
                            ->whereNull('payment_plan_id');
                    });
                    $selectedSources = array_diff($selectedSources, [Payment::SOURCE_PUBLIC]);
                }

                // 'admin-scheduled' filter matches both admin-scheduled and tr-pay-scheduled
                if (in_array('admin-scheduled', $selectedSources)) {
                    $q->orWhereRaw("json_extract(metadata, '$.source') IN (?, ?)", [
                        Payment::SOURCE_ADMIN_SCHEDULED,
                        Payment::SOURCE_SCHEDULED,
                    ]);
                    $selectedSources = array_diff($selectedSources, ['admin-scheduled']);
                }

                // Remaining sources are straightforward metadata matches
                $remainingSources = array_values($selectedSources);
                if (! empty($remainingSources)) {
                    foreach ($remainingSources as $src) {
                        $q->orWhereRaw("json_extract(metadata, '$.source') = ?", [$src]);
                    }
                }
            });
        }

        return $query->orderBy('created_at', 'desc')->paginate(20);
    }

    /**
     * Skeleton placeholder shown while component loads.
     */
    public function placeholder(): string
    {
        return <<<'HTML'
        <flux:skeleton.group animate="shimmer">
            {{-- Filters skeleton --}}
            <flux:card class="mb-6">
                <div class="flex flex-col md:flex-row gap-4 p-4">
                    <div class="flex-1">
                        <flux:skeleton class="h-10 w-full rounded-lg" />
                    </div>
                    <div class="w-full md:w-48">
                        <flux:skeleton class="h-10 w-full rounded-lg" />
                    </div>
                    <div class="w-full md:w-48">
                        <flux:skeleton class="h-10 w-full rounded-lg" />
                    </div>
                    <div class="w-full md:w-48">
                        <flux:skeleton class="h-10 w-full rounded-lg" />
                    </div>
                </div>
            </flux:card>

            {{-- Table skeleton --}}
            <flux:card>
                <div class="overflow-x-auto">
                    {{-- Table header --}}
                    <div class="flex items-center gap-4 px-4 py-3 border-b border-zinc-200 dark:border-zinc-700">
                        <flux:skeleton class="h-4 w-28 rounded" />
                        <flux:skeleton class="h-4 w-24 rounded" />
                        <flux:skeleton class="h-4 w-20 rounded" />
                        <flux:skeleton class="h-4 w-20 rounded" />
                        <flux:skeleton class="h-4 w-20 rounded" />
                        <flux:skeleton class="h-4 w-20 rounded" />
                        <flux:skeleton class="h-4 w-16 rounded" />
                        <flux:skeleton class="h-4 w-20 rounded" />
                        <flux:skeleton class="h-4 w-12 rounded" />
                    </div>

                    {{-- Table rows --}}
                    @for ($i = 0; $i < 5; $i++)
                        <div class="flex items-center gap-4 px-4 py-3 border-b border-zinc-200 dark:border-zinc-700">
                            <flux:skeleton.line class="w-28" />
                            <flux:skeleton.line class="w-24" />
                            <flux:skeleton.line class="w-20" />
                            <flux:skeleton.line class="w-20" />
                            <flux:skeleton class="h-5 w-20 rounded-full" />
                            <flux:skeleton class="h-5 w-20 rounded-full" />
                            <flux:skeleton.line class="w-16" />
                            <flux:skeleton.line class="w-20" />
                            <flux:skeleton class="h-8 w-12 rounded" />
                        </div>
                    @endfor
                </div>

                {{-- Pagination skeleton --}}
                <div class="p-4 border-t border-zinc-200 dark:border-zinc-700">
                    <div class="flex items-center justify-between">
                        <flux:skeleton.line class="w-32" />
                        <div class="flex gap-1">
                            <flux:skeleton class="h-8 w-8 rounded" />
                            <flux:skeleton class="h-8 w-8 rounded" />
                            <flux:skeleton class="h-8 w-8 rounded" />
                        </div>
                    </div>
                </div>
            </flux:card>
        </flux:skeleton.group>
        HTML;
    }

    /**
     * Render the component.
     */
    public function render()
    {
        return view('livewire.admin.payments.payments-table', [
            'payments' => $this->getPayments(),
        ]);
    }
}
