<?php 

// Accounting related functions

include '/var/www/itflow-ng/bootstrap.php';

use Twetech\Nestogy\Model\Accounting;

function syncPlaidTransactions($db_account_id, $next_cursor = null) {
    global $mysqli, $config_plaid_client_id, $config_plaid_secret;

    error_log("Starting syncPlaidTransactions for account_id: $db_account_id, next_cursor: $next_cursor");

    // Get access token and next cursor from database
    $sql = "SELECT plaid_access_token, plaid_next_cursor FROM plaid_accounts
    LEFT JOIN accounts ON plaid_accounts.plaid_account_id = accounts.plaid_id
    WHERE account_id = $db_account_id";
    $result = mysqli_query($mysqli, $sql);
    if (mysqli_num_rows($result) == 0) {
        error_log("No access token found for account_id: $db_account_id");
        return "no_access_token";
    }

    $row = mysqli_fetch_assoc($result);
    $access_token = $row['plaid_access_token'];
    $next_cursor = $next_cursor ? $next_cursor : $row['plaid_next_cursor'];

    error_log("Using access_token: $access_token, next_cursor: $next_cursor");

    $postfields = [
        "client_id" => $config_plaid_client_id,
        "secret" => $config_plaid_secret,
        "access_token" => $access_token,
        "cursor" => $next_cursor
    ];

    $postfields = json_encode($postfields);

    $curl = curl_init();
    $curl_array = array(
        CURLOPT_URL => 'https://production.plaid.com/transactions/sync',
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_ENCODING => '',
        CURLOPT_MAXREDIRS => 10,
        CURLOPT_TIMEOUT => 0,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
        CURLOPT_CUSTOMREQUEST => 'POST',
        CURLOPT_POSTFIELDS => $postfields,
        CURLOPT_HTTPHEADER => array(
            'Content-Type: application/json'
        )
    );
    curl_setopt_array($curl, $curl_array);
    $response = curl_exec($curl);
    curl_close($curl);
    $response = json_decode($response, true);

    if (isset($response['error_code']) && $response['error_code'] == 'ITEM_LOGIN_REQUIRED') {
        return ['success' => false, 'error' => 'ITEM_LOGIN_REQUIRED'];
    } else {
        //update the account updated_at field
        $sql = "UPDATE plaid_accounts SET plaid_last_update = NOW() WHERE plaid_access_token = '$access_token'";
        $account_sql = mysqli_query($mysqli, $sql);
    }

    error_log("Plaid response summary: " . 
              "Added: " . count($response['added'] ?? []) . ", " .
              "Modified: " . count($response['modified'] ?? []) . ", " .
              "Removed: " . count($response['removed'] ?? []) . ", " .
              "Has more: " . ($response['has_more'] ? 'Yes' : 'No') . ", " .
              "Next cursor: " . ($response['next_cursor'] ?? 'Not provided'));

    $accounts = $response['accounts'] ?? [];
    $added_transactions = $response['added'] ?? [];
    $modified_transactions = $response['modified'] ?? [];
    $removed_transactions = $response['removed'] ?? [];

    if (is_array($accounts)) {
        foreach ($accounts as $account) {
            $account_id = $account['account_id'] ?? '';
            $account_name = $account['official_name'] ?? $account['name'];
            $account_type = $account['type'] ?? '';
            $account_subtype = $account['subtype'] ?? '';
            $account_balance_current = $account['balances']['current'] ?? '0';
            $account_balance_available = $account['balances']['available'] ?? '0';
            $account_balance_limit = $account['balances']['limit'] ?? '0';

            // Check if account already exists
            $sql = "SELECT * FROM plaid_accounts WHERE plaid_access_token = '$access_token'";
            $result = mysqli_query($mysqli, $sql);
            if (mysqli_num_rows($result) > 0) {
                //update the account
                $sql = "UPDATE plaid_accounts SET
                plaid_name = '$account_name',
                plaid_official_name = '$account_name',
                plaid_type = '$account_type',
                plaid_subtype = '$account_subtype',
                plaid_balance_current = '$account_balance_current',
                plaid_balance_available = '$account_balance_available',
                plaid_balance_limit = '$account_balance_limit'
                WHERE plaid_access_token = '$access_token'
                ";
                $account_sql = mysqli_query($mysqli, $sql);
                if (!$account_sql) {
                    error_log(mysqli_error($mysqli));
                }
            }
        }
    }

    if (is_array($added_transactions)){
        error_log("Processing " . count($added_transactions) . " added transactions");
        foreach ($added_transactions as $transaction) {
            $account_id = $transaction['account_id'] ?? '';
            $account_owner = $transaction['account_owner'] ?? '';
            $amount = $transaction['amount'] ?? '';
            $authorized_date = $transaction['authorized_date'] ?? '0000-00-00';
            $category = sanitizeInput($transaction['category'] ?? '');
            $category_id = $transaction['category_id'] ?? '';
            $date = $transaction['date'] ?? '0000-00-00';
            $iso_currency_code = $transaction['iso_currency_code'] ?? '';
            $merchant_name = sanitizeInput($transaction['merchant_name'] ?? '');
            $name = sanitizeInput($transaction['name'] ?? '');
            $payment_channel = $transaction['payment_channel'] ?? '';
            $payment_meta = $transaction['payment_meta'] ?? '';
            $pending = $transaction['pending'] == 'true' ? 1 : 0;
            $pending_transaction_id = $transaction['pending_transaction_id'] ?? '';
            $transaction_id = $transaction['transaction_id'] ?? '';
            $transaction_type = $transaction['transaction_type'] ?? '';
            $icon_url = $transaction['personal_finance_category_icon_url'] ?? '';
    
            // Check if transaction already exists
            $sql = "SELECT * FROM bank_transactions WHERE transaction_id = '$transaction_id'";
            $result = mysqli_query($mysqli, $sql);
            if (mysqli_num_rows($result) > 0) {
                error_log("Transaction already exists: " . $transaction_id);
                continue;
            }
    
            // If category is an array, convert it to a string
            if (is_array($category)) {
                $category = implode(', ', $category);
            }
    
            // If payment_meta is an array, convert it to a string
            if (is_array($payment_meta)) {
                $payment_meta = implode(', ', $payment_meta);
            }
    
            $sql = "INSERT INTO bank_transactions SET
            bank_account_id = '$account_id',
            account_owner = '$account_owner',
            amount = '$amount',
            authorized_date = '$authorized_date',
            category = '$category',
            category_id = '$category_id',
            date = '$date',
            iso_currency_code = '$iso_currency_code',
            merchant_name = '$merchant_name',
            name = '$name',
            payment_channel = '$payment_channel',
            payment_meta = '$payment_meta',
            pending = '$pending',
            pending_transaction_id = '$pending_transaction_id',
            transaction_id = '$transaction_id',
            transaction_type = '$transaction_type',
            icon_url = '$icon_url'
            ";
            $transaction_sql = mysqli_query($mysqli, $sql);

            if (!$transaction_sql) {
                error_log(mysqli_error($mysqli));
            }
            error_log("Added transaction: " . $transaction_id);
        }
        
        // Update next cursor in database
        $next_cursor = $response['next_cursor'] ?? null;
        if ($next_cursor) {
            $sql = "UPDATE plaid_accounts
            LEFT JOIN accounts ON plaid_accounts.plaid_account_id = accounts.plaid_id
            SET plaid_accounts.plaid_next_cursor = '$next_cursor'
            WHERE accounts.account_id = $db_account_id";
            $update_result = mysqli_query($mysqli, $sql);
            error_log("Updated next_cursor in database: " . ($update_result ? 'Success' : 'Failed'));
        } else {
            error_log("No next_cursor provided in response");
        }

        if ($response['has_more']) {
            error_log("More transactions available. Recursively calling syncPlaidTransactions");
            return syncPlaidTransactions($db_account_id, $next_cursor);
        }
    } else {
        error_log("No added transactions in this batch");
    }

    if (is_array($modified_transactions)) {
        foreach ($modified_transactions as $transaction) {
            $account_id = $transaction['account_id'] ?? '';
            $account_owner = $transaction['account_owner'] ?? '';
            $amount = $transaction['amount'] ?? '';
            $authorized_date = $transaction['authorized_date'] ?? '0000-00-00';
            $category = $transaction['category'] ?? '';
            $category_id = $transaction['category_id'] ?? '';
            $date = $transaction['date'] ?? '0000-00-00';
            $iso_currency_code = $transaction['iso_currency_code'] ?? '';
            $merchant_name = sanitizeInput($transaction['merchant_name'] ?? '');
            $name = sanitizeInput($transaction['name'] ?? '');
            $payment_channel = $transaction['payment_channel'] ?? '';
            $payment_meta = $transaction['payment_meta'] ?? '';
            $pending = $transaction['pending'] == 'true' ? 1 : 0;
            $pending_transaction_id = $transaction['pending_transaction_id'] ?? '';
            $transaction_id = $transaction['transaction_id'] ?? '';
            $transaction_type = $transaction['transaction_type'] ?? '';

            // Check if transaction already exists
            $sql = "SELECT * FROM bank_transactions WHERE transaction_id = '$transaction_id'";
            $result = mysqli_query($mysqli, $sql);
            if (mysqli_num_rows($result) == 0) {
                continue;
            }

            // If category is an array, convert it to a string
            if (is_array($category)) {
                $category = implode(', ', $category);
            }

            // If payment_meta is an array, convert it to a string
            if (is_array($payment_meta)) {
                $payment_meta = implode(', ', $payment_meta);
            }

            $sql = "UPDATE bank_transactions SET
            bank_account_id = '$account_id',
            account_owner = '$account_owner',
            amount = '$amount',
            authorized_date = '$authorized_date',
            category = '$category',
            category_id = '$category_id',
            date = '$date',
            iso_currency_code = '$iso_currency_code',
            merchant_name = '$merchant_name',
            name = '$name',
            payment_channel = '$payment_channel',
            payment_meta = '$payment_meta',
            pending = '$pending',
            pending_transaction_id = '$pending_transaction_id',
            transaction_id = '$transaction_id',
            transaction_type = '$transaction_type'
            WHERE transaction_id = '$transaction_id'
            ";
            $transaction_sql = mysqli_query($mysqli, $sql);
        }
    }

    if (is_array($removed_transactions)) {
        foreach ($removed_transactions as $transaction) {
            $transaction_id = $transaction['transaction_id'] ?? '';

            $sql = "DELETE FROM bank_transactions WHERE transaction_id = '$transaction_id'";
            $transaction_sql = mysqli_query($mysqli, $sql);
        }
    }

    error_log("Finished syncPlaidTransactions for account_id: $db_account_id");

    return ['success' => true];
}

