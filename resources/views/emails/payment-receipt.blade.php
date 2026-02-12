<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Payment Receipt</title>
    <style>
        body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
        .container { max-width: 600px; margin: 0 auto; padding: 20px; }
        .header { background-color: #f8f9fa; padding: 20px; text-align: center; border-radius: 5px; margin-bottom: 20px; }
        .content { background-color: #ffffff; padding: 20px; border: 1px solid #dee2e6; border-radius: 5px; }
        .payment-details { background-color: #f8f9fa; padding: 15px; border-radius: 5px; margin: 20px 0; }
        .invoice-table { width: 100%; border-collapse: collapse; margin: 20px 0; }
        .invoice-table th, .invoice-table td { padding: 10px; text-align: left; border-bottom: 1px solid #dee2e6; }
        .invoice-table th { background-color: #f8f9fa; font-weight: bold; }
        .total-row { font-weight: bold; background-color: #e9ecef; }
        .footer { margin-top: 30px; padding-top: 20px; border-top: 1px solid #dee2e6; font-size: 12px; color: #6c757d; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>Payment Receipt</h1>
            <p>Thank you for your payment</p>
        </div>

        <div class="content">
            <h2>Payment Confirmation</h2>
            <p><strong>Transaction ID:</strong> {{ $transactionId }}</p>
            <p><strong>Client:</strong> {{ $clientInfo['client_name'] }}</p>
            <p><strong>Client ID:</strong> {{ $clientInfo['client_id'] }}</p>
            <p><strong>Payment Date:</strong> {{ now()->format('F j, Y \a\t g:i A T') }}</p>

            <div class="payment-details">
                <h3>Payment Details</h3>
                <p><strong>Payment Method:</strong> {{ ucwords(str_replace('_', ' ', $paymentData['paymentMethod'])) }}</p>
                <p><strong>Payment Amount:</strong> ${{ number_format($paymentData['amount'], 2) }}</p>
                @if($paymentData['paymentMethod'] === 'credit_card' && isset($paymentData['fee']))
                    <p><strong>Processing Fee:</strong> ${{ number_format($paymentData['fee'], 2) }}</p>
                    <p><strong>Total Charged:</strong> ${{ number_format($paymentData['amount'] + $paymentData['fee'], 2) }}</p>
                @endif
                @if($paymentData['paymentMethod'] === 'payment_plan')
                    <p><strong>Payment Plan:</strong> {{ $paymentData['planFrequency'] }} payments for {{ $paymentData['planDuration'] }} months</p>
                    @if($paymentData['downPayment'] > 0)
                        <p><strong>Down Payment:</strong> ${{ number_format($paymentData['downPayment'], 2) }}</p>
                    @endif
                @endif
            </div>

            @if(isset($paymentData['invoices']) && count($paymentData['invoices']) > 0)
            <h3>Invoices Paid</h3>
            <table class="invoice-table">
                <thead>
                    <tr>
                        <th>Invoice #</th>
                        <th>Description</th>
                        <th>Amount Paid</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($paymentData['invoices'] as $invoice)
                    <tr>
                        <td>{{ $invoice['invoice_number'] }}</td>
                        <td>{{ $invoice['description'] }}</td>
                        <td>${{ number_format($invoice['amount'], 2) }}</td>
                    </tr>
                    @endforeach
                    <tr class="total-row">
                        <td colspan="2"><strong>Total Paid</strong></td>
                        <td><strong>${{ number_format($paymentData['amount'], 2) }}</strong></td>
                    </tr>
                </tbody>
            </table>
            @endif

            @if(isset($paymentData['notes']) && !empty($paymentData['notes']))
            <h3>Payment Notes</h3>
            <p>{{ $paymentData['notes'] }}</p>
            @endif

            <div class="footer">
                <p>This is an automated payment receipt. Please keep this email for your records.</p>
                <p>If you have any questions about this payment, please contact our office.</p>
            </div>
        </div>
    </div>
</body>
</html>