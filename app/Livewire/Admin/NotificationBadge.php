<?php

namespace App\Livewire\Admin;

use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * Sidebar Notification Badge Component
 *
 * Lightweight polling component that shows the unread notification count
 * inside the sidebar profile dropdown. Polls every 30 seconds.
 */
class NotificationBadge extends Component
{
    /**
     * Get the count of unread notifications.
     */
    #[Computed]
    public function unreadCount(): int
    {
        return Auth::user()->unreadNotifications()->count();
    }

    public function render()
    {
        return view('livewire.admin.notification-badge');
    }
}