function linkPlaidAccount($account_id, $plaid_account_id) {
    global $mysqli;

    validateAccountantRole();

    $account_id = sanitizeInput($account_id);
    $plaid_account_id = sanitizeInput($plaid_account_id);

    $sql = "UPDATE accounts SET plaid_id = '$plaid_account_id' WHERE account_id = $account_id";
    error_log($sql);
    $result = mysqli_query($mysqli, $sql);

    return $result;
}

function linkTransactionToPayment($transaction_id, $payment_id) {
    global $mysqli;

    validateAccountantRole();

    $transaction_id = sanitizeInput($transaction_id);
    $payment_id = sanitizeInput($payment_id);

    $sql = "UPDATE payments SET plaid_transaction_id = '$transaction_id' WHERE payment_id = $payment_id";
    error_log($sql);
    $result = mysqli_query($mysqli, $sql);

    return $result;
}

function linkTransactionToExpense($transaction_id, $expense_id) {
    global $mysqli;

    validateAccountantRole();

    $transaction_id = sanitizeInput($transaction_id);
    $expense_id = sanitizeInput($expense_id);

    $sql = "UPDATE expenses SET plaid_transaction_id = '$transaction_id' WHERE expense_id = $expense_id";
    error_log($sql);
    $result = mysqli_query($mysqli, $sql);

    return $result;
}

