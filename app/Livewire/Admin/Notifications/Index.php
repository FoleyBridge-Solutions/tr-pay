<?php

namespace App\Livewire\Admin\Notifications;

use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Notifications Index Component
 *
 * Displays all notifications with filtering by category, severity, and read status.
 * Supports bulk actions (mark as read, delete).
 */
#[Layout('layouts::admin')]
class Index extends Component
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

    public function updatedFilterSeverity(): void
    {
        $this->resetPage();
    }

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
     */
    public function getNotifications()
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
     */
    public function getSeverities(): array
    {
        return [
            'critical' => 'Critical',
            'warning' => 'Warning',
            'info' => 'Info',
        ];
    }

    public function render()
    {
        return view('livewire.admin.notifications.index', [
            'notifications' => $this->getNotifications(),
            'categories' => $this->getCategories(),
            'severities' => $this->getSeverities(),
        ]);
    }
}
