<?php
extract($statement);
$currency_code = "USD";
$max_rows = 10;
$current_balance_calculated = $aging_balance + $aging_balance_30 + $aging_balance_60 + $aging_balance_90;
$past_due_amount = $aging_balance_30 + $aging_balance_60 + $aging_balance_90;
?>
<div class="card">
    <div class="card-header py-2">
        <form method="get">
            <input type="hidden" name="page" value="statement">
            <h3 class="card-title mt-2"><i class="fas fa-fw fa-balance-scale mr-2"></i>Statement for 
            <select name="client_id" id="client_id" class="form-control form-control-sm select2" onchange="this.form.submit()">
            <?php foreach ($all_clients as $client) { ?>
                <option value="<?= $client['client_id'] ?>" <?php if ($client['client_id'] == $client_id) { echo "selected"; } ?>>
                    <?= $client['client_name'] ?>
                </option>
            <?php } ?>
            </select>
        </form>
        </h3>
        <div class="card-tools">
            <button type="button" class="btn btn-label-primary d-print-none" onclick="window.print();"><i class="fas fa-fw fa-print mr-2"></i>Print</button>
        </div>
    </div>
    
    <div class="card-body">
        <div>
            <div class="row">
                <div class="col-md-6 col-12">
                    <div class="me-4">
                        <h4>Client Details</h4>
                        <table class="table table-sm" id="client_details_table">
                            <tr>
                                <td>Client Name:</td>
                                <td><?= $client_name; ?></td>
                            </tr>
                            <tr>
                                <td>Net Terms:</td>
                                <td><?= $client_net_terms; ?> days</td>
                            </tr>
                            <tr>
                                <td>Current Balance:</td>
                                <td><?= numfmt_format_currency($currency_format, $current_balance_calculated, $currency_code); ?></td>
                            </tr>
                            <tr>
                                <td>Past Due:</td>
                                <td><?= numfmt_format_currency($currency_format, $past_due_amount, $currency_code); ?></td>
                            </tr>
                        </table>                        
                    </div>

                </div>
                <div class="col-md-6 col-12">
                    <!-- 0-30. 30-60, 60-90, 90+ -->
                    <div class="me-4">
                        <h4>Ageing Summary</h4>
                        <table class="table table-sm" id="ageing_summary_table">
                            <tr>
                                <td>0-30 Days:</td>
                                <td><?= numfmt_format_currency($currency_format, $aging_balance, $currency_code); ?></td>
                            </tr>
                            <tr>
                                <td>31-60 Days:</td>
                                <td><?= numfmt_format_currency($currency_format, $aging_balance_30, $currency_code); ?></td>
                            </tr>
                            <tr>
                                <td>61-90 Days:</td>
                                    <td><?= numfmt_format_currency($currency_format, $aging_balance_60, $currency_code); ?></td>
                            </tr>
                            <tr>
                                <td>90+ Days:</td>
                                <td><?= numfmt_format_currency($currency_format, $aging_balance_90, $currency_code); ?></td>
                            </tr>
                        </table>
                    </div>
                </div>

                <div class="coTmd-12">
                    <div class="m-3">
                        <h4>
                            <a href="?client_id=<?= $client_id; ?>&max_rows=<?= $max_rows+10; ?>" class="text-secondary">
                                <?= $outstanding_wording; ?>
                            </a>
                            Invoices and Associated Payments
                        </h4>
                        <div class="">
                            <table class="table border-top" id="client_statement_table">
                                <thead>
                                    <tr>
                                        <th>Date</th>
                                        <th>Transaction</th>
                                        <th>Amount</th>
                                        <th>Balance</th>
                                    </tr>
                                </thead>
                                <?php
                                $default_order = 0;
                                $invoice_count = 0;
                                $transaction_invoices = [];

                                foreach ($transactions as $row) {
                                    // if (in_array($row['invoice_id'], $transaction_invoices)) {
                                    //     continue;
                                    // } else {
                                    //     array_push($transaction_invoices, $row['invoice_id']);
                                    // }

                                    $transaction_date = nullable_htmlentities($row['invoice_date']);
                                    $transaction_type = "Invoice" . "<a href='/public/?page=invoice&invoice_id=" . $row['invoice_id'] . "'> " . $row['invoice_prefix'] . $row['invoice_number'] . "</a>";
                                    $transaction_amount = floatval($row['invoice_amount']);
                                    $transaction_balance = $row['invoice_balance'];
                                    $transaction_due_date = sanitizeInput($row['invoice_due']);
                                    $invoice_id = intval($row['invoice_id']);

                                    if ($invoice_count > $max_rows && $transaction_balance <= 0) {
                                        continue;
                                    }

                                    // if ($transaction_balance <= 0) {
                                    //     $transaction_balance = 0;
                                    // }


                                    // IF due date has passed, add a warning class
                                    if ((strtotime($transaction_due_date) < strtotime(date("Y-m-d"))) && ($transaction_balance > 0)) {
                                        $transaction_balance = "<span class='text-danger'>" . numfmt_format_currency($currency_format, $transaction_balance, $currency_code) . " <small>(Past Due)</small></span>";
                                    } else {
                                        $transaction_balance = numfmt_format_currency($currency_format, $transaction_balance, $currency_code);
                                    }
                                    $default_order ++;
                                    $payments = $row['payments'];

                                    ?>
                                    <tr>
                                        <td><?= $transaction_date; ?></td>
                                        <td><?= $transaction_type; ?></td>
                                        <td><?= numfmt_format_currency($currency_format, $transaction_amount, $currency_code); ?></td>
                                        <td rowspan="<?= count($payments) + 1 ?>"><?= $transaction_balance ?></td>
                                    </tr>
                                    <?php
                                    foreach ($payments as $payment) {

                                        $transaction_date = nullable_htmlentities($payment['payment_date']);
                                        $transaction_type = $payment['payment_method'];
                                        $transaction_amount = floatval($payment['payment_amount']) *-1;
                                        if ($payment['payment_method'] != "Stripe") {
                                            $transaction_type = $transaction_type. " " . $payment['payment_reference'];
                                        } else {
                                            $stripe_ref_last_4 = "...".substr($payment['payment_reference'], -4);
                                            $transaction_type = "Online Payment";
                                        }
                                        $default_order ++;

                                        // if transaction date is from a previous year, then save the year to $transaction_year
                                        if (date("Y", strtotime($transaction_date)) < date("Y")) {
                                            $transaction_year = date("Y", strtotime($transaction_date));
                                        }
                                        ?>
                                        <tr class="small">
                                            <td><?= $transaction_date; ?></td>
                                            <td>
                                                <i class="bx bx-credit-card"></i>
                                                <a href="/old_pages/report/payments_report.php?client_id=<?= $client_id; ?>&payment_reference=<?= $payment['payment_reference']; ?>&year=<?= date("Y", strtotime($transaction_date)); ?>">
                                                    <?= $transaction_type; ?>
                                                </a>
                                            </td>
                                            <td><?= numfmt_format_currency($currency_format, $transaction_amount, $currency_code); ?></td>
                                        </tr>
                                        <?php
                                    }
                                }
                                ?>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
