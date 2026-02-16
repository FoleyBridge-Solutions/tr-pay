<?php

namespace App\Livewire\Admin;

use App\Models\Payment;
use App\Models\PaymentPlan;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Admin Dashboard Component
 *
 * Displays overview statistics, active alerts, and recent activity.
 */
#[Layout('layouts::admin')]
class Dashboard extends Component
{
    /**
     * Get dashboard statistics.
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
     * Get unread notifications for the alerts section.
     *
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function getAlerts()
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
     * Get recent payments.
     *
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function getRecentPayments()
    {
        return Payment::with('paymentPlan')
            ->orderBy('created_at', 'desc')
            ->limit(10)
            ->get();
    }

    /**
     * Get recent payment plans.
     *
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function getRecentPlans()
    {
        return PaymentPlan::orderBy('created_at', 'desc')
            ->limit(5)
            ->get();
    }

    public function render()
    {
        return view('livewire.admin.dashboard', [
            'stats' => $this->getStats(),
            'alerts' => $this->getAlerts(),
            'recentPayments' => $this->getRecentPayments(),
            'recentPlans' => $this->getRecentPlans(),
        ]);
    }
}
