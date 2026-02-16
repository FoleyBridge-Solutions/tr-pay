<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Log;

/**
 * Service for sending notifications to all active admin users.
 *
 * Provides a centralized way to broadcast notifications to every
 * active user in the system. All notification sends are wrapped
 * in try/catch to prevent notification failures from affecting
 * the calling code's main flow.
 */
class AdminAlertService
{
    /**
     * Send a notification to all active admin users.
     *
     * Each user receives their own copy of the notification so they
     * can independently mark it as read or dismiss it.
     *
     * @param Notification $notification The notification instance to send
     */
    public static function notifyAll(Notification $notification): void
    {
        try {
            $users = User::where('is_active', true)->get();

            foreach ($users as $user) {
                try {
                    $user->notify($notification);
                } catch (\Exception $e) {
                    Log::error('Failed to send admin notification to user', [
                        'user_id' => $user->id,
                        'user_email' => $user->email,
                        'notification' => get_class($notification),
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        } catch (\Exception $e) {
            Log::error('Failed to query users for admin notification', [
                'notification' => get_class($notification),
                'error' => $e->getMessage(),
            ]);
        }
    }
}
