<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Notification sent when an automated payment charge fails.
 *
 * Covers scheduled plan payments, scheduled single payments,
 * and recurring payments that fail during automated processing.
 */
class PaymentFailed extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * Create a new notification instance.
     *
     * @param  string  $clientName  The client/customer name
     * @param  string  $clientId  The client ID
     * @param  float  $amount  The payment amount
     * @param  string  $error  The error message
     * @param  string  $paymentType  Type of payment (recurring, plan_installment, scheduled)
     * @param  int|null  $paymentId  The payment record ID
     */
    public function __construct(
        public string $clientName,
        public string $clientId,
        public float $amount,
        public string $error,
        public string $paymentType = 'payment',
        public ?int $paymentId = null
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
        $typeLabel = match ($this->paymentType) {
            'recurring' => 'Recurring Payment',
            'plan_installment' => 'Plan Installment',
            'scheduled' => 'Scheduled Payment',
            default => 'Payment',
        };

        return (new MailMessage)
            ->subject("[{$appName}] {$typeLabel} Failed - {$this->clientName}")
            ->error()
            ->greeting("{$typeLabel} Failed")
            ->line("An automated payment failed to process for **{$this->clientName}**.")
            ->line('**Payment Details:**')
            ->line("- Client: {$this->clientName} ({$this->clientId})")
            ->line('- Amount: $'.number_format($this->amount, 2))
            ->line("- Type: {$typeLabel}")
            ->line('')
            ->line('**Error:**')
            ->line($this->error)
            ->action('View Payments', route('admin.payments'))
            ->line('')
            ->line('Please review and take appropriate action.')
            ->salutation("- {$appName} System");
    }

    /**
     * Get the array representation of the notification.
     *
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        $typeLabel = match ($this->paymentType) {
            'recurring' => 'Recurring payment',
            'plan_installment' => 'Plan installment',
            'scheduled' => 'Scheduled payment',
            default => 'Payment',
        };

        return [
            'title' => "{$typeLabel} Failed",
            'message' => "{$typeLabel} of \$".number_format($this->amount, 2)." for {$this->clientName} ({$this->clientId}) failed: {$this->error}",
            'severity' => 'warning',
            'category' => 'payment',
            'client_name' => $this->clientName,
            'client_id' => $this->clientId,
            'amount' => $this->amount,
            'error' => $this->error,
            'payment_type' => $this->paymentType,
            'payment_id' => $this->paymentId,
            'action_url' => route('admin.payments'),
            'action_label' => 'View Payments',
        ];
    }
}
