<?php

// app/Mail/PaymentMethodSaved.php

namespace App\Mail;

use App\Models\Customer;
use App\Models\CustomerPaymentMethod;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * PaymentMethodSaved Mailable
 *
 * Sent to customers when a new payment method is saved to their account.
 * Serves as a security notification.
 */
class PaymentMethodSaved extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * Create a new message instance.
     */
    public function __construct(
        public Customer $customer,
        public CustomerPaymentMethod $paymentMethod
    ) {}

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'New Payment Method Added to Your Account',
        );
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        return new Content(
            view: 'emails.payment-method-saved',
            with: [
                'customerName' => $this->customer->name,
                'methodType' => $this->paymentMethod->type === 'card' ? 'credit card' : 'bank account',
                'displayName' => $this->paymentMethod->display_name,
                'lastFour' => $this->paymentMethod->last_four,
                'dateAdded' => now()->format('F j, Y \a\t g:i A'),
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