function getMonthlyTax($tax_name, $month, $year, $mysqli)
{
    $tax_name = sanitizeInput($tax_name);
    $month = intval($month);
    $year = intval($year);

    // SQL to calculate monthly tax
    $sql = "SELECT SUM(item_tax) AS monthly_tax FROM invoice_items 
            LEFT JOIN invoices ON invoice_items.item_invoice_id = invoices.invoice_id
            LEFT JOIN payments ON invoices.invoice_id = payments.payment_invoice_id
            WHERE YEAR(payments.payment_date) = $year AND MONTH(payments.payment_date) = $month
            AND invoice_items.item_tax_id = (SELECT tax_id FROM taxes WHERE tax_name = '$tax_name')";
    $result = mysqli_query($mysqli, $sql);
    $row = mysqli_fetch_assoc($result);
    return $row['monthly_tax'] ?? 0;
}

function getQuarterlyTax($tax_name, $quarter, $year, $mysqli)
{

    $tax_name = sanitizeInput($tax_name);
    $quarter = intval($quarter);
    $year = intval($year);

    // Calculate start and end months for the quarter
    $start_month = ($quarter - 1) * 3 + 1;
    $end_month = $start_month + 2;

    // SQL to calculate quarterly tax
    $sql = "SELECT SUM(item_tax) AS quarterly_tax FROM invoice_items 
            LEFT JOIN invoices ON invoice_items.item_invoice_id = invoices.invoice_id
            LEFT JOIN payments ON invoices.invoice_id = payments.payment_invoice_id
            WHERE YEAR(payments.payment_date) = $year AND MONTH(payments.payment_date) BETWEEN $start_month AND $end_month
            AND invoice_items.item_tax_id = (SELECT tax_id FROM taxes WHERE tax_name = '$tax_name')";
    $result = mysqli_query($mysqli, $sql);
    $row = mysqli_fetch_assoc($result);
    return $row['quarterly_tax'] ?? 0;
}

