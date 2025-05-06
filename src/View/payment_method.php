<div class="card">
    <div class="card-header text-center">
        <h3>Select Payment Method</h3>
        <p class="mb-0">Choose how you would like to pay</p>
    </div>
    <div class="card-body">
        <form id="paymentMethodForm" method="post">
            <input type="hidden" name="step" value="payment_method">
            <input type="hidden" id="selectedPaymentMethod" name="payment_method" value="">

        <div class="row">
            <div class="col-md-4 mb-3">
                <button type="button" class="btn btn-primary w-100" onclick="selectPaymentMethod('credit_card')">
                    Pay with Credit Card (3% fee: $<?= htmlspecialchars($creditCardFee); ?>)
                </button>
            </div>
            <div class="col-md-4 mb-3">
                <button type="button" class="btn btn-success w-100" onclick="selectPaymentMethod('debit_card')">
                    Pay with Debit Card (No fee)
                </button>
            </div>
            <div class="col-md-4 mb-3">
                <button type="button" class="btn btn-info w-100" onclick="selectPaymentMethod('echeck')">
                    Pay with eCheck (No fee)
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