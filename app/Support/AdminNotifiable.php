<?php

namespace App\Support;

use Illuminate\Notifications\Notifiable;

/**
 * A notifiable class for sending notifications to admin email addresses.
 *
 * Usage:
 *   $admin = new AdminNotifiable();
 *   $admin->notify(new SomeNotification());
 *
 * This will send to the ADMIN_EMAIL environment variable.
 */
class AdminNotifiable
{
    use Notifiable;

    /**
     * The email addresses to notify.
     *
     * @var array<string>
     */
    protected array $emails;

    /**
     * Create a new admin notifiable instance.
     *
     * @param  array<string>|string|null  $emails  Override email(s), defaults to ADMIN_EMAIL env
     */
    public function __construct(array|string|null $emails = null)
    {
        if ($emails === null) {
            $adminEmail = env('ADMIN_EMAIL');
            $this->emails = $adminEmail ? [$adminEmail] : [];
        } elseif (is_string($emails)) {
            $this->emails = [$emails];
        } else {
            $this->emails = $emails;
        }
    }

    /**
     * Route notifications for the mail channel.
     *
     * @return array<string>|string
     */
    public function routeNotificationForMail(): array|string
    {
        return count($this->emails) === 1 ? $this->emails[0] : $this->emails;
    }

    /**
     * Get the notifiable's unique key for the database notification channel.
     */
    public function getKey(): string
    {
        return 'admin';
    }

    /**
     * Check if admin notifications are configured.
     */
    public function isConfigured(): bool
    {
        return ! empty($this->emails);
    }
}