function getTotalTax($tax_name, $year, $mysqli)
{
    $tax_name = sanitizeInput($tax_name);
    $year = intval($year);

    // SQL to calculate total tax
    $sql = "SELECT SUM(item_tax) AS total_tax FROM invoice_items 
            LEFT JOIN invoices ON invoice_items.item_invoice_id = invoices.invoice_id
            LEFT JOIN payments ON invoices.invoice_id = payments.payment_invoice_id
            WHERE YEAR(payments.payment_date) = $year
            AND invoice_items.item_tax_id = (SELECT tax_id FROM taxes WHERE tax_name = '$tax_name')";
    $result = mysqli_query($mysqli, $sql);
    $row = mysqli_fetch_assoc($result);
    return $row['total_tax'] ?? 0;
}

function getMonthlyIncome($year, $month)
{
    $year = intval($year);
    $month = intval($month);

    global $mysqli;

    $sql_month_query = $month == 13 ? "" : "AND MONTH(payment_date) = $month";

    $sql = "SELECT SUM(payment_amount) AS total_income FROM payments WHERE YEAR(payment_date) = $year $sql_month_query";
    $result = mysqli_query($mysqli, $sql);
    $row = mysqli_fetch_assoc($result);
    return floatval($row['total_income']) ?? 0;
}

function getMonthlyPayments($year, $month)
{

    $year = intval($year);
    $month = intval($month);

    global $mysqli;

    $sql_month_query = $month == 13 ? "" : "AND MONTH(payment_date) = $month";

    $sql = "SELECT COUNT(payment_id) AS number_payments FROM payments WHERE YEAR(payment_date) = $year $sql_month_query";
    $result = mysqli_query($mysqli, $sql);
    $row = mysqli_fetch_assoc($result);
    return intval($row['number_payments']) ?? 0;
}

function getMonthlyReceivables($year, $month)
{

    $year = intval($year);
    $month = intval($month);

    global $mysqli;

    $sql_month_query = $month == 13 ? "" : "AND MONTH(invoice_date) = $month";

    $sql = "SELECT SUM(invoice_amount) AS total_receivables FROM invoices WHERE YEAR(invoice_date) = $year $sql_month_query";
    $result = mysqli_query($mysqli, $sql);
    $row = mysqli_fetch_assoc($result);
    return floatval($row['total_receivables']) ?? 0;
}

function getMonthlyOutstandingInvoices($year, $month)
{
    global $mysqli;

    $year = intval($year);
    $month = intval($month);

    // Corrected typo in the SQL query for 'invoice status' to 'invoice_status'
    $sql_month_query = $month == 13 ? "" : "AND MONTH(invoice_date) = $month";

    $sql = "SELECT COUNT(invoice_id) AS number_outstanding_invoices FROM invoices WHERE YEAR(invoice_date) = $year AND (invoice_status = 'Unpaid' OR invoice_status = 'Partial') $sql_month_query";
    $result = mysqli_query($mysqli, $sql);
    if ($result) {
        $row = mysqli_fetch_assoc($result);
        return intval($row['number_outstanding_invoices']);
    }

    // Return 0 if the query fails or no result is found
    return 0;
}

function getUnbilledHours($year, $month)
{
    global $mysqli;

    $year = intval($year);
    $month = intval($month);

    $sql_month_query = $month == 13 ? "" : "AND MONTH(ticket_created_at) = $month";

    $sql = "SELECT SUM(ticket_reply_time_worked) AS total_unbilled_hours FROM ticket_replies
        LEFT JOIN tickets ON ticket_replies.ticket_reply_ticket_id = tickets.ticket_id
        WHERE YEAR(ticket_created_at) = $year
        AND ticket_status = 5
        AND ticket_billable = '1'
        AND ticket_invoice_id IS NULL $sql_month_query
    ";

    $result = mysqli_query($mysqli, $sql);
    $row = mysqli_fetch_assoc($result);
    return floatval($row['total_unbilled_hours']) ?? 0;
}

