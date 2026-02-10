<?php

namespace Fbs\trpay\Service;

use PHPMailer\PHPMailer\Exception;
use PHPMailer\PHPMailer\PHPMailer;

class EmailService
{
    private $mailer;

    public function __construct(array $config = [])
    {
        $this->mailer = new PHPMailer(true);

        // Server settings
        $this->mailer->isSMTP();
        $this->mailer->Host = $config['host'] ?? 'smtp.example.com';
        $this->mailer->SMTPAuth = true;
        $this->mailer->Username = $config['username'] ?? 'user@example.com';
        $this->mailer->Password = $config['password'] ?? 'password';
        $this->mailer->SMTPSecure = $config['encryption'] ?? PHPMailer::ENCRYPTION_STARTTLS;
        $this->mailer->Port = $config['port'] ?? 587;

        // Default sender
        $this->mailer->setFrom($config['from_email'] ?? 'noreply@example.com', $config['from_name'] ?? 'Payment System');
    }

    /**
     * Send verification code email
     *
     * @param  string  $email  Recipient email address
     * @param  string  $code  Verification code
     * @param  array  $companyData  Optional company data to personalize the email
     * @return bool Whether the email was sent successfully
     */
    public function sendVerificationCode(string $email, string $code, array $companyData = []): bool
    {
        try {
            // Recipient
            $this->mailer->addAddress($email);

            // Content
            $this->mailer->isHTML(true);
            $this->mailer->Subject = 'Your Verification Code';

            // Personalize if we have company data
            $companyName = $companyData['name'] ?? 'Customer';

            $this->mailer->Body = "
                <h2>Verification Code</h2>
                <p>Hello {$companyName},</p>
                <p>Your verification code is: <strong>{$code}</strong></p>
                <p>This code will expire in 15 minutes.</p>
                <p>Thank you for using our payment system.</p>
            ";

            $this->mailer->AltBody = "Verification Code\n\nHello {$companyName},\n\nYour verification code is: {$code}\n\nThis code will expire in 15 minutes.\n\nThank you for using our payment system.";

            $this->mailer->send();

            return true;
        } catch (Exception $e) {
            error_log("Email could not be sent. Mailer Error: {$this->mailer->ErrorInfo}");

            return false;
        }
    }
}
