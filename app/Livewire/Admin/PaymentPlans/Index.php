<?php

namespace App\Livewire\Admin\PaymentPlans;

use App\Models\AdminActivity;
use App\Models\PaymentPlan;
use App\Repositories\PaymentRepository;
use Carbon\Carbon;
use Flux\Flux;
use Illuminate\Support\Facades\Log;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Payment Plans Index Component
 *
 * Lists all payment plans with filtering and management actions.
 */
#[Layout('layouts::admin')]
class Index extends Component
{
    use WithPagination;

    #[Url(as: 'q')]
    public string $search = '';

    #[Url]
    public string $status = '';

    public ?PaymentPlan $selectedPlan = null;

    /**
     * Pre-formatted plan details for Alpine display (bypasses modal morph issues).
     *
     * @var array<string, mixed>
     */
    public array $planDetails = [];

    /**
     * Serialized payment schedule rows for Alpine x-for display.
     *
     * @var array<int, array<string, mixed>>
     */
    public array $planPayments = [];

    public bool $showCancelModal = false;

    public string $cancelReason = '';

    public bool $showSkipModal = false;

    public bool $showAdjustDateModal = false;

    public string $adjustDate = '';

    protected PaymentRepository $paymentRepo;

    public function boot(PaymentRepository $paymentRepo): void
    {
        $this->paymentRepo = $paymentRepo;
    }

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

    /**
     * Show plan details modal.
     */
    public function viewPlan(int $id): void
    {
        $this->selectedPlan = PaymentPlan::with(['payments' => function ($q) {
            $q->orderBy('payment_number');
        }])->find($id);
        $this->planDetails = $this->formatPlanDetails($this->selectedPlan);
        $this->planPayments = $this->formatPlanPayments($this->selectedPlan);
        $this->modal('plan-details')->show();
    }

    /**
     * Close details modal.
     */
    public function closeDetails(): void
    {
        $this->selectedPlan = null;
        $this->planDetails = [];
        $this->planPayments = [];
    }

    /**
     * Reset cancel modal state when closed.
     */
    public function resetCancelModal(): void
    {
        $this->showCancelModal = false;
        $this->cancelReason = '';
    }

    /**
     * Open cancel confirmation modal.
     */
    public function confirmCancel(int $id): void
    {
        $this->selectedPlan = PaymentPlan::find($id);
        $this->cancelReason = '';
        $this->showCancelModal = true;
    }

    /**
     * Cancel the payment plan.
     */
    public function cancelPlan(): void
    {
        if (! $this->selectedPlan) {
            return;
        }

        $plan = $this->selectedPlan;
        $planId = $plan->plan_id;
        $previousStatus = $plan->status;
        $plan->cancel($this->cancelReason ?: null);

        // Log the activity
        AdminActivity::log(
            AdminActivity::ACTION_CANCELLED,
            $plan,
            description: "Cancelled payment plan {$planId}".($this->cancelReason ? ": {$this->cancelReason}" : ''),
            newValues: [
                'plan_id' => $planId,
                'client_name' => $plan->customer?->name ?? 'Unknown',
                'total_amount' => $plan->total_amount,
                'monthly_payment' => $plan->monthly_payment,
                'payments_completed' => $plan->payments_completed,
                'payments_remaining' => $plan->duration_months - $plan->payments_completed,
                'status' => 'cancelled',
                'previous_status' => $previousStatus,
                'cancel_reason' => $this->cancelReason ?: null,
            ]
        );

        $this->showCancelModal = false;
        $this->selectedPlan = null;
        $this->cancelReason = '';

        Flux::toast('Payment plan cancelled successfully.');
    }

