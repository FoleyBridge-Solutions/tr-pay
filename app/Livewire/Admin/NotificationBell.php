<?php

namespace App\Livewire\Admin;

use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * Notification Bell Component
 *
 * Displays a bell icon with unread notification count in the sidebar.
 * Shows a dropdown with the latest unread notifications and quick actions.
 */
class NotificationBell extends Component
{
    /**
     * Get the count of unread notifications.
     */
    #[Computed]
    public function unreadCount(): int
    {
        return Auth::user()->unreadNotifications()->count();
    }

    /**
     * Get the latest unread notifications for the dropdown.
     *
     * @return \Illuminate\Database\Eloquent\Collection
     */
    #[Computed]
    public function latestNotifications()
    {
        return Auth::user()->unreadNotifications()->latest()->limit(5)->get();
    }

    /**
     * Mark a single notification as read.
     */
    public function markAsRead(string $notificationId): void
    {
        $notification = Auth::user()->notifications()->find($notificationId);

        if ($notification) {
            $notification->markAsRead();
        }
    }

    /**
     * Mark all notifications as read.
     */
    public function markAllAsRead(): void
    {
        Auth::user()->unreadNotifications->markAsRead();
    }

    public function render()
    {
        return view('livewire.admin.notification-bell');
    }
}
