<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Notification sent when a payment method is expiring soon
 * and is linked to active plans or recurring payments.
 */
class PaymentMethodExpiring extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * Create a new notification instance.
     *
     * @param  string  $clientName  The client/customer name
     * @param  string  $clientId  The client ID
     * @param  string  $lastFour  Last four digits of the card
     * @param  string  $brand  Card brand (e.g., Visa, Mastercard)
     * @param  string  $expirationDate  The expiration date (e.g., 03/2026)
     * @param  int  $activeLinksCount  Number of active plans/recurring payments linked
     */
    public function __construct(
        public string $clientName,
        public string $clientId,
        public string $lastFour,
        public string $brand,
        public string $expirationDate,
        public int $activeLinksCount = 0
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
            ->subject("[{$appName}] Payment Method Expiring - {$this->clientName}")
            ->greeting('Payment Method Expiring Soon')
            ->line("A payment method is expiring soon for **{$this->clientName}** and is linked to active automated payments.")
            ->line('**Payment Method:**')
            ->line("- Card: {$this->brand} ending in {$this->lastFour}")
            ->line("- Expires: {$this->expirationDate}")
            ->line("- Active plans/recurring payments linked: {$this->activeLinksCount}")
            ->action('View Client', route('admin.clients'))
            ->line('')
            ->line('Please contact the client to update their payment method before it expires.')
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
            'title' => 'Payment Method Expiring',
            'message' => "{$this->brand} ending in {$this->lastFour} for {$this->clientName} ({$this->clientId}) expires {$this->expirationDate}. Linked to {$this->activeLinksCount} active payment(s).",
            'severity' => 'info',
            'category' => 'payment_method',
            'client_name' => $this->clientName,
            'client_id' => $this->clientId,
            'last_four' => $this->lastFour,
            'brand' => $this->brand,
            'expiration_date' => $this->expirationDate,
            'active_links_count' => $this->activeLinksCount,
            'action_url' => route('admin.clients'),
            'action_label' => 'View Client',
        ];
    }
}
