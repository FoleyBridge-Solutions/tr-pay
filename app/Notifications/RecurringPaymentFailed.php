<?php

namespace App\Notifications;

use App\Models\RecurringPayment;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Notification sent when a recurring payment fails to process.
 */
class RecurringPaymentFailed extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * Create a new notification instance.
     */
    public function __construct(
        public RecurringPayment $recurringPayment,
        public string $errorMessage
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
            ->subject("[{$appName}] Recurring Payment Failed - {$this->recurringPayment->client_name}")
            ->error()
            ->greeting('Payment Processing Failed')
            ->line("A recurring payment failed to process for **{$this->recurringPayment->client_name}**.")
            ->line('**Payment Details:**')
            ->line("- Amount: \${$this->recurringPayment->amount}")
            ->line("- Frequency: {$this->recurringPayment->frequency_label}")
            ->line('- Payment Method: '.ucfirst($this->recurringPayment->payment_method_type)." ending in {$this->recurringPayment->payment_method_last_four}")
            ->line("- Consecutive Failures: {$this->recurringPayment->payments_failed}")
            ->line('')
            ->line('**Error:**')
            ->line($this->errorMessage)
            ->action('View in Admin Panel', route('admin.recurring-payments'))
            ->line('')
            ->line('Please review this payment and take appropriate action.')
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
            'recurring_payment_id' => $this->recurringPayment->id,
            'client_name' => $this->recurringPayment->client_name,
            'amount' => $this->recurringPayment->amount,
            'error' => $this->errorMessage,
        ];
    }
}