function getMonthlyProfit($year, $month)
{
    global $mysqli;

    $year = intval($year);
    $month = intval($month);

    $sql_payment_month_query = $month == 13 ? "" : "AND MONTH(payment_date) = $month";
    $sql_expense_month_query = $month == 13 ? "" : "AND MONTH(expense_date) = $month";


    $payment_sql = "SELECT SUM(payment_amount) AS total_income FROM payments WHERE YEAR(payment_date) = $year $sql_payment_month_query";
    $payment_result = mysqli_query($mysqli, $payment_sql);
    $payment_row = mysqli_fetch_assoc($payment_result);
    $total_income = floatval($payment_row['total_income']) ?? 0;

    $expense_sql = "SELECT SUM(expense_amount) AS total_expenses FROM expenses WHERE YEAR(expense_date) = $year $sql_expense_month_query";
    $expense_result = mysqli_query($mysqli, $expense_sql);
    $expense_row = mysqli_fetch_assoc($expense_result);
    $total_expenses = floatval($expense_row['total_expenses']) ?? 0;

    return $total_income - $total_expenses;
}

//Get account currency code
function getAccountCurrencyCode($account_id)
{
    global $mysqli;

    $account_id = intval($account_id);

    $sql = mysqli_query($mysqli, "SELECT account_currency_code FROM accounts WHERE account_id = $account_id");
    $row = mysqli_fetch_array($sql);
    $account_currency_code = nullable_htmlentities($row['account_currency_code']);
    return $account_currency_code;
}

function calculateAccountBalance($account_id)
{
    global $mysqli;

    $account_id = intval($account_id);

    $sql_account = mysqli_query($mysqli, "SELECT * FROM accounts LEFT JOIN account_types ON accounts.account_type = account_types.account_type_id WHERE account_archived_at  IS NULL AND account_id = $account_id ORDER BY account_name ASC; ");
    $row = mysqli_fetch_array($sql_account);
    $opening_balance = floatval($row['opening_balance']);
    $account_id = intval($row['account_id']);

    $sql_payments = mysqli_query($mysqli, "SELECT SUM(payment_amount) AS total_payments FROM payments WHERE payment_account_id = $account_id");
    $row = mysqli_fetch_array($sql_payments);
    $total_payments = floatval($row['total_payments']);

    $sql_revenues = mysqli_query($mysqli, "SELECT SUM(revenue_amount) AS total_revenues FROM revenues WHERE revenue_account_id = $account_id");
    $row = mysqli_fetch_array($sql_revenues);
    $total_revenues = floatval($row['total_revenues']);

    $sql_expenses = mysqli_query($mysqli, "SELECT SUM(expense_amount) AS total_expenses FROM expenses WHERE expense_account_id = $account_id");
    $row = mysqli_fetch_array($sql_expenses);
    $total_expenses = floatval($row['total_expenses']);

    $balance = $opening_balance + $total_payments + $total_revenues - $total_expenses;

    if ($balance == '') {
        $balance = '0.00';
    }

    if ($balance < 0) {
        $balance = 0;
    }

    return $balance;
}
function getClientRecurringInvoicesTotal($client_id)
{
    global $mysqli;

    $client_id = intval($client_id);

    $sql = "SELECT SUM(recurring_amount) AS recurring_total FROM recurring WHERE recurring_client_id = $client_id AND recurring_frequency = 'month'";
    $result = mysqli_query($mysqli, $sql);
    $row = mysqli_fetch_assoc($result);
    $total = floatval($row['recurring_total']) ?? 0;

    $year_sql = "SELECT SUM(recurring_amount) AS recurring_total FROM recurring WHERE recurring_client_id = $client_id AND recurring_frequency = 'year'";
    $year_result = mysqli_query($mysqli, $year_sql);
    $year_row = mysqli_fetch_assoc($year_result);
    $year_total = floatval($year_row['recurring_total']) ?? 0;

    $monthly_total = ($year_total / 12) + $total;
    return $monthly_total;
}

