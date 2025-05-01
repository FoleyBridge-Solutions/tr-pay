<?php



require_once "/var/www/itflow-ng/src/Model/Accounting.php";
require_once "/var/www/itflow-ng/src/Model/Client.php";
require_once "/var/www/itflow-ng/src/Database.php";
require_once "/var/www/itflow-ng/portal/guest_header.php";
require_once "/var/www/itflow-ng/portal/portal_header.php";


use Twetech\Nestogy\Database;
use Twetech\Nestogy\Model\Accounting;

$config = require '/var/www/itflow-ng/config/nestogy/config.php';
$database = new Database($config['db']);
$pdo = $database->getConnection();

$url_key = sanitizeInput($_GET['url_key']);
$quote_id = intval($_GET['quote_id']);
$accounting = new Accounting($pdo);

if (!isset($_GET['quote_id'], $_GET['url_key'])) {
    echo "<br><h2>Oops, something went wrong! Please raise a ticket if you believe this is an error.</h2>";
    require_once "portal/guest_footer.php";

    exit();
}


$url_key = sanitizeInput($_GET['url_key']);
$quote_id = intval($_GET['quote_id']);

$sql = mysqli_query(
    $mysqli,
    "SELECT * FROM quotes
    LEFT JOIN clients ON quote_client_id = client_id
    LEFT JOIN contacts ON clients.client_id = contacts.contact_client_id AND contact_primary = 1
    LEFT JOIN locations ON clients.client_id = locations.location_client_id AND location_primary = 1
    WHERE quote_id = $quote_id
    AND quote_url_key = '$url_key'"
);

if (mysqli_num_rows($sql) !== 1) {
    // Invalid quote/key
    echo "<br><h2>Oops, something went wrong! Please raise a ticket if you believe this is an error.</h2>";
    require_once "portal/guest_footer.php";

    exit();
}

$row = mysqli_fetch_array($sql);

$quote_id = intval($row['quote_id']);
$quote_prefix = nullable_htmlentities($row['quote_prefix']);
$quote_number = intval($row['quote_number']);
$quote_status = nullable_htmlentities($row['quote_status']);
$quote_date = nullable_htmlentities($row['quote_date']);
$quote_expire = nullable_htmlentities($row['quote_expire']);
$quote_discount = floatval($row['quote_discount_amount']);
$quote_amount = $accounting->getQuoteTotal($quote_id);
$quote_currency_code = nullable_htmlentities($row['quote_currency_code']);
$quote_note = nullable_htmlentities($row['quote_note']);
$category_id = intval($row['category_id']);
$client_id = intval($row['client_id']);
$client_name = nullable_htmlentities($row['client_name']);
$client_name_escaped = sanitizeInput($row['client_name']);
$location_address = nullable_htmlentities($row['location_address']);
$location_city = nullable_htmlentities($row['location_city']);
$location_state = nullable_htmlentities($row['location_state']);
$location_zip = nullable_htmlentities($row['location_zip']);
$contact_email = nullable_htmlentities($row['contact_email']);
$contact_phone = formatPhoneNumber($row['contact_phone']);
$contact_extension = nullable_htmlentities($row['contact_extension']);
$contact_mobile = formatPhoneNumber($row['contact_mobile']);
$client_website = nullable_htmlentities($row['client_website']);
$client_currency_code = nullable_htmlentities($row['client_currency_code']);
$client_net_terms = intval($row['client_net_terms']);
if ($client_net_terms == 0) {
    $client_net_terms = intval($row['config_default_net_terms']);
}

