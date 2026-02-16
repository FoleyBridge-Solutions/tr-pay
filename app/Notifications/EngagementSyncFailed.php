<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Notification sent when a PracticeCS engagement type update fails.
 *
 * The customer paid and accepted the engagement, but the engagement
 * type was not updated in PracticeCS.
 */
class EngagementSyncFailed extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * Create a new notification instance.
     *
     * @param  string  $clientName  The client name
     * @param  string  $clientId  The client ID
     * @param  string  $error  The error message
     * @param  string|null  $transactionId  The related payment transaction ID
     */
    public function __construct(
        public string $clientName,
        public string $clientId,
        public string $error,
        public ?string $transactionId = null
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
            ->subject("[{$appName}] Engagement Sync Failed - {$this->clientName}")
            ->error()
            ->greeting('Engagement Type Update Failed')
            ->line("An engagement type failed to update in PracticeCS for **{$this->clientName}**.")
            ->line('**Details:**')
            ->line("- Client: {$this->clientName} ({$this->clientId})")
            ->when($this->transactionId, fn ($mail) => $mail->line("- Transaction ID: {$this->transactionId}"))
            ->line('')
            ->line('**Error:**')
            ->line($this->error)
            ->action('View Clients', route('admin.clients'))
            ->line('')
            ->line('The engagement type must be manually updated in PracticeCS.')
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
            'title' => 'Engagement Sync Failed',
            'message' => "Engagement type update failed for {$this->clientName} ({$this->clientId}): {$this->error}",
            'severity' => 'warning',
            'category' => 'practicecs',
            'client_name' => $this->clientName,
            'client_id' => $this->clientId,
            'error' => $this->error,
            'transaction_id' => $this->transactionId,
            'action_url' => route('admin.clients'),
            'action_label' => 'View Clients',
        ];
    }
}
