<?php

namespace App\Mail;

use App\Models\PaymentRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Payment Request Email
 *
 * Sent to a client with a tokenized link to pay a specific amount.
 */
class PaymentRequestMail extends Mailable
{
    use Queueable, SerializesModels;

    public PaymentRequest $paymentRequest;

    /**
     * Create a new message instance.
     */
    public function __construct(PaymentRequest $paymentRequest)
    {
        $this->paymentRequest = $paymentRequest;
    }

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Payment Request - '.config('branding.company_name', config('app.name')).' - $'.number_format($this->paymentRequest->amount, 2),
        );
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        return new Content(
            view: 'emails.payment-request',
            with: [
                'paymentRequest' => $this->paymentRequest,
                'paymentUrl' => $this->paymentRequest->payment_url,
                'companyName' => config('branding.company_name', config('app.name')),
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