$sql = mysqli_query($mysqli, "SELECT * FROM companies, settings WHERE companies.company_id = settings.company_id AND companies.company_id = 1");
$row = mysqli_fetch_array($sql);
$company_name = nullable_htmlentities($row['company_name']);
$company_address = nullable_htmlentities($row['company_address']);
$company_city = nullable_htmlentities($row['company_city']);
$company_state = nullable_htmlentities($row['company_state']);
$company_zip = nullable_htmlentities($row['company_zip']);
$company_phone = formatPhoneNumber($row['company_phone']);
$company_email = nullable_htmlentities($row['company_email']);
$company_website = nullable_htmlentities($row['company_website']);
$company_logo = nullable_htmlentities($row['company_logo']);
if (!empty($company_logo)) {
    $company_logo_base64 = base64_encode(file_get_contents("/uploads/settings/$company_logo"));
}
$company_locale = nullable_htmlentities($row['company_locale']);
$config_quote_footer = nullable_htmlentities($row['config_quote_footer']);

//Set Currency Format
$currency_format = numfmt_create($company_locale, NumberFormatter::CURRENCY);

//Update status to Viewed only if invoice_status = "Sent"
if ($quote_status == 'Sent') {
    mysqli_query($mysqli, "UPDATE quotes SET quote_status = 'Viewed' WHERE quote_id = $quote_id");
}

//Mark viewed in history
mysqli_query($mysqli, "INSERT INTO history SET history_status = '$quote_status', history_description = 'Quote viewed - $ip - $os - $browser', history_quote_id = $quote_id");


