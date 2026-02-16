<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Notification sent when an ACH return is detected.
 *
 * Post-settlement returns are critical because money was already
 * credited and is now being reversed.
 */
class AchReturnDetected extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * Create a new notification instance.
     *
     * @param string $transactionId The payment transaction ID
     * @param string $clientName The client/customer name
     * @param string $clientId The client ID
     * @param float $amount The payment amount
     * @param string $returnCode The ACH return code (e.g., R01, R02)
     * @param string $returnReason The return reason description
     * @param bool $isPostSettlement Whether this is a post-settlement return
     */
    public function __construct(
        public string $transactionId,
        public string $clientName,
        public string $clientId,
        public float $amount,
        public string $returnCode,
        public string $returnReason,
        public bool $isPostSettlement = false
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
        $severity = $this->isPostSettlement ? 'CRITICAL' : 'Warning';
        $stage = $this->isPostSettlement ? 'post-settlement (money reversal)' : 'pre-settlement';

        return (new MailMessage)
            ->subject("[{$appName}] [{$severity}] ACH Return - {$this->clientName} ({$this->returnCode})")
            ->error()
            ->greeting("ACH Return Detected ({$stage})")
            ->line("An ACH payment has been returned for **{$this->clientName}**.")
            ->line('**Return Details:**')
            ->line("- Transaction ID: {$this->transactionId}")
            ->line("- Client: {$this->clientName} ({$this->clientId})")
            ->line("- Amount: \$".number_format($this->amount, 2))
            ->line("- Return Code: {$this->returnCode}")
            ->line("- Reason: {$this->returnReason}")
            ->line("- Stage: {$stage}")
            ->action('View ACH Returns', route('admin.ach.returns.index'))
            ->line('')
            ->line($this->isPostSettlement
                ? 'This is a post-settlement return. The funds have been reversed. Immediate review required.'
                : 'This is a pre-settlement rejection. The payment was not funded.')
            ->salutation("- {$appName} System");
    }

    /**
     * Get the array representation of the notification.
     *
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        $stage = $this->isPostSettlement ? 'Post-settlement' : 'Pre-settlement';

        return [
            'title' => "ACH Return ({$this->returnCode})",
            'message' => "{$stage} ACH return for {$this->clientName} ({$this->clientId}) - \$".number_format($this->amount, 2).": {$this->returnReason}",
            'severity' => $this->isPostSettlement ? 'critical' : 'warning',
            'category' => 'ach',
            'transaction_id' => $this->transactionId,
            'client_name' => $this->clientName,
            'client_id' => $this->clientId,
            'amount' => $this->amount,
            'return_code' => $this->returnCode,
            'return_reason' => $this->returnReason,
            'is_post_settlement' => $this->isPostSettlement,
            'action_url' => route('admin.ach.returns.index'),
            'action_label' => 'View ACH Returns',
        ];
    }
}
