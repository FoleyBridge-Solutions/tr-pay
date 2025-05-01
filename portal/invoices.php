<?php
/*
 * Client Portal
 * Invoices for PTC
 */

require_once "/var/www/itflow-ng/includes/inc_portal.php";

if ($contact_primary == 0 && !$contact_is_billing_contact) {
    header("Location: portal_post.php?logout");
    exit();
}

$invoices_sql = mysqli_query($mysqli, "SELECT * FROM invoices WHERE invoice_client_id = $client_id AND invoice_status != 'Draft' ORDER BY invoice_date DESC");
?>

<div class="row">

    <div class="col-md-10">
        <div class="card-datatable table-responsive">   
            <table id=responsive class="datatables-basic table border-top table-striped table-hover">
                <thead class="text-dark">
                <tr>
                    <th>#</th>
                    <th>Scope</th>
                    <th>Amount</th>
                    <th>Date</th>
                    <th>Due</th>
                    <th>Status</th>
                </tr>
                </thead>
                <tbody>

                <?php
                
                while ($row = mysqli_fetch_array($invoices_sql)) {
                    $invoice_id = intval($row['invoice_id']);
                    $invoice_prefix = nullable_htmlentities($row['invoice_prefix']);
                    $invoice_number = intval($row['invoice_number']);
                    $invoice_scope = nullable_htmlentities($row['invoice_scope']);
                    $invoice_status = nullable_htmlentities($row['invoice_status']);
                    $invoice_date = nullable_htmlentities($row['invoice_date']);
                    $invoice_due = nullable_htmlentities($row['invoice_due']);
                    $invoice_amount = getInvoiceAmount($invoice_id);
                    $invoice_url_key = nullable_htmlentities($row['invoice_url_key']);

                    if (empty($invoice_scope)) {
                        $invoice_scope_display = "-";
                    } else {
                        $invoice_scope_display = $invoice_scope;
                    }

                    $now = time();
                    if (($invoice_status == "Sent" || $invoice_status == "Partial" || $invoice_status == "Viewed") && strtotime($invoice_due) + 86400 < $now) {
                        $overdue_color = "text-danger font-weight-bold";
                    } else {
                        $overdue_color = "";
                    }

                    if ($invoice_status == "Sent") {
                        $invoice_badge_color = "warning text-white";
                    } elseif ($invoice_status == "Viewed") {
                        $invoice_badge_color = "info";
                    } elseif ($invoice_status == "Partial") {
                        $invoice_badge_color = "primary";
                    } elseif ($invoice_status == "Paid") {
                        $invoice_badge_color = "success";
                    } elseif ($invoice_status == "Cancelled") {
                        $invoice_badge_color = "danger";
                    } else{
                        $invoice_badge_color = "secondary";
                    }
                    ?>

                    <tr>
                        <td><a target="_blank" href="//<?= $config_base_url ?>/portal/guest_view_invoice.php?invoice_id=<?= "$invoice_id&url_key=$invoice_url_key"?>"> <?= "$invoice_prefix$invoice_number"; ?></a></td>
                        <td><?= $invoice_scope_display; ?></td>
                        <td><?= numfmt_format_currency($currency_format, $invoice_amount, $company_currency); ?></td>
                        <td><?= $invoice_date; ?></td>
                        <td class="<?= $overdue_color; ?>"><?= $invoice_due; ?></td>
                        <td>
                            <span class="p-2 badge bg-label-<?= $invoice_badge_color; ?>">
                                <?= $invoice_status; ?>
                            </span>
                        </td>

                    </tr>
                <?php } ?>

                </tbody>
            </table>
        </div>
    </div>

</div>


<?php
require_once "portal_footer.php";

