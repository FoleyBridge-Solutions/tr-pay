<?php

namespace App\Livewire\Admin\PaymentPlans;

use App\Models\AdminActivity;
use App\Models\PaymentPlan;
use Flux\Flux;
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

        // Search by plan ID
        if ($this->search) {
            $query->where('plan_id', 'like', "%{$this->search}%");
        }

        // Filter by status
        if ($this->status) {
            $query->where('status', $this->status);
        }

        return $query->orderBy('created_at', 'desc')->paginate(20);
    }

    public function render()
    {
        return view('livewire.admin.payment-plans.index', [
            'plans' => $this->getPlans(),
        ]);
    }
}
