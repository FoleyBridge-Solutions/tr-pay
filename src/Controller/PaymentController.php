<?php

namespace Fbs\trpay\Controller;

use Fbs\trpay\Model\PaymentModel;
use Fbs\trpay\Service\EmailService;

class PaymentController {
    private $paymentModel;
    private $emailService;

    public function __construct($pdo) {
        $this->paymentModel = new PaymentModel($pdo);
        
        // Load email configuration
        $emailConfig = require __DIR__ . '/../../config/email.php';
        $this->emailService = new EmailService($emailConfig);
    }

    public function handleStep(string $step, array $postData) {
        switch ($step) {
            case 'email_verification':
                return $this->verifyEmail($postData['email']);
            case 'verify_code':
                return $this->verifyCode($postData['verification_code']);
            case 'payment_information':
                return $this->savePaymentInfo($postData);
            case 'payment_method':
                return $this->savePaymentMethod($postData['payment_method']);
            case 'confirmation':
                return $this->confirmPayment();
            default:
                throw new \Exception("Invalid step: $step");
        }
    }

    private function verifyEmail(string $email) {
        // Get company data
        $company = $this->paymentModel->getCompanyByEmail($email);
        
        // Debug the result
        error_log("Company data in controller: " . ($company ? 'Found' : 'Not found'));
        
        // Only proceed if we have valid company data (non-null, non-false)
        if (!empty($company) && is_array($company)) {
            $_SESSION['company_info'] = $company;
            $_SESSION['verification_code'] = rand(100000, 999999);
            $_SESSION['code_expiry'] = time() + (15 * 60); // 15 minutes expiry
            
            // Send verification code email
            $emailSent = $this->emailService->sendVerificationCode($email, $_SESSION['verification_code'], $company);
            
            if (!$emailSent) {
                error_log("Failed to send verification email to: " . $email);
                return false;
            }
            
            error_log("Verification code {$_SESSION['verification_code']} sent to {$email}");
            return true;
        }
        
        error_log("Email verification failed for: " . $email);
        return false;
    }

    /**
     * Verify the provided verification code
     */
    private function verifyCode(string $code) {
        // Check if code has expired
        if (time() > ($_SESSION['code_expiry'] ?? 0)) {
            error_log("Verification code has expired");
            return false;
        }
        
        // Check if code matches
        if ($_SESSION['verification_code'] == $code) {
            // Check if we have multiple clients or a single client
            if (isset($_SESSION['company_info']['clients']) && is_array($_SESSION['company_info']['clients'])) {
                // Multiple clients case
                $_SESSION['open_invoices'] = [];
                $_SESSION['clients_data'] = [];
                $totalBalance = 0;
                
                foreach ($_SESSION['company_info']['clients'] as $clientData) {
                    $clientKey = $clientData['client_KEY'];
                    
                    // Get client balance
                    $balanceInfo = $this->paymentModel->getClientBalance($clientKey);
                    
                    // Store client data
                    $_SESSION['clients_data'][$clientKey] = [
                        'client_id' => $balanceInfo['client_id'],
                        'client_name' => $balanceInfo['client_name'],
                        'balance' => (float)$balanceInfo['balance']
                    ];
                    
                    $totalBalance += (float)$balanceInfo['balance'];
                    
                    // Get open invoices for this client
                    try {
                        $clientInvoices = $this->paymentModel->getClientOpenInvoices($clientKey);
                        // Add client info to each invoice
                        foreach ($clientInvoices as &$invoice) {
                            $invoice['client_name'] = $balanceInfo['client_name'];
                            $invoice['client_id'] = $balanceInfo['client_id'];
                            $invoice['client_KEY'] = $clientKey;
                        }
                        $_SESSION['open_invoices'] = array_merge($_SESSION['open_invoices'], $clientInvoices);
                        error_log("Found " . count($clientInvoices) . " open invoices for client " . $balanceInfo['client_id']);
                    } catch (\Exception $e) {
                        error_log("Error fetching invoices for client {$clientKey}: " . $e->getMessage());
                    }
                }
                
                // Set total balance as payment amount
                $_SESSION['payment_amount'] = number_format($totalBalance, 2, '.', '');
                $_SESSION['has_multiple_clients'] = true;
                
                error_log("Total of " . count($_SESSION['open_invoices']) . " invoices found across " . count($_SESSION['clients_data']) . " clients");
            } else {
                // Single client case - existing code
                $clientKey = $_SESSION['company_info']['client_KEY'];
                
                // Get client balance for reference
                $balanceInfo = $this->paymentModel->getClientBalance($clientKey);
                $_SESSION['payment_amount'] = number_format($balanceInfo['balance'], 2, '.', '');
                $_SESSION['client_name'] = $balanceInfo['client_name'];
                $_SESSION['client_id'] = $balanceInfo['client_id'];
                
                // Get open invoices
                try {
                    $_SESSION['open_invoices'] = $this->paymentModel->getClientOpenInvoices($clientKey);
                    error_log("Found " . count($_SESSION['open_invoices']) . " open invoices for client");
                } catch (\Exception $e) {
                    error_log("Error fetching invoices: " . $e->getMessage());
                    $_SESSION['open_invoices'] = [];
                }
            }
            return true;
        }
        
        return false;
    }

    /**
     * Save payment information from the form
     */
    private function savePaymentInfo(array $data) {
        // Validate amount
        if (!isset($data['amount']) || !is_numeric($data['amount']) || $data['amount'] <= 0) {
            error_log("Invalid payment amount: " . ($data['amount'] ?? 'not set'));
            return false;
        }
        
        // Save selected invoice(s) and amount
        $_SESSION['payment_amount'] = $data['amount'];
        $_SESSION['selected_invoices'] = $data['invoices'] ?? [];
        $_SESSION['payment_notes'] = $data['notes'] ?? '';
        
        return true;
    }

    private function savePaymentMethod(string $method) {
        $_SESSION['payment_method'] = $method;
    }

    private function confirmPayment() {
        $data = [
            'company_id' => $_SESSION['company_info']['id'],
            'amount' => $_SESSION['payment_amount'],
            'method' => $_SESSION['payment_method'],
            'notes' => $_SESSION['notes'],
        ];
        $this->paymentModel->savePayment($data);
        $_SESSION['transaction_id'] = uniqid('txn_');
    }
}