    /**
     * Open skip confirmation modal.
     */
    public function confirmSkip(int $id): void
    {
        $this->selectedPlan = PaymentPlan::find($id);
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
     * Skip the next payment for the selected plan.
     */
    public function skipPayment(): void
    {
        if (! $this->selectedPlan) {
            return;
        }

        $plan = $this->selectedPlan;

        if (! $plan->canSkipPayment()) {
            Flux::toast('This plan cannot skip any more payments.', variant: 'danger');

            return;
        }

        $planId = $plan->plan_id;
        $skippedDate = $plan->next_payment_date?->format('Y-m-d');

        try {
            $plan->skipNextPayment();

            AdminActivity::log(
                AdminActivity::ACTION_SKIPPED,
                $plan,
                description: "Skipped payment for plan {$planId} (was due {$skippedDate}). Plan extended by 1 month.",
                newValues: [
                    'plan_id' => $planId,
                    'skipped_date' => $skippedDate,
                    'skips_used' => $plan->skips_used,
                    'max_skips' => PaymentPlan::MAX_SKIPS,
                    'new_duration_months' => $plan->duration_months,
                    'next_payment_date' => $plan->next_payment_date?->format('Y-m-d'),
                ]
            );

            $this->showSkipModal = false;

            // Refresh plan details if the modal is open
            $this->selectedPlan = PaymentPlan::with(['payments' => function ($q) {
                $q->orderBy('payment_number');
            }])->find($plan->id);
            $this->planDetails = $this->formatPlanDetails($this->selectedPlan);
            $this->planPayments = $this->formatPlanPayments($this->selectedPlan);

            Flux::toast('Payment skipped. Plan extended by 1 month.');
        } catch (\Exception $e) {
            Log::error('Failed to skip payment plan payment', [
                'plan_id' => $planId,
                'error' => $e->getMessage(),
            ]);
            Flux::toast('Failed to skip payment: '.$e->getMessage(), variant: 'danger');
        }
    }

    /**
     * Open the adjust date modal for the selected plan.
     */
    public function showAdjustDate(int $id): void
    {
        $this->selectedPlan = PaymentPlan::find($id);
        $this->adjustDate = $this->selectedPlan?->next_payment_date?->format('Y-m-d') ?? '';
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
     * Adjust the next payment date for the selected plan.
     */
    public function adjustPaymentDate(): void
    {
        if (! $this->selectedPlan) {
            return;
        }

        $this->validate([
            'adjustDate' => 'required|date|after:today',
        ], [
            'adjustDate.after' => 'The new payment date must be in the future.',
        ]);

        $plan = $this->selectedPlan;
        $planId = $plan->plan_id;
        $oldDate = $plan->next_payment_date?->format('Y-m-d');
        $newDate = Carbon::parse($this->adjustDate);

        try {
            $plan->adjustNextPaymentDate($newDate);

            AdminActivity::log(
                AdminActivity::ACTION_UPDATED,
                $plan,
                description: "Adjusted next payment date for plan {$planId} from {$oldDate} to {$this->adjustDate}",
                oldValues: ['next_payment_date' => $oldDate],
                newValues: [
                    'plan_id' => $planId,
                    'next_payment_date' => $this->adjustDate,
                ]
            );

            $this->showAdjustDateModal = false;
            $this->adjustDate = '';

            // Refresh plan details if the modal is open
            $this->selectedPlan = PaymentPlan::with(['payments' => function ($q) {
                $q->orderBy('payment_number');
            }])->find($plan->id);
            $this->planDetails = $this->formatPlanDetails($this->selectedPlan);
            $this->planPayments = $this->formatPlanPayments($this->selectedPlan);

            Flux::toast('Payment date adjusted successfully.');
        } catch (\Exception $e) {
            Log::error('Failed to adjust payment plan date', [
                'plan_id' => $planId,
                'error' => $e->getMessage(),
            ]);
            Flux::toast('Failed to adjust date: '.$e->getMessage(), variant: 'danger');
        }
    }

    /**
     * Format plan data for Alpine display inside the modal.
     *
     * @return array<string, mixed>
     */
    protected function formatPlanDetails(?PaymentPlan $plan): array
    {
        if (! $plan) {
            return [];
        }

        return [
            'id' => $plan->id,
            'plan_id' => $plan->plan_id ?? '-',
            'total_amount' => number_format($plan->total_amount, 2),
            'invoice_amount' => number_format($plan->invoice_amount ?? 0, 2),
            'plan_fee' => number_format($plan->plan_fee ?? 0, 2),
            'monthly_payment' => number_format($plan->monthly_payment, 2),
            'duration_months' => $plan->duration_months ?? '-',
            'amount_paid' => number_format($plan->amount_paid ?? 0, 2),
            'amount_remaining' => number_format($plan->amount_remaining ?? 0, 2),
            'status' => $plan->status,
            'can_cancel' => in_array($plan->status, ['active', 'past_due']),
            'can_skip' => $plan->canSkipPayment(),
            'skips_used' => $plan->skips_used,
            'max_skips' => PaymentPlan::MAX_SKIPS,
            'next_payment_date' => $plan->next_payment_date?->toIso8601String(),
            'has_next_payment' => $plan->next_payment_date !== null && in_array($plan->status, ['active', 'past_due']),
        ];
    }

    /**
     * Format plan payments for Alpine x-for display.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function formatPlanPayments(?PaymentPlan $plan): array
    {
        if (! $plan || ! $plan->payments) {
            return [];
        }

        return $plan->payments->map(fn ($payment) => [
            'payment_number' => $payment->payment_number,
            'amount' => number_format($payment->amount, 2),
            'scheduled_date' => $payment->scheduled_date?->toIso8601String(),
            'status' => $payment->status,
        ])->toArray();
    }

    /**
     * Get filtered payment plans.
     */
    public function getPlans()
    {
        $query = PaymentPlan::query();

        // Search by plan ID or client name (stored in metadata)
        if ($this->search) {
            $query->where(function ($q) {
                $q->where('plan_id', 'like', "%{$this->search}%")
                    ->orWhere('metadata', 'like', "%{$this->search}%");
            });
        }

        // Filter by status
        if ($this->status) {
            $query->where('status', $this->status);
        }

        return $query->orderBy('created_at', 'desc')->paginate(20);
    }

    /**
     * Fetch live client names from PracticeCS for the given plans.
     *
     * @param  \Illuminate\Contracts\Pagination\LengthAwarePaginator  $plans
     * @return array<string, string> Map of client_id => client_name
     */
    protected function getClientNames($plans): array
    {
        $clientIds = collect($plans->items())->pluck('client_id')->unique()->filter()->values()->toArray();

        if (empty($clientIds)) {
            return [];
        }

        try {
            return $this->paymentRepo->getClientNames($clientIds);
        } catch (\Exception $e) {
            Log::warning('Failed to fetch live client names from PracticeCS for payment plans', [
                'error' => $e->getMessage(),
            ]);

            return [];
        }
    }

    public function render()
    {
        $plans = $this->getPlans();
        $clientNames = $this->getClientNames($plans);

        return view('livewire.admin.payment-plans.index', [
            'plans' => $plans,
            'clientNames' => $clientNames,
        ]);
    }
}