?>

    <div class="card">

        <div class="card-header d-print-none">

            <div class="float-right">
                <button class="btn btn-label-primary" id="downloadBtn"><i class="fas fa-fw fa-download mr-2"></i>Download</button>
                <a class="btn btn-label-primary" href="#" onclick="window.print();"><i class="fas fa-fw fa-print mr-2"></i>Print</a>
            </div>
        </div>
        <div class="card-body">

            <div class="row mb-4">
                <div class="col-sm-2">
                    <img class="img-fluid" src="<?= "/uploads/settings/$company_logo"; ?>">
                </div>
                <div class="col-sm-10">
                    <h3 class="text-right"><strong>Estimate</strong><br><small class="text-secondary"><?= "$quote_prefix$quote_number"; ?></small></h3>
                </div>
            </div>

            <div class="row mb-4">

                <div class="col-sm">
                    <ul class="list-unstyled">
                        <li><h4><strong><?= $company_name; ?></strong></h4></li>
                        <li><?= $company_address; ?></li>
                        <li><?= "$company_city $company_state $company_zip"; ?></li>
                        <li><?= $company_phone; ?></li>
                        <li><?= $company_email; ?></li>
                    </ul>

                </div>

                <div class="col-sm">

                    <ul class="list-unstyled text-right">
                        <li><h4><strong><?= $client_name; ?></strong></h4></li>
                        <li><?= $location_address; ?></li>
                        <li><?= "$location_city $location_state $location_zip"; ?></li>
                        <li><?= "$contact_phone $contact_extension"; ?></li>
                        <li><?= $contact_mobile; ?></li>
                        <li><?= $contact_email; ?></li>
                    </ul>

                </div>
            </div>
            <div class="row mb-4">
                <div class="col-sm-8">
                </div>
                <div class="col-sm-4">
                    <table id=responsive class="responsive table">
                        <tr>
                            <td>Date</td>
                            <td class="text-right"><?= $quote_date; ?></td>
                        </tr>
                        <tr class="text-bold">
                            <td>Expire</td>
                            <td class="text-right"><?= $quote_expire; ?></td>
                        </tr>
                    </table>
                </div>
            </div>

            <?php $sql_items = mysqli_query($mysqli, "SELECT * FROM invoice_items WHERE item_quote_id = $quote_id ORDER BY item_order ASC"); ?>

            <div class="row mb-4">
                <div class="col-md-12">
                    <div class="card">
                                <div class="card-datatable table-responsive container-fluid  pt-0">               
                            <table id=responsive class="responsive table">
                                <thead>
                                <tr>
                                    <th>Product</th>
                                    <th>Description</th>
                                    <th class="text-center">Qty</th>
                                    <th class="text-right">Price</th>
                                    <th class="text-right">Tax</th>
                                    <th class="text-right">Total</th>
                                </tr>
                                </thead>
                                <tbody>
                                <?php

                                $total_tax = $sub_total = 0; // Default 0

                                while ($row = mysqli_fetch_array($sql_items)) {
                                    $item_id = intval($row['item_id']);
                                    $item_name = nullable_htmlentities($row['item_name']);
                                    $item_description = $row['item_description'];
                                    $item_quantity = floatval($row['item_quantity']);
                                    $item_price = floatval($row['item_price']);
                                    $item_subtotal = $item_price * $item_quantity;
                                    $item_total = $accounting->getLineItemTotal($item_id);
                                    $item_tax = $item_total - $item_subtotal;
                                    $total_tax = $item_tax + $total_tax;
                                    $sub_total = $item_price * $item_quantity + $sub_total;

                                    ?>

                                    <tr>
                                        <td><?= $item_name; ?></td>
                                        <td><?= nl2br(html_entity_decode($item_description)); ?></td>
                                        <td class="text-center"><?= $item_quantity; ?></td>
                                        <td class="text-right"><?= numfmt_format_currency($currency_format, $item_price, $quote_currency_code); ?></td>
                                        <td class="text-right"><?= numfmt_format_currency($currency_format, $item_tax, $quote_currency_code); ?></td>
                                        <td class="text-right"><?= numfmt_format_currency($currency_format, $item_total, $quote_currency_code); ?></td>
                                    </tr>

                                    <?php

                                }

                                ?>

                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>

            <div class="row mb-4">
                <div class="col-sm-7">
                    <?php if (!empty($quote_note)) { ?>
                        <div class="card">
                            <div class="card-body">
                                <?= nl2br($quote_note); ?>
                            </div>
                        </div>
                    <?php } ?>
                </div>

                <div class="col-sm-3 offset-sm-2">
                    <table id=responsive class="responsive table table-borderless">
                        <tbody>
                        <tr class="border-bottom">
                            <td>Subtotal</td>
                            <td class="text-right"><?= numfmt_format_currency($currency_format, $sub_total, $quote_currency_code); ?></td>
                        </tr>
                        <?php if ($quote_discount > 0) { ?>
                            <tr class="border-bottom">
                                <td>Discount</td>
                                <td class="text-right"><?= numfmt_format_currency($currency_format, -$quote_discount, $quote_currency_code); ?></td>
                            </tr>
                        <?php } ?>
                        <?php if ($total_tax > 0) { ?>
                            <tr class="border-bottom">
                                <td>Tax</td>
                                <td class="text-right"><?= numfmt_format_currency($currency_format, $total_tax, $quote_currency_code); ?></td>
                            </tr>
                        <?php } ?>
                        <tr class="border-bottom">
                            <td><strong>Total</strong></td>
                            <td class="text-right"><strong><?= numfmt_format_currency($currency_format, $quote_amount, $quote_currency_code); ?></strong></td>
                        </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <hr class="mt-5">

            <div class="text-center"><?= nl2br($config_quote_footer); ?></div>
            <div class="">
                <?php
                    if ($quote_status == "Sent" || $quote_status == "Viewed" && strtotime($quote_expire) > strtotime("now")) {
                        ?>
                        <a class="btn btn-success" href="/portal/guest_post.php?accept_quote=<?= $quote_id; ?>&url_key=<?= $url_key; ?>">
                            <i class="fas fa-fw fa-thumbs-up mr-2"></i>Accept
                        </a>
                        <a class="btn btn-danger" href="/portal/guest_post.php?decline_quote=<?= $quote_id; ?>&url_key=<?= $url_key; ?>">
                            <i class="fas fa-fw fa-thumbs-down mr-2"></i>Decline
                        </a>
                    <?php } ?>
            </div>

        </div>
    </div>

    <script src="https://cdnjs.cloudflare.com/ajax/libs/pdfmake/0.1.68/pdfmake.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/pdfmake/0.1.68/vfs_fonts.js"></script>
    <script>

        var docDefinition = {
            info: {
                title: <?= json_encode(html_entity_decode($company_name) . "- Estimate") ?>,
                author: <?= json_encode(html_entity_decode($company_name)) ?>
            },

            //watermark: {text: '<?= $quote_status; ?>', color: 'lightgrey', opacity: 0.3, bold: true, italics: false},

            content: [
                // Header
                {
                    columns: [
                        <?php if (!empty($company_logo_base64)) { ?>
                        {
                            image: <?= json_encode("data:image;base64,$company_logo_base64") ?>,
                            width: 120
                        },
                        <?php } ?>

                        [
                            {
                                text: 'Estimate',
                                style: 'invoiceTitle',
                                width: '*'
                            },
                            {
                                text: <?= json_encode(html_entity_decode("$quote_prefix$quote_number")) ?>,
                                style: 'invoiceNumber',
                                width: '*'
                            },
                        ],
                    ],
                },
                // Billing Headers
                {
                    columns: [
                        {
                            text: <?= json_encode(html_entity_decode($company_name)) ?>,
                            style: 'invoiceBillingTitle'
                        },
                        {
                            text: <?= json_encode(html_entity_decode($client_name)) ?>,
                            style: 'invoiceBillingTitleClient'
                        },
                    ]
                },
                // Billing Address
                {
                    columns: [
                        {
                            text: <?= json_encode(html_entity_decode("$company_address \n $company_city $company_state $company_zip \n $company_phone \n $company_website")) ?>,
                            style: 'invoiceBillingAddress'
                        },
                        {
                            text: <?= json_encode(html_entity_decode("$location_address \n $location_city $location_state $location_zip \n $contact_email \n $contact_phone")) ?>,
                            style: 'invoiceBillingAddressClient'
                        },
                    ]
                },
                //Invoice Dates Table
                {
                    table: {
                        // headers are automatically repeated if the table spans over multiple pages
                        // you can declare how many rows should be treated as headers
                        headerRows: 0,
                        widths: [ '*',80, 80 ],

                        body: [
                            // Total
                            [
                                {
                                    text: '',
                                    rowSpan: 3
                                },
                                {},
                                {},
                            ],
                            [
                                {},
                                {
                                    text: 'Date',
                                    style: 'invoiceDateTitle'
                                },
                                {
                                    text: <?= json_encode(html_entity_decode($quote_date)) ?>,
                                    style: 'invoiceDateValue'
                                },
                            ],
                            [
                                {},
                                {
                                    text: 'Expire',
                                    style: 'invoiceDueDateTitle'
                                },
                                {
                                    text: <?= json_encode(html_entity_decode($quote_expire)) ?>,
                                    style: 'invoiceDueDateValue'
                                },
                            ],
                        ]
                    }, // table
                    layout: 'lightHorizontalLines'
                },
                // Line breaks
                '\n\n',
                // Items
                {
                    table: {
                        // headers are automatically repeated if the table spans over multiple pages
                        // you can declare how many rows should be treated as headers
                        headerRows: 1,
                        widths: [ '*', 40, 'auto', 'auto', 80 ],

                        body: [
                            // Table Header
                            [
                                {
                                    text: 'Product',
                                    style: [ 'itemsHeader', 'left']
                                },
                                {
                                    text: 'Qty',
                                    style: [ 'itemsHeader', 'center']
                                },
                                {
                                    text: 'Price',
                                    style: [ 'itemsHeader', 'right']
                                },
                                {
                                    text: 'Tax',
                                    style: [ 'itemsHeader', 'right']
                                },
                                {
                                    text: 'Total',
                                    style: [ 'itemsHeader', 'right']
                                }
                            ],
                            // Items
                            <?php
                            $total_tax = 0;
                            $sub_total = 0;

                            $sql_quote_items = mysqli_query($mysqli, "SELECT * FROM invoice_items WHERE item_quote_id = $quote_id ORDER BY item_order ASC");

                            while ($row = mysqli_fetch_array($sql_quote_items)) {
                                $item_id = intval($row['item_id']);
                                $item_name = nullable_htmlentities($row['item_name']);
                                $item_description = $row['item_description'];
                                $item_quantity = floatval($row['item_quantity']);
                                $item_price = floatval($row['item_price']);
                                $item_subtotal = $item_price * $item_quantity;
                                $item_total = $accounting->getLineItemTotal($item_id);
                                $item_tax = $item_total - $item_subtotal;
                                $total_tax = $item_tax + $total_tax;
                                $sub_total = $item_price * $item_quantity + $sub_total;
                            ?>
                            // Item
                            [
                                [
                                    {
                                        text: <?= json_encode(strip_tags(html_entity_decode($item_name))) ?>,
                                        style: 'itemTitle'
                                    },
                                    {
                                        text: <?= json_encode(strip_tags(html_entity_decode($item_description))) ?>,
                                        style: 'itemDescription'
                                    }
                                ],
                                {
                                    text: <?= json_encode($item_quantity) ?>,
                                    style: 'itemQty'
                                },
                                {
                                    text: <?= json_encode(numfmt_format_currency($currency_format, $item_price, $quote_currency_code)) ?>,
                                    style: 'itemNumber'
                                },
                                {
                                    text: <?= json_encode(numfmt_format_currency($currency_format, $item_tax, $quote_currency_code)) ?>,
                                    style: 'itemNumber'
                                },
                                {
                                    text: <?= json_encode(numfmt_format_currency($currency_format, $item_total, $quote_currency_code)) ?>,
                                    style: 'itemNumber'
                                }
                            ],
                            <?php
                            }
                            ?>
                            // END Items
                        ]
                    }, // table
                    layout: 'lightHorizontalLines'
                },
                // TOTAL
                {
                    table: {
                        // headers are automatically repeated if the table spans over multiple pages
                        // you can declare how many rows should be treated as headers
                        headerRows: 0,
                        widths: [ '*','auto', 80 ],

                        body: [
                            // Total
                            [
                                {
                                    text: 'Notes',
                                    style:'notesTitle'
                                },
                                {},
                                {}
                            ],
                            [
                                {
                                    rowSpan: '*',
                                    text: <?= json_encode(strip_tags(html_entity_decode($quote_note))) ?>,
                                    style: 'notesText'
                                },
                                {
                                    text: 'Subtotal',
                                    style: 'itemsFooterSubTitle'
                                },
                                {
                                    text: <?= json_encode(numfmt_format_currency($currency_format, $sub_total, $quote_currency_code)) ?>,
                                    style: 'itemsFooterSubValue'
                                }
                            ],
                            <?php if ($quote_discount > 0) { ?>
                            [
                                {},
                                {
                                    text: 'Discount',
                                    style: 'itemsFooterSubTitle'
                                },
                                {
                                    text: <?= json_encode(numfmt_format_currency($currency_format, -$quote_discount, $quote_currency_code)) ?>,
                                    style: 'itemsFooterSubValue'
                                }
                            ],
                            <?php } ?>
                            <?php if ($total_tax > 0) { ?>
                            [
                                {},
                                {
                                    text: 'Tax',
                                    style: 'itemsFooterSubTitle'
                                },
                                {
                                    text: <?= json_encode(numfmt_format_currency($currency_format, $total_tax, $quote_currency_code)) ?>,
                                    style: 'itemsFooterSubValue'
                                }
                            ],
                            <?php } ?>
                            [
                                {},
                                {
                                    text: 'Total',
                                    style: 'itemsFooterTotalTitle'
                                },
                                {
                                    text: <?= json_encode(numfmt_format_currency($currency_format, $quote_amount, $quote_currency_code)) ?>,
                                    style: 'itemsFooterTotalValue'
                                }
                            ],
                        ]
                    }, // table
                    layout: 'lightHorizontalLines'
                },
                // TERMS / FOOTER
                {
                    text: <?= json_encode(html_entity_decode($config_quote_footer)); ?>,
                    style: 'documentFooterCenter'
                }
            ], //End Content,
            styles: {
                // Document Footer
                documentFooterCenter: {
                    fontSize: 9,
                    margin: [10,50,10,10],
                    alignment: 'center'
                },
                // Invoice Title
                invoiceTitle: {
                    fontSize: 18,
                    bold: true,
                    alignment: 'right',
                    margin: [0,0,0,3]
                },
                // Invoice Number
                invoiceNumber: {
                    fontSize: 14,
                    alignment: 'right'
                },
                // Billing Headers
                invoiceBillingTitle: {
                    fontSize: 14,
                    bold: true,
                    alignment: 'left',
                    margin: [0,20,0,5]
                },
                invoiceBillingTitleClient: {
                    fontSize: 14,
                    bold: true,
                    alignment: 'right',
                    margin: [0,20,0,5]
                },
                // Billing Details
                invoiceBillingAddress: {
                    fontSize: 10,
                    lineHeight: 1.2
                },
                invoiceBillingAddressClient: {
                    fontSize: 10,
                    lineHeight: 1.2,
                    alignment: 'right',
                    margin: [0,0,0,30]
                },
                // Invoice Dates
                invoiceDateTitle: {
                    fontSize: 10,
                    alignment: 'left',
                    margin: [0,5,0,5]
                },
                invoiceDateValue: {
                    fontSize: 10,
                    alignment: 'right',
                    margin: [0,5,0,5]
                },
                // Invoice Due Dates
                invoiceDueDateTitle: {
                    fontSize: 10,
                    bold: true,
                    alignment: 'left',
                    margin: [0,5,0,5]
                },
                invoiceDueDateValue: {
                    fontSize: 10,
                    bold: true,
                    alignment: 'right',
                    margin: [0,5,0,5]
                },
                // Items Header
                itemsHeader: {
                    fontSize: 10,
                    margin: [0,5,0,5],
                    bold: true,
                    alignment: 'right'
                },
                // Item Title
                itemTitle: {
                    fontSize: 10,
                    bold: true,
                    margin: [0,5,0,3]
                },
                itemDescription: {
                    italics: true,
                    fontSize: 9,
                    lineHeight: 1.1,
                    margin: [0,3,0,5]
                },
                itemQty: {
                    fontSize: 10,
                    margin: [0,5,0,5],
                    alignment: 'center'
                },
                itemNumber: {
                    fontSize: 10,
                    margin: [0,5,0,5],
                    alignment: 'right'
                },
                itemTotal: {
                    fontSize: 10,
                    margin: [0,5,0,5],
                    bold: true,
                    alignment: 'right'
                },
                // Items Footer (Subtotal, Total, Tax, etc)
                itemsFooterSubTitle: {
                    fontSize: 10,
                    margin: [0,5,0,5],
                    alignment: 'right'
                },
                itemsFooterSubValue: {
                    fontSize: 10,
                    margin: [0,5,0,5],
                    bold: false,
                    alignment: 'right'
                },
                itemsFooterTotalTitle: {
                    fontSize: 10,
                    margin: [0,5,0,5],
                    bold: true,
                    alignment: 'right'
                },
                itemsFooterTotalValue: {
                    fontSize: 10,
                    margin: [0,5,0,5],
                    bold: true,
                    alignment: 'right'
                },
                notesTitle: {
                    fontSize: 10,
                    bold: true,
                    margin: [0,5,0,5]
                },
                notesText: {
                    fontSize: 9,
                    margin: [0,5,50,5]
                },
                left: {
                    alignment: 'left'
                },
                center: {
                    alignment: 'center'
                },
            },
            defaultStyle: {
                columnGap: 20,
            }
        }

        // Add this new code
        document.getElementById('downloadBtn').addEventListener('click', function() {
            pdfMake.createPdf(docDefinition).download('quote_<?= $quote_prefix . $quote_number ?>.pdf');
        });
    </script>


<?php
require_once "portal/guest_footer.php";





