<?php

namespace App\Notifications;

use App\Models\PaymentPlan;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Notification sent when a scheduled payment plan payment fails.
 */
class PaymentPlanPaymentFailed extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * Create a new notification instance.
     */
    public function __construct(
        public PaymentPlan $paymentPlan,
        public string $errorMessage,
        public int $paymentNumber
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
        $clientName = $this->paymentPlan->metadata['client_name'] ?? 'Unknown Client';

        return (new MailMessage)
            ->subject("[{$appName}] Payment Plan Payment Failed - {$clientName}")
            ->error()
            ->greeting('Payment Plan Payment Failed')
            ->line("A scheduled payment failed for plan **{$this->paymentPlan->plan_id}**.")
            ->line('**Payment Details:**')
            ->line("- Client: {$clientName}")
            ->line("- Plan ID: {$this->paymentPlan->plan_id}")
            ->line("- Payment #{$this->paymentNumber} of {$this->paymentPlan->duration_months}")
            ->line("- Amount: \${$this->paymentPlan->monthly_payment}")
            ->line('- Payment Method: '.ucfirst($this->paymentPlan->payment_method_type)." ending in {$this->paymentPlan->payment_method_last_four}")
            ->line("- Consecutive Failures: {$this->paymentPlan->payments_failed}")
            ->line('')
            ->line('**Error:**')
            ->line($this->errorMessage)
            ->action('View Payment Plans', route('admin.payment-plans'))
            ->line('')
            ->line('Please review this payment plan and take appropriate action.')
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
            'payment_plan_id' => $this->paymentPlan->id,
            'plan_id' => $this->paymentPlan->plan_id,
            'payment_number' => $this->paymentNumber,
            'amount' => $this->paymentPlan->monthly_payment,
            'error' => $this->errorMessage,
        ];
    }
}