function getClientBalance($client_id) {
    global $pdo;
    if (!$pdo) {
        // Initialize $pdo if not already done
        $domain = $_SERVER['HTTP_HOST'];
        $config = require "/var/www/itflow-ng/config/$domain/config.php";
        $database = new Twetech\Nestogy\Database($config['db']);
        $pdo = $database->getConnection();
    }
    $accounting_model = new Accounting($pdo);

    return $accounting_model->getClientBalance($client_id);
}

function getClientAgingBalance($client_id, $from, $to) {

    global $mysqli;

    $client_id = intval($client_id);
    $from = intval($from);
    $to = intval($to);

    // Get from and to dates for the ageing balance by subtracting the number of days from the current date
    $from_date = date('Y-m-d', strtotime('-' . $from . ' days'));
    $to_date = date('Y-m-d', strtotime('-' . $to . ' days'));

    //Get all invoice ids that are not draft or cancelled from the date range
    $sql = "SELECT invoice_id FROM invoices
    WHERE invoice_client_id = $client_id
    AND invoice_status NOT LIKE 'Draft'
    AND invoice_status NOT LIKE 'Cancelled'
    AND invoice_date <= '$from_date'
    AND invoice_date >= '$to_date'";
    $sql_invoice_ids = mysqli_query($mysqli, $sql);

    $invoice_ids = [];
    while ($row = mysqli_fetch_array($sql_invoice_ids)) {
        $invoice_ids[] = $row['invoice_id'];
    }

    // Get Balance for the invoices in the date range
    $balance = 0;
    foreach ($invoice_ids as $invoice_id) {
        $balance += getInvoiceBalance($invoice_id);
    }

    return $balance;
}

function getClientPastDueBalance($client_id, $credits = false) {
    global $pdo;
    if (!$pdo) {
        // Initialize $pdo if not already done
        $config = require "/var/www/itflow-ng/config.php";
        $database = new Twetech\Nestogy\Database($config['db']);
        $pdo = $database->getConnection();
    }
    $accounting_model = new Accounting($pdo);
    return $accounting_model->getClientPastDueBalance($client_id);
}

function getClientPastDueMonths($client_id) {

    $client_id = intval($client_id);

    $monthly = getClientRecurringInvoicesTotal($client_id);
    $balance = getClientPastDueBalance($client_id);

    if ($monthly == 0) {
        return 0;
    }

    return $balance / $monthly;

}

function getMonthlyExpenses($year, $month, $number = false)
{

    $year = intval($year);
    $month = intval($month);
    if (!is_bool($number)) {
        $number = false;
    }


    global $mysqli;

    $sql_month_query = $month == 13 ? "" : "AND MONTH(expense_date) = $month";

    if ($number) {
        $sql = "SELECT COUNT(expense_id) AS total_expenses FROM expenses WHERE YEAR(expense_date) = $year $sql_month_query";
    } else {
        $sql = "SELECT SUM(expense_amount) AS total_expenses FROM expenses WHERE YEAR(expense_date) = $year $sql_month_query";
    }

    $result = mysqli_query($mysqli, $sql);
    $row = mysqli_fetch_assoc($result); 
    return $row['total_expenses'] ?? 0;
}

function getMonthlyUnbilledHours($year, $month, $number = false)
{
    global $mysqli;

    $year = intval($year);
    $month = intval($month);
    if (!is_bool($number)) {
        $number = false;
    }

    $sql_month_query = $month == 13 ? "" : "AND MONTH(ticket_reply_created_at) = $month";

    $sql = 
    "SELECT SUM(ticket_reply_time_worked) AS total_unbilled_hours FROM ticket_replies
    WHERE YEAR(ticket_reply_created_at) = $year
    ";
    $sql .= $sql_month_query;
    $result = mysqli_query($mysqli, $sql);
    $row = mysqli_fetch_assoc($result);
    $number_unbilled = $row['total_unbilled_hours']/10000;

    if ($number) {
        return $number_unbilled;
    } else {
        return $number_unbilled * 125;
    }

}

function getMonthlyCalendarEvents($year, $month)
{
    global $mysqli;
    $year = intval($year);
    $month = intval($month);
    return 0;
}

function getMonthlyUnassignedTickets($year, $month)
{
    global $mysqli;
    $year = intval($year);
    $month = intval($month);
    return 0;
}

