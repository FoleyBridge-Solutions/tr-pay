<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Notification sent when a payment plan transitions to past_due or failed status.
 */
class PaymentPlanPastDue extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * Create a new notification instance.
     *
     * @param  string  $planId  The plan identifier (e.g., plan_xxxxxxxxx)
     * @param  string  $clientName  The client name
     * @param  string  $clientId  The client ID
     * @param  float  $monthlyPayment  The monthly payment amount
     * @param  string  $status  The new status (past_due or failed)
     * @param  int  $paymentsFailed  Number of consecutive failures
     */
    public function __construct(
        public string $planId,
        public string $clientName,
        public string $clientId,
        public float $monthlyPayment,
        public string $status,
        public int $paymentsFailed
    ) {}

    /**
     * Get the notification's delivery channels.
     *
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    /**
     * Get the mail representation of the notification.
     */
    public function toMail(object $notifiable): MailMessage
    {
        $appName = config('app.name');
        $statusLabel = $this->status === 'failed' ? 'Failed' : 'Past Due';

        return (new MailMessage)
            ->subject("[{$appName}] Payment Plan {$statusLabel} - {$this->clientName}")
            ->error()
            ->greeting("Payment Plan {$statusLabel}")
            ->line("A payment plan has transitioned to **{$statusLabel}** status.")
            ->line('**Plan Details:**')
            ->line("- Plan ID: {$this->planId}")
            ->line("- Client: {$this->clientName} ({$this->clientId})")
            ->line('- Monthly Payment: $'.number_format($this->monthlyPayment, 2))
            ->line("- Consecutive Failures: {$this->paymentsFailed}")
            ->action('View Payment Plans', route('admin.payment-plans'))
            ->line('')
            ->line('Please review this plan and contact the client if needed.')
            ->salutation("- {$appName} System");
    }

    /**
     * Get the array representation of the notification.
     *
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        $statusLabel = $this->status === 'failed' ? 'Failed' : 'Past Due';

        return [
            'title' => "Payment Plan {$statusLabel}",
            'message' => "Plan {$this->planId} for {$this->clientName} ({$this->clientId}) is now {$statusLabel}. \$".number_format($this->monthlyPayment, 2)."/mo, {$this->paymentsFailed} consecutive failures.",
            'severity' => $this->status === 'failed' ? 'critical' : 'warning',
            'category' => 'plan',
            'plan_id' => $this->planId,
            'client_name' => $this->clientName,
            'client_id' => $this->clientId,
            'monthly_payment' => $this->monthlyPayment,
            'status' => $this->status,
            'payments_failed' => $this->paymentsFailed,
            'action_url' => route('admin.payment-plans'),
            'action_label' => 'View Payment Plans',
        ];
    }
}
