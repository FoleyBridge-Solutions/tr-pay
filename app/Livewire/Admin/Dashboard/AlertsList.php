<?php

// app/Livewire/Admin/Dashboard/AlertsList.php

namespace App\Livewire\Admin\Dashboard;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Lazy;
use Livewire\Component;

/**
 * Lazy-loaded dashboard alerts list.
 *
 * Displays unread notifications with dismiss functionality.
 */
#[Lazy]
class AlertsList extends Component
{
    /**
     * Get unread notifications for the alerts section.
     */
    public function getAlerts(): Collection
    {
        return Auth::user()->unreadNotifications()->latest()->limit(5)->get();
    }

    /**
     * Mark a notification as read from the dashboard.
     */
    public function dismissAlert(string $notificationId): void
    {
        $notification = Auth::user()->notifications()->find($notificationId);

        if ($notification) {
            $notification->markAsRead();
        }
    }

    /**
     * Skeleton placeholder shown while component loads.
     */
    public function placeholder(): string
    {
        return <<<'HTML'
        <div class="mb-8">
            <div class="flex items-center justify-between mb-4">
                <flux:skeleton class="h-6 w-32 rounded" />
                <flux:skeleton class="h-8 w-20 rounded" />
            </div>
            <flux:skeleton.group animate="shimmer">
                <div class="space-y-3">
                    @for ($i = 0; $i < 2; $i++)
                        <div class="border-l-4 border-zinc-200 dark:border-zinc-700 bg-zinc-50 dark:bg-zinc-800/30 rounded-r-lg px-4 py-3 flex items-start gap-3">
                            <flux:skeleton class="size-5 rounded-full shrink-0 mt-0.5" />
                            <div class="flex-1 space-y-2">
                                <flux:skeleton.line class="w-1/3" />
                                <flux:skeleton.line class="w-2/3" />
                                <flux:skeleton.line class="w-24" />
                            </div>
                        </div>
                    @endfor
                </div>
            </flux:skeleton.group>
        </div>
        HTML;
    }

    public function render()
    {
        return view('livewire.admin.dashboard.alerts-list', [
            'alerts' => $this->getAlerts(),
        ]);
    }
}
