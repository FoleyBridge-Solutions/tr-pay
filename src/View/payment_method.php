<div class="card">
    <div class="card-header text-center">
        <h3>Select Payment Method</h3>
        <p class="mb-0">Choose how you would like to pay</p>
    </div>
    <div class="card-body">
        <form id="paymentMethodForm" method="post">
            <input type="hidden" name="step" value="payment_method">
            <input type="hidden" id="selectedPaymentMethod" name="payment_method" value="">

            <button type="button" class="btn btn-primary w-100 mb-3" onclick="selectPaymentMethod('credit_card')">
                Pay with Credit Card (3% fee: $<?= htmlspecialchars($creditCardFee); ?>)
            </button>
            <button type="button" class="btn btn-success w-100 mb-3" onclick="selectPaymentMethod('debit_card')">
                Pay with Debit Card (No fee)
            </button>
            <button type="button" class="btn btn-info w-100" onclick="selectPaymentMethod('echeck')">
                Pay with eCheck (No fee)
            </button>
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