function getMonthlyInvoices($year, $month, $number = false)
{
    $year = intval($year);
    $month = intval($month);
    if (!is_bool($number)) {
        $number = false;
    }

    switch ($number) {
        case true:
            return getMonthlyInvoicesNumber($year, $month);
        case false:
            return getMonthlyInvoicesAmount($year, $month);
    }
}

function getMonthlyInvoicesAmount($year, $month)
{
    global $mysqli;

    $year = intval($year);
    $month = intval($month);

    $sql_month_query = $month == 13 ? "" : "AND MONTH(invoice_date) = $month";

    $sql = "SELECT SUM(invoice_amount) AS total_invoices FROM invoices WHERE YEAR(invoice_date) = $year $sql_month_query";
    $result = mysqli_query($mysqli, $sql);
    $row = mysqli_fetch_assoc($result);
    return $row['total_invoices'] ?? 0;
}

function getMonthlyInvoicesNumber($year, $month)
{
    $month = intval($month);
    $year = intval($year);

    global $mysqli;

    $sql_month_query = $month == 13 ? "" : "AND MONTH(invoice_date) = $month";

    $sql = "SELECT COUNT(invoice_id) AS number_invoices FROM invoices WHERE YEAR(invoice_date) = $year $sql_month_query";
    $result = mysqli_query($mysqli, $sql);
    $row = mysqli_fetch_assoc($result);
    return $row['number_invoices'] ?? 0;
}

function getMonthlyMarkup($year, $month)
{
    global $mysqli;

    $year = intval($year);
    $month = intval($month);

    $sql_month_query = $month == 13 ? "" : "AND MONTH(invoice_date) = $month";

    $sql_invoices = "SELECT * FROM invoices WHERE YEAR(invoice_date) = $year $sql_month_query";
    $invoices_result = mysqli_query($mysqli, $sql_invoices);
    $invoices_row = mysqli_fetch_assoc($invoices_result);
    $total_amount = 0;
    $total_cost = 0;
    foreach ($invoices_result as $invoices_row) {
        $invoice_amount = $invoices_row['invoice_amount'];

        $total_amount += $invoice_amount;

        $sql_items = "SELECT * FROM invoice_items
        LEFT JOIN products ON invoice_items.item_product_id = products.product_id
        WHERE item_invoice_id = " . $invoices_row['invoice_id'];
        $items_result = mysqli_query($mysqli, $sql_items);
        foreach ($items_result as $items_row) {
            $total_cost += $items_row['product_cost'] * $items_row['item_quantity'];
        }
    }
    $total_cost == 0 ? $total_cost = 1 : $total_cost;
    
    return $total_amount / $total_cost;
}

function clientSendDisconnect($client_id){

    global $mysqli;
    $client_id = intval($client_id);
    return false;
}

function getPaymentForCategoryAndMonth($category_id, $month, $year)
{
    global $mysqli;

    $category_id = intval($category_id);
    $month = intval($month);
    $year = intval($year);

    $sql_payments = mysqli_query($mysqli,
        "SELECT SUM(payment_amount) AS payment_amount_for_month
        FROM payments
        LEFT JOIN invoices ON payment_invoice_id = invoice_id
        WHERE invoice_category_id = $category_id
        AND YEAR(payment_date) = $year
        AND MONTH(payment_date) = $month"
    );
    $row = mysqli_fetch_array($sql_payments);
    return floatval($row['payment_amount_for_month']);
}

function getInvoiceBalance($invoice_id)
{
    global $pdo;
    if (!$pdo) {
        // Initialize $pdo if not already done
        $domain = $_SERVER['HTTP_HOST'];
        $config = require "/var/www/itflow-ng/config/$domain/config.php";
        $database = new Twetech\Nestogy\Database($config['db']);
        $pdo = $database->getConnection();
    }
    $accounting_model = new Accounting($pdo);

    return $accounting_model->getInvoiceBalance($invoice_id);
}

function getItemTotal($item_id) {
    global $mysqli;
    $sql = mysqli_query($mysqli, "SELECT * FROM invoice_items WHERE item_id = $item_id");
    $row = mysqli_fetch_array($sql);
    $item_price = floatval($row['item_price']);
    $item_qty = floatval($row['item_quantity']);
    $item_discount = floatval($row['item_discount']);
    $item_tax = floatval($row['item_tax']);
    $item_subtotal = $item_price * $item_qty;
    $item_total = $item_subtotal + $item_tax - $item_discount;
    return $item_total;
}

