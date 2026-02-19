<?php

// app/Livewire/Admin/Notifications/NotificationsList.php

namespace App\Livewire\Admin\Notifications;

use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Lazy;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Lazy-loaded notifications list.
 *
 * Displays filtered notification cards with bulk actions and pagination.
 * Uses left-border colored cards styled by severity level.
 */
#[Lazy]
class NotificationsList extends Component
{
    use WithPagination;

    #[Url(as: 'category')]
    public string $filterCategory = '';

    #[Url(as: 'severity')]
    public string $filterSeverity = '';

    #[Url(as: 'status')]
    public string $filterStatus = '';

    /**
     * Reset pagination when filters change.
     */
    public function updatedFilterCategory(): void
    {
        $this->resetPage();
    }

    /**
     * Reset pagination when severity filter changes.
     */
    public function updatedFilterSeverity(): void
    {
        $this->resetPage();
    }

    /**
     * Reset pagination when status filter changes.
     */
    public function updatedFilterStatus(): void
    {
        $this->resetPage();
    }

    /**
     * Clear all filters.
     */
    public function clearFilters(): void
    {
        $this->reset(['filterCategory', 'filterSeverity', 'filterStatus']);
        $this->resetPage();
    }

    /**
     * Mark a single notification as read.
     */
    public function markAsRead(string $notificationId): void
    {
        $notification = Auth::user()->notifications()->find($notificationId);

        if ($notification && ! $notification->read_at) {
            $notification->markAsRead();
        }
    }

    /**
     * Mark all visible notifications as read.
     */
    public function markAllAsRead(): void
    {
        Auth::user()->unreadNotifications->markAsRead();
    }

    /**
     * Delete a single notification.
     */
    public function deleteNotification(string $notificationId): void
    {
        Auth::user()->notifications()->where('id', $notificationId)->delete();
    }

    /**
     * Delete all read notifications.
     */
    public function deleteAllRead(): void
    {
        Auth::user()->readNotifications()->delete();
    }

    /**
     * Get filtered notifications with pagination.
     *
     * @return \Illuminate\Contracts\Pagination\LengthAwarePaginator
     */
    public function getNotifications(): mixed
    {
        $query = Auth::user()->notifications()->latest();

        if ($this->filterStatus === 'unread') {
            $query->whereNull('read_at');
        } elseif ($this->filterStatus === 'read') {
            $query->whereNotNull('read_at');
        }

        if ($this->filterCategory) {
            $query->whereJsonContains('data->category', $this->filterCategory);
        }

        if ($this->filterSeverity) {
            $query->whereJsonContains('data->severity', $this->filterSeverity);
        }

        return $query->paginate(25);
    }

    /**
     * Get available categories for filter dropdown.
     *
     * @return array<string, string>
     */
    public function getCategories(): array
    {
        return [
            'practicecs' => 'PracticeCS',
            'payment' => 'Payment',
            'ach' => 'ACH',
            'plan' => 'Payment Plan',
            'payment_method' => 'Payment Method',
        ];
    }

    /**
     * Get available severities for filter dropdown.
     *
     * @return array<string, string>
     */
    public function getSeverities(): array
    {
        return [
            'critical' => 'Critical',
            'warning' => 'Warning',
            'info' => 'Info',
        ];
    }

    /**
     * Skeleton placeholder shown while component loads.
     *
     * Renders shimmer filter skeletons and card-shaped notification skeletons
     * with left border styling to match the notification card design.
     */
    public function placeholder(): string
    {
        return <<<'HTML'
        <div>
            {{-- Filter skeletons --}}
            <div class="mb-6 flex flex-wrap items-end gap-4">
                <flux:skeleton.group animate="shimmer">
                    <div class="space-y-1">
                        <flux:skeleton class="h-4 w-12 rounded" />
                        <flux:skeleton class="h-9 w-40 rounded-lg" />
                    </div>
                    <div class="space-y-1">
                        <flux:skeleton class="h-4 w-16 rounded" />
                        <flux:skeleton class="h-9 w-44 rounded-lg" />
                    </div>
                    <div class="space-y-1">
                        <flux:skeleton class="h-4 w-14 rounded" />
                        <flux:skeleton class="h-9 w-40 rounded-lg" />
                    </div>
                    <div class="ml-auto flex gap-2">
                        <flux:skeleton class="h-8 w-28 rounded-lg" />
                        <flux:skeleton class="h-8 w-24 rounded-lg" />
                    </div>
                </flux:skeleton.group>
            </div>

            {{-- Notification card skeletons --}}
            <flux:card>
                <flux:skeleton.group animate="shimmer">
                    @for ($i = 0; $i < 5; $i++)
                        <div class="border-l-4 {{ ['border-l-red-500', 'border-l-amber-500', 'border-l-blue-500', 'border-l-blue-500', 'border-l-amber-500'][$i] }} px-4 py-3 {{ $i < 4 ? 'border-b border-zinc-200 dark:border-zinc-700' : '' }}">
                            <div class="flex items-start justify-between gap-4">
                                <div class="min-w-0 flex-1 space-y-2">
                                    <div class="flex items-center gap-2">
                                        <flux:skeleton class="w-2 h-2 rounded-full" />
                                        <flux:skeleton.line class="w-48" />
                                        <flux:skeleton class="h-5 w-14 rounded-full" />
                                        <flux:skeleton class="h-5 w-16 rounded-full" />
                                    </div>
                                    <flux:skeleton.line class="w-3/4" />
                                    <div class="flex items-center gap-4">
                                        <flux:skeleton.line class="w-36" />
                                    </div>
                                </div>
                                <div class="flex items-center gap-1 shrink-0">
                                    <flux:skeleton class="size-8 rounded" />
                                    <flux:skeleton class="size-8 rounded" />
                                </div>
                            </div>
                        </div>
                    @endfor
                </flux:skeleton.group>
            </flux:card>
        </div>
        HTML;
    }

    public function render()
    {
        return view('livewire.admin.notifications.notifications-list', [
            'notifications' => $this->getNotifications(),
            'categories' => $this->getCategories(),
            'severities' => $this->getSeverities(),
        ]);
    }
}
