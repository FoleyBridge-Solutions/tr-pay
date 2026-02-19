<?php

// app/Livewire/Admin/Dashboard/RecentPlans.php

namespace App\Livewire\Admin\Dashboard;

use App\Models\PaymentPlan;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Lazy;
use Livewire\Component;

/**
 * Lazy-loaded recent payment plans table.
 *
 * Displays the 5 most recent payment plans with status and progress.
 */
#[Lazy]
class RecentPlans extends Component
{
    /**
     * Get recent payment plans.
     */
    public function getRecentPlans(): Collection
    {
        return PaymentPlan::orderBy('created_at', 'desc')
            ->limit(5)
            ->get();
    }

    /**
     * Skeleton placeholder shown while component loads.
     */
    public function placeholder(): string
    {
        return <<<'HTML'
        <div>
            <div class="flex items-center justify-between mb-4">
                <flux:skeleton class="h-6 w-48 rounded" />
                <flux:skeleton class="h-8 w-20 rounded" />
            </div>
            <flux:card>
                <flux:skeleton.group animate="shimmer">
                    <div class="divide-y divide-zinc-200 dark:divide-zinc-700">
                        <div class="px-4 py-3 flex items-center gap-4">
                            <flux:skeleton.line class="w-20" />
                            <flux:skeleton.line class="w-16" />
                            <flux:skeleton.line class="w-12" />
                        </div>
                        @for ($i = 0; $i < 5; $i++)
                            <div class="px-4 py-3 flex items-center gap-4">
                                <div class="space-y-1">
                                    <flux:skeleton.line class="w-20" />
                                    <flux:skeleton.line class="w-16" />
                                </div>
                                <flux:skeleton class="h-5 w-16 rounded-full" />
                                <flux:skeleton.line class="w-10" />
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
        return view('livewire.admin.dashboard.recent-plans', [
            'recentPlans' => $this->getRecentPlans(),
        ]);
    }
}
