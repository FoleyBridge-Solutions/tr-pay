<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Notification sent when a PracticeCS payment write fails.
 *
 * This is critical because the customer has been charged but the
 * payment was NOT recorded in the accounting system.
 */
class PracticeCsWriteFailed extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * Create a new notification instance.
     *
     * @param  string  $transactionId  The payment transaction ID
     * @param  string  $clientId  The client ID
     * @param  float  $amount  The payment amount
     * @param  string  $error  The error message
     * @param  string  $context  Additional context (e.g., 'recurring', 'plan_installment', 'single')
     */
    public function __construct(
        public string $transactionId,
        public string $clientId,
        public float $amount,
        public string $error,
        public string $context = 'payment'
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

        return (new MailMessage)
            ->subject("[{$appName}] PracticeCS Write Failed - Client {$this->clientId}")
            ->error()
            ->greeting('PracticeCS Payment Write Failed')
            ->line('A payment was charged but **failed to write to PracticeCS**. Manual intervention required.')
            ->line('**Payment Details:**')
            ->line("- Transaction ID: {$this->transactionId}")
            ->line("- Client ID: {$this->clientId}")
            ->line('- Amount: $'.number_format($this->amount, 2))
            ->line("- Context: {$this->context}")
            ->line('')
            ->line('**Error:**')
            ->line($this->error)
            ->action('View Payments', route('admin.payments'))
            ->line('')
            ->line('This payment must be manually entered in PracticeCS.')
            ->salutation("- {$appName} System");
    }

    /**
     * Get the array representation of the notification.
     *
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'title' => 'PracticeCS Write Failed',
            'message' => "Payment \${$this->transactionId} for client {$this->clientId} (\$".number_format($this->amount, 2).") failed to write to PracticeCS: {$this->error}",
            'severity' => 'critical',
            'category' => 'practicecs',
            'transaction_id' => $this->transactionId,
            'client_id' => $this->clientId,
            'amount' => $this->amount,
            'error' => $this->error,
            'context' => $this->context,
            'action_url' => route('admin.payments'),
            'action_label' => 'View Payments',
        ];
    }
}
