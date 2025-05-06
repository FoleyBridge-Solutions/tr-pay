<div class="card">
    <div class="card-header text-center">
        <h3>Confirmation</h3>
    </div>
    <div class="card-body">
        <p>Transaction ID: <strong><?= htmlspecialchars($_SESSION['transaction_id']); ?></strong></p>
        <p>Your payment method: <strong><?= htmlspecialchars($_SESSION['payment_method']); ?></strong></p>
        <p>Payment Amount: <strong>$<?= htmlspecialchars($_SESSION['payment_amount']); ?></strong></p>
        <p>Member#: <strong><?= htmlspecialchars($_SESSION['invoice_number'] ?? 'N/A'); ?></strong></p>
        <p>Notes: <strong><?= htmlspecialchars($_SESSION['notes'] ?? 'N/A'); ?></strong></p>
        <p>Thank you for your payment!</p>
    </div>
</div>