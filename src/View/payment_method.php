<div class="card shadow-sm rounded-lg">
    <div class="card-header text-center bg-light border-bottom-0 pt-4">
        <h3 class="mb-1">Select Your Payment Method</h3>
        <p class="text-muted mb-0">Choose how you'd like to complete your payment securely.</p>
        <div class="mt-2">
            <!-- Replace this comment with your actual SVG code -->
            <!-- Example: <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" class="bi bi-shield-lock-fill text-success me-1" viewBox="0 0 16 16" style="vertical-align: -0.125em;"><path fill-rule="evenodd" d="M8 0c-.993 0-1.924.39-2.643 1.099a4.905 4.905 0 0 0-1.942 3.036C1.96 5.346.013 6.84.001 8.83c0 .03.002.06.004.09C.06 11.69 1.857 16 8 16s7.94-4.31 7.996-7.08c.002-.03.003-.06.004-.09C15.987 6.84 14.04 5.346 12.585 4.135A4.905 4.905 0 0 0 10.643 1.1 4.904 4.904 0 0 0 8 0zm0 5a1.5 1.5 0 0 1 .5 2.915V10.5a.5.5 0 0 1-1 0V7.915A1.5 1.5 0 0 1 8 5z"/></svg> -->
            <span class="text-success" style="font-size: 0.9em;">
                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" class="bi bi-lock-fill me-1" viewBox="0 0 16 16" style="vertical-align: -0.125em;">
                    <path d="M8 1a2 2 0 0 1 2 2v4H6V3a2 2 0 0 1 2-2zm3 6V3a3 3 0 0 0-6 0v4a2 2 0 0 0-2 2v5a2 2 0 0 0 2 2h6a2 2 0 0 0 2-2V9a2 2 0 0 0-2-2z"/>
                </svg>
                SSL Encrypted Connection
            </span>
        </div>
    </div>
    <div class="card-body p-4">
        <form id="paymentMethodForm" method="post">
            <input type="hidden" name="step" value="payment_method">
            <input type="hidden" id="selectedPaymentMethod" name="payment_method" value="">

        <div class="row gx-3 gy-3">
            <div class="col-md-4">
                <button type="button" class="btn btn-outline-primary w-100 p-3" onclick="selectPaymentMethod('credit_card')">
                    <i class="fas fa-credit-card me-2"></i>Pay with Credit Card
                    <small class="d-block text-muted">(3% fee: $<?= htmlspecialchars($creditCardFee); ?>)</small>
                </button>
            </div>
            <div class="col-md-4">
                <button type="button" class="btn btn-outline-success w-100 p-3" onclick="selectPaymentMethod('debit_card')">
                    <i class="fas fa-credit-card me-2"></i>Pay with Debit Card
                    <small class="d-block text-muted">(No fee)</small>
                </button>
            </div>
            <div class="col-md-4">
                <button type="button" class="btn btn-outline-info w-100 p-3" onclick="selectPaymentMethod('echeck')">
                    <i class="fas fa-money-check-alt me-2"></i>Pay with eCheck
                    <small class="d-block text-muted">(No fee)</small>
                </button>
            </div>
        </div>
        </form>
    </div>
</div>
<script>
function selectPaymentMethod(method) {
    // Set the selected method in the hidden input
    document.getElementById('selectedPaymentMethod').value = method;
    
    // Submit the form
    document.getElementById('paymentMethodForm').submit();
}
</script>