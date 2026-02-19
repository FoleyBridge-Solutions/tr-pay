<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Notification sent to admin users when ACH payments have been bulk-voided
 * and need manual resubmission.
 *
 * This is a batch notification that summarizes multiple voided payments
 * in a single message, rather than sending individual notifications per payment.
 */
class VoidedPaymentsBatchNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * Create a new notification instance.
     *
     * @param  array<int, array{id: int, client_name: string, client_id: string, amount: float, description: string}>  $payments  Array of voided payment summaries
     * @param  string  $voidDate  The date the payments were voided (e.g., "2026-02-16")
     * @param  string  $reason  Reason for the void (e.g., "ACH batch failure")
     */
    public function __construct(
        public array $payments,
        public string $voidDate,
        public string $reason = 'ACH batch failure'
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
        $count = count($this->payments);
        $totalAmount = array_sum(array_column($this->payments, 'amount'));

        $mail = (new MailMessage)
            ->subject("[{$appName}] {$count} Voided ACH Payments Require Resubmission")
            ->error()
            ->greeting('Voided Payments — Action Required')
            ->line("{$count} ACH payments were bulk-voided on {$this->voidDate} due to: **{$this->reason}**.")
            ->line('These payments need to be manually resubmitted. No saved payment methods exist for these customers, so bank account details must be re-collected.')
            ->line('')
            ->line('**Voided Payments:**');

        foreach ($this->payments as $payment) {
            $amount = number_format($payment['amount'], 2);
            $mail->line("- **{$payment['client_name']}** ({$payment['client_id']}) — \${$amount} — {$payment['description']}");
        }

        $mail->line('')
            ->line('**Total:** $'.number_format($totalAmount, 2))
            ->line('')
            ->line('**Next Steps:**')
            ->line('1. Contact each client to collect bank account details')
            ->line('2. Resubmit payments through the admin payment flow')
            ->line('3. For payment plan clients (IDs 293, 298), the down payment must be re-collected before installments begin')
            ->action('View Payments', route('admin.payments'))
            ->salutation("- {$appName} System");

        return $mail;
    }

    /**
     * Get the array representation of the notification.
     *
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        $count = count($this->payments);
        $totalAmount = array_sum(array_column($this->payments, 'amount'));

        return [
            'title' => "Voided ACH Payments — {$count} Require Resubmission",
            'message' => "{$count} ACH payments totaling \$".number_format($totalAmount, 2)." were voided on {$this->voidDate} and need manual resubmission. Bank details must be re-collected.",
            'severity' => 'warning',
            'category' => 'payment',
            'void_date' => $this->voidDate,
            'reason' => $this->reason,
            'payment_count' => $count,
            'total_amount' => $totalAmount,
            'payments' => $this->payments,
        ];
    }
}
