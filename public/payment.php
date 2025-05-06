<?php
session_start();

require_once '../bootstrap.php';

use Fbs\trpay\Controller\PaymentController;
use Fbs\trpay\View\View;

// Initialize the controller and view
$paymentController = new PaymentController($conn);
$view = new View();

// Initialize current step
$currentStep = $_SESSION['current_step'] ?? 'email_verification';

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $step = $_POST['step'] ?? $currentStep;
    try {
        $success = $paymentController->handleStep($step, $_POST);
        
        if ($success) {
            // Only advance to next step if the current step was successful
            if ($step === 'email_verification') {
                $_SESSION['current_step'] = 'verify_code';
            } elseif ($step === 'verify_code') {
                $_SESSION['current_step'] = 'payment_information';
            } elseif ($step === 'payment_information') {
                $_SESSION['current_step'] = 'payment_method';
            } elseif ($step === 'payment_method') {
                $_SESSION['current_step'] = 'confirmation';
            }
            
            // UPDATE CURRENT STEP AFTER PROCESSING - THIS IS THE KEY FIX
            $currentStep = $_SESSION['current_step'];
            
            // Optional: redirect to prevent form resubmission
            header("Location: payment.php");
            exit;
        } else {
            // Step failed, add error message
            $error = "Validation failed for step: $step";
            if ($step === 'email_verification') {
                $error = "No company found with this email address.";
            } elseif ($step === 'verify_code') {
                $error = "Invalid verification code.";
            }
        }
    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}

// Calculate credit card fee (3% of payment amount)
$creditCardFee = 0;
if (isset($_SESSION['payment_amount']) && is_numeric($_SESSION['payment_amount'])) {
    $creditCardFee = $_SESSION['payment_amount'] * 0.03;
    $creditCardFee = number_format($creditCardFee, 2, '.', '');
}

// Render the current step's view
$view->render($currentStep, [
    'error' => $error ?? null,
    'companyInfo' => $_SESSION['company_info'] ?? null,
    'paymentAmount' => $_SESSION['payment_amount'] ?? null,
    'transactionId' => $_SESSION['transaction_id'] ?? null,
    'creditCardFee' => $creditCardFee,
]);