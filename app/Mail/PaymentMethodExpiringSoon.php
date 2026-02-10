<?php

// app/Mail/PaymentMethodExpiringSoon.php

namespace App\Mail;

use App\Models\Customer;
use App\Models\CustomerPaymentMethod;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * PaymentMethodExpiringSoon Mailable
 *
 * Sent to customers when their credit card is expiring soon (within 30 days).
 * Helps prevent failed payments on recurring charges.
 */
class PaymentMethodExpiringSoon extends Mailable
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
            subject: 'Your Payment Method is Expiring Soon',
        );
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        return new Content(
            view: 'emails.payment-method-expiring',
            with: [
                'customerName' => $this->customer->name,
                'displayName' => $this->paymentMethod->display_name,
                'lastFour' => $this->paymentMethod->last_four,
                'expirationDate' => $this->paymentMethod->expiration_display,
                'brand' => $this->paymentMethod->brand,
                'hasLinkedPlans' => $this->paymentMethod->isLinkedToActivePlans(),
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
