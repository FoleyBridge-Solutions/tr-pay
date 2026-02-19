<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Payment Request</title>
    <style>
        body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; margin: 0; padding: 0; background-color: #f5f5f5; }
        .container { max-width: 600px; margin: 0 auto; padding: 20px; }
        .header { background-color: #18181b; padding: 30px 20px; text-align: center; border-radius: 5px 5px 0 0; }
        .header h1 { color: #ffffff; margin: 0; font-size: 24px; }
        .header p { color: #a1a1aa; margin: 8px 0 0; font-size: 14px; }
        .content { background-color: #ffffff; padding: 30px; border: 1px solid #e4e4e7; border-top: none; }
        .amount-box { background-color: #f4f4f5; padding: 20px; border-radius: 8px; text-align: center; margin: 20px 0; }
        .amount-box .label { font-size: 14px; color: #71717a; margin: 0; }
        .amount-box .amount { font-size: 36px; font-weight: bold; color: #18181b; margin: 5px 0; }
        .invoice-table { width: 100%; border-collapse: collapse; margin: 20px 0; }
        .invoice-table th, .invoice-table td { padding: 10px; text-align: left; border-bottom: 1px solid #e4e4e7; }
        .invoice-table th { background-color: #f4f4f5; font-weight: bold; font-size: 13px; color: #52525b; }
        .invoice-table td { font-size: 14px; }
        .message-box { background-color: #fefce8; border: 1px solid #fef08a; padding: 15px; border-radius: 8px; margin: 20px 0; }
        .message-box .label { font-size: 12px; font-weight: bold; color: #a16207; text-transform: uppercase; margin: 0 0 5px; }
        .message-box p { margin: 0; font-size: 14px; color: #713f12; }
        .pay-button { display: block; background-color: #18181b; color: #ffffff !important; text-align: center; padding: 16px 32px; font-size: 18px; font-weight: bold; text-decoration: none; border-radius: 8px; margin: 25px 0; }
        .pay-button:hover { background-color: #27272a; }
        .details { font-size: 13px; color: #71717a; margin: 15px 0; }
        .details p { margin: 4px 0; }
        .footer { padding: 20px 30px; border: 1px solid #e4e4e7; border-top: none; border-radius: 0 0 5px 5px; background-color: #fafafa; }
        .footer p { font-size: 12px; color: #a1a1aa; margin: 4px 0; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>{{ $companyName }}</h1>
            <p>Payment Request</p>
        </div>

        <div class="content">
            <p>Hello {{ $paymentRequest->client_name }},</p>

            <p>A payment has been requested for your account. Please review the details below and click the button to complete your payment.</p>

            <div class="amount-box">
                <p class="label">Amount Due</p>
                <p class="amount">${{ number_format($paymentRequest->amount, 2) }}</p>
            </div>

            @if($paymentRequest->message)
                <div class="message-box">
                    <p class="label">Message from {{ $companyName }}</p>
                    <p>{{ $paymentRequest->message }}</p>
                </div>
            @endif

            @if($paymentRequest->invoices && count($paymentRequest->invoices) > 0)
                <h3 style="font-size: 16px; margin: 20px 0 10px;">Invoices</h3>
                <table class="invoice-table">
                    <thead>
                        <tr>
                            <th>Invoice #</th>
                            <th>Description</th>
                            <th style="text-align: right;">Amount</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($paymentRequest->invoices as $invoice)
                            <tr>
                                <td>{{ $invoice['invoice_number'] }}</td>
                                <td>{{ $invoice['description'] ?? '' }}</td>
                                <td style="text-align: right;">${{ number_format($invoice['open_amount'] ?? 0, 2) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif

            <a href="{{ $paymentUrl }}" class="pay-button">Pay ${{ number_format($paymentRequest->amount, 2) }} Now</a>

            <div class="details">
                <p><strong>Client ID:</strong> {{ $paymentRequest->client_id }}</p>
                <p><strong>Link expires:</strong> {{ $paymentRequest->expires_at->format('F j, Y') }}</p>
            </div>

            <p style="font-size: 14px; color: #52525b;">
                If the button above doesn't work, copy and paste this URL into your browser:
            </p>
            <p style="font-size: 12px; color: #3b82f6; word-break: break-all;">{{ $paymentUrl }}</p>
        </div>

        <div class="footer">
            <p>This is an automated payment request from {{ $companyName }}.</p>
            <p>This link is single-use and will expire on {{ $paymentRequest->expires_at->format('F j, Y') }}.</p>
            <p>If you have any questions, please contact our office.</p>
        </div>
    </div>
</body>
</html>
