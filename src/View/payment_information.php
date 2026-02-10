<div class="card">
    <div class="card-header text-center">
        <h3>Payment Information</h3>
        <?php if (isset($_SESSION['has_multiple_clients']) && $_SESSION['has_multiple_clients']) { ?>
            <p class="mb-0">You have invoices from multiple accounts</p>
        <?php } else { ?>
            <p class="mb-0">Enter payment details for <?= htmlspecialchars($_SESSION['client_name'] ?? 'your account') ?></p>
        <?php } ?>
    </div>
    <div class="card-body">
        <form method="post">
            <input type="hidden" name="step" value="payment_information">

            <?php
            $companyBalance = $_SESSION['company_info']['balance'] ?? 0;
        $customerId = $_SESSION['customer_id'] ?? 'N/A';
        $openInvoices = $_SESSION['open_invoices'] ?? [];
        $hasInvoices = ! empty($openInvoices);
        ?>

            <?php if ($hasInvoices) { ?>
                <div class="mb-4">
                    <h5>Select Invoice(s) to Pay</h5>
                    <div class="table-responsive">
                        <table class="table table-hover">
                            <thead>
                                <tr>
                                    <th><input type="checkbox" id="select-all" class="form-check-input"></th>
                                    <?php if (isset($_SESSION['has_multiple_clients']) && $_SESSION['has_multiple_clients']) { ?>
                                        <th>Client</th>
                                    <?php } ?>
                                    <th>Invoice #</th>
                                    <th>Date</th>
                                    <th>Due Date</th>
                                    <th>Description</th>
                                    <th class="text-end">Amount</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php
                            // Sort invoices by client if multiple clients
                            if (isset($_SESSION['has_multiple_clients']) && $_SESSION['has_multiple_clients']) {
                                usort($openInvoices, function ($a, $b) {
                                    return strcmp($a['client_name'], $b['client_name']);
                                });
                            }

                $currentClient = null;
                foreach ($openInvoices as $invoice) {
                    // Add client section header if multiple clients
                    if (isset($_SESSION['has_multiple_clients']) &&
                        $_SESSION['has_multiple_clients'] &&
                        $currentClient !== $invoice['client_name']) {
                        $currentClient = $invoice['client_name'];
                    }
                    ?>
                                <tr>
                                    <td>
                                        <input type="checkbox" name="invoices[]" value="<?= $invoice['ledger_entry_KEY'] ?>" 
                                               class="form-check-input invoice-checkbox" 
                                               data-amount="<?= $invoice['open_amount'] ?>">
                                    </td>
                                    <?php if (isset($_SESSION['has_multiple_clients']) && $_SESSION['has_multiple_clients']) { ?>
                                        <td><?= htmlspecialchars($invoice['client_name']) ?></td>
                                    <?php } ?>
                                    <td><?= htmlspecialchars($invoice['invoice_number']) ?></td>
                                    <td><?= htmlspecialchars($invoice['invoice_date']) ?></td>
                                    <td><?= htmlspecialchars($invoice['due_date']) ?></td>
                                    <td><?= htmlspecialchars($invoice['description']) ?></td>
                                    <td class="text-end">$<?= htmlspecialchars($invoice['open_amount']) ?></td>
                                </tr>
                                <?php } ?>
                            </tbody>
                            <tfoot>
                                <tr>
                                    <th colspan="<?= (isset($_SESSION['has_multiple_clients']) && $_SESSION['has_multiple_clients']) ? 6 : 5 ?>" class="text-end">Total Selected:</th>
                                    <th class="text-end">$<span id="total-selected">0.00</span></th>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                </div>
            <?php } else { ?>
                <div class="alert alert-info">
                    No open invoices found for this account. You can still make a payment by entering an amount below.
                </div>
            <?php } ?>

            <div class="mb-3">
                <label for="amount" class="form-label">Payment Amount</label>
                <div class="input-group">
                    <span class="input-group-text">$</span>
                    <input type="number" step="0.01" min="0.01" class="form-control" id="amount" name="amount" 
                           value="<?= htmlspecialchars($_SESSION['payment_amount'] ?? '') ?>" required>
                </div>
                <div class="form-text">Enter the payment amount in USD.</div>
            </div>
            <div class="mb-3">
                <label for="invoice_number" class="form-label">Invoice# or Member#</label>
                <input 
                    type="text" 
                    class="form-control" 
                    id="invoice_number" 
                    name="invoice_number" 
                    placeholder="Member#: <?= htmlspecialchars($customerId); ?>" 
                    value="<?= htmlspecialchars($customerId); ?>" 
                    readonly
                >
            </div>
            <div class="mb-3">
                <label for="notes" class="form-label">Notes (Optional - max 255 characters)</label>
                <textarea class="form-control" id="notes" name="notes" rows="3" maxlength="255" placeholder="Enter any additional notes"></textarea>
            </div>
            <button type="submit" class="btn btn-primary w-100">Continue to Payment Method</button>
        </form>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const checkboxes = document.querySelectorAll('.invoice-checkbox');
    const selectAll = document.getElementById('select-all');
    const totalSelected = document.getElementById('total-selected');
    const amountInput = document.getElementById('amount');
    
    // Function to update total
    function updateTotal() {
        let total = 0;
        checkboxes.forEach(checkbox => {
            if (checkbox.checked) {
                total += parseFloat(checkbox.getAttribute('data-amount'));
            }
        });
        totalSelected.textContent = total.toFixed(2);
        amountInput.value = total.toFixed(2);
    }
    
    // Add event listeners to checkboxes
    checkboxes.forEach(checkbox => {
        checkbox.addEventListener('change', updateTotal);
    });
    
    // Select all functionality
    if (selectAll) {
        selectAll.addEventListener('change', function() {
            checkboxes.forEach(checkbox => {
                checkbox.checked = selectAll.checked;
            });
            updateTotal();
        });
    }
});
</script>