function getInvoiceAmount($invoice_id)
{
    global $pdo;
    if (!$pdo) {
        // Initialize $pdo if not already done
        $domain = $_SERVER['HTTP_HOST'];
        $config = require "/var/www/itflow-ng/config/$domain/config.php";
        $database = new \Twetech\Nestogy\Database($config['db']);
        $pdo = $database->getConnection();
    }
    $accounting_model = new Accounting($pdo);
    return $accounting_model->getInvoiceAmount($invoice_id);
}

function getInvoicePayments($invoice_id)
{
    global $mysqli;

    $invoice_id = intval($invoice_id);

    $sql = "SELECT SUM(payment_amount) AS total_payments FROM payments WHERE payment_invoice_id = $invoice_id";
    $result = mysqli_query($mysqli, $sql);
    $row = mysqli_fetch_assoc($result);
    return floatval($row['total_payments']);
}

function getMostRecentPaymentDate($invoice_id)
{
    global $mysqli;

    $invoice_id = intval($invoice_id);

    $sql = "SELECT payment_date FROM payments WHERE payment_invoice_id = $invoice_id ORDER BY payment_date DESC LIMIT 1";
    $result = mysqli_query($mysqli, $sql);
    $num = mysqli_num_rows($result);
    if ($num > 0) {
        $row = mysqli_fetch_assoc($result);
        return $row['payment_date'];
    } else {
        return null;
    }
}

function createCreditForInvoice($invoice_id, $credit_amount) {

    global $pdo;
    if (!$pdo) {
        // Initialize $pdo if not already done
        $domain = $_SERVER['HTTP_HOST'];
        $config = require "/var/www/itflow-ng/config/$domain/config.php";
        $database = new \Twetech\Nestogy\Database($config['db']);
        $pdo = $database->getConnection();
    }

    $invoice_id = intval($invoice_id);
    $credit_amount = floatval($credit_amount);

    // Create new payment with the credit amount, and link it to the invoice
    $sql = "INSERT INTO payments SET
        payment_invoice_id = $invoice_id,
        payment_amount = $credit_amount,
        payment_date = CURDATE(),
        payment_method = 'Credit',
        payment_currency_code = 'USD',
        payment_reference = 'Credit',
        payment_account_id = 1
    ";

    $pdo->query($sql);
    $payment_id = $pdo->lastInsertId();

    // Create credit for the payment
    $sql = "INSERT INTO credits SET
        credit_payment_id = $payment_id,
        credit_amount = $credit_amount,
        credit_date = CURDATE(),
        credit_currency_code = 'USD',
        credit_client_id = (SELECT invoice_client_id FROM invoices WHERE invoice_id = $invoice_id),
        credit_reference = 'Credit'
        ";
    $pdo->query($sql);
    $credit_id = $pdo->lastInsertId();
    
    if ($credit_id) {
        return $credit_id;
    } else {
        return false;
    }
}

function getRecurringInvoiceAmount($recurring_id) {
    global $mysqli;
    $recurring_id = intval($recurring_id);
    $sql = "SELECT * FROM invoice_items WHERE item_recurring_id = $recurring_id";
    $result = mysqli_query($mysqli, $sql);
    $recurring_amount = 0;
    while ($row = mysqli_fetch_assoc($result)) {
        $recurring_amount += getItemTotal($row['item_id']);
    }
    return $recurring_amount;
}

function createExpenseFromTransaction($transaction_id) {
    global $mysqli;
    $transaction_id = intval($transaction_id);
    $sql = "SELECT * FROM bank_transactions WHERE transaction_id = $transaction_id";
    $result = mysqli_query($mysqli, $sql);
    $row = mysqli_fetch_assoc($result);

    $expense_amount = floatval($row['transaction_amount']);
    $expense_date = $row['transaction_date'];
    $expense_currency_code = 'USD';
    $expense_reference = $row['name'];
    $transaction_account_id = $row['bank_account_id'];

    $sql = "SELECT * FROM accounts WHERE account_id = $transaction_account_id";
    $result = mysqli_query($mysqli, $sql);
    $row = mysqli_fetch_assoc($result);
    $expense_account_id = $row['account_expense_id'];

    $sql = "INSERT INTO expenses SET
        expense_amount = $expense_amount,
        expense_date = '$expense_date',
        expense_currency_code = '$expense_currency_code',
        expense_reference = '$expense_reference',
        expense_account_id = $expense_account_id
    ";
    mysqli_query($mysqli, $sql);
    $expense_id = mysqli_insert_id($mysqli);

    return $expense_id;
}
