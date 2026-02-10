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
#[Layout('layouts.admin')]
class Index extends Component
{
    use WithPagination;

    #[Url(as: 'q')]
    public string $search = '';

    #[Url]
    public string $status = '';

    public ?PaymentPlan $selectedPlan = null;

    public bool $showDetails = false;

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
        $this->showDetails = true;
    }

    /**
     * Close details modal.
     */
    public function closeDetails(): void
    {
        $this->showDetails = false;
        $this->selectedPlan = null;
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
