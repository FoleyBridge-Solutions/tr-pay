<?php

// app/Livewire/Admin/Dashboard/StatsGrid.php

namespace App\Livewire\Admin\Dashboard;

use App\Models\Payment;
use App\Models\PaymentPlan;
use Livewire\Attributes\Lazy;
use Livewire\Component;

/**
 * Lazy-loaded dashboard statistics grid.
 *
 * Displays payment counts, amounts, active plans, and due/failed metrics.
 */
#[Lazy]
class StatsGrid extends Component
{
    /**
     * Get dashboard statistics.
     *
     * @return array<string, int|float>
     */
    public function getStats(): array
    {
        $today = now()->startOfDay();
        $thisWeek = now()->startOfWeek();
        $thisMonth = now()->startOfMonth();

        return [
            'payments_today' => Payment::whereDate('created_at', $today)
                ->where('status', Payment::STATUS_COMPLETED)
                ->count(),
            'payments_today_amount' => Payment::whereDate('created_at', $today)
                ->where('status', Payment::STATUS_COMPLETED)
                ->sum('amount'),
            'payments_this_month' => Payment::where('created_at', '>=', $thisMonth)
                ->where('status', Payment::STATUS_COMPLETED)
                ->count(),
            'payments_this_month_amount' => Payment::where('created_at', '>=', $thisMonth)
                ->where('status', Payment::STATUS_COMPLETED)
                ->sum('amount'),
            'active_plans' => PaymentPlan::where('status', PaymentPlan::STATUS_ACTIVE)->count(),
            'past_due_plans' => PaymentPlan::where('status', PaymentPlan::STATUS_PAST_DUE)->count(),
            'payments_due_this_week' => Payment::where('status', Payment::STATUS_PENDING)
                ->whereNotNull('scheduled_date')
                ->whereBetween('scheduled_date', [$today, $thisWeek->copy()->endOfWeek()])
                ->count(),
            'failed_payments' => Payment::where('status', Payment::STATUS_FAILED)
                ->where('created_at', '>=', $thisMonth)
                ->count(),
        ];
    }

    /**
     * Skeleton placeholder shown while component loads.
     */
    public function placeholder(): string
    {
        return <<<'HTML'
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4 mb-8">
            @for ($i = 0; $i < 4; $i++)
                <flux:card class="p-4">
                    <div class="flex items-center justify-between">
                        <div class="space-y-2 flex-1">
                            <flux:skeleton.line class="w-24" />
                            <flux:skeleton class="h-7 w-16 rounded" />
                            <flux:skeleton.line class="w-20" />
                        </div>
                        <flux:skeleton class="size-12 rounded-full" />
                    </div>
                </flux:card>
            @endfor
        </div>
        HTML;
    }

    public function render()
    {
        return view('livewire.admin.dashboard.stats-grid', [
            'stats' => $this->getStats(),
        ]);
    }
}
