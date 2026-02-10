<?php

// app/Mail/PaymentMethodDeleted.php

namespace App\Mail;

use App\Models\Customer;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * PaymentMethodDeleted Mailable
 *
 * Sent to customers when a payment method is removed from their account.
 * Serves as a security notification.
 */
class PaymentMethodDeleted extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * Create a new message instance.
     *
     * @param  Customer  $customer  The customer
     * @param  array  $methodInfo  Method info (type, last_four, display_name)
     */
    public function __construct(
        public Customer $customer,
        public array $methodInfo
    ) {}

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Payment Method Removed from Your Account',
        );
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        return new Content(
            view: 'emails.payment-method-deleted',
            with: [
                'customerName' => $this->customer->name,
                'methodType' => ($this->methodInfo['type'] ?? 'card') === 'card' ? 'credit card' : 'bank account',
                'displayName' => $this->methodInfo['display_name'] ?? 'Payment method',
                'lastFour' => $this->methodInfo['last_four'] ?? '****',
                'dateRemoved' => now()->format('F j, Y \a\t g:i A'),
            ],
        );
    }

    /**
     * Get the attachments for the message.
     *
     * @return array<int, \Illuminate\Mail\Mailables\Attachment>
     */
    public function attachments(): array
    {
        return [];
    }
}
