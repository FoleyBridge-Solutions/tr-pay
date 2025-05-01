<?php
/*
 * Client Portal
 * Landing / Home page for the client portal
 */



require_once "/var/www/itflow-ng/includes/inc_portal.php";


// Ticket status from GET
if (!isset($_GET['status'])) {
    $status = 'Open';
    $ticket_status_snippet = "ticket_status != '5'";
} elseif (isset($_GET['status']) && ($_GET['status']) == 'Open') {
    $status = 'Open';
    $ticket_status_snippet = "ticket_status != '5'";
} elseif (isset($_GET['status']) && ($_GET['status']) == 'Closed') {
    $status = 'Closed';
    $ticket_status_snippet = "ticket_status = '5'";
} else {
    $status = '%';
    $ticket_status_snippet = "ticket_status LIKE '%'";
}

$contact_tickets = mysqli_query($mysqli, "SELECT * FROM tickets LEFT JOIN contacts ON ticket_contact_id = contact_id LEFT JOIN ticket_statuses ON ticket_status = ticket_status_id WHERE $ticket_status_snippet AND ticket_contact_id = $contact_id AND ticket_client_id = $client_id ORDER BY ticket_id DESC");

//Get Total tickets closed
$sql_total_tickets_closed = mysqli_query($mysqli, "SELECT COUNT(ticket_id) AS total_tickets_closed FROM tickets WHERE ticket_status = '5' AND ticket_client_id = $client_id AND ticket_contact_id = $contact_id");
$row = mysqli_fetch_array($sql_total_tickets_closed);
$total_tickets_closed = intval($row['total_tickets_closed']);

//Get Total tickets open
$sql_total_tickets_open = mysqli_query($mysqli, "SELECT COUNT(ticket_id) AS total_tickets_open FROM tickets WHERE ticket_status != '5' AND ticket_client_id = $client_id AND ticket_contact_id = $contact_id");
$row = mysqli_fetch_array($sql_total_tickets_open);
$total_tickets_open = intval($row['total_tickets_open']);

//Get Total tickets
$sql_total_tickets = mysqli_query($mysqli, "SELECT COUNT(ticket_id) AS total_tickets FROM tickets WHERE  ticket_client_id = $client_id AND ticket_contact_id = $contact_id");
$row = mysqli_fetch_array($sql_total_tickets);
$total_tickets = intval($row['total_tickets']);

$statuses = [
    1 => 'New',
    2 => 'Open',
    3 => 'Waiting',
    4 => 'Resolved',
    5 => 'Closed'
];
?>

<div class="row">

    <div class="col-md-10">
        <div class="card-datatable table-responsive">
            <table class="datatables-basic table border-top table-striped table-hover">
                <thead class="text-dark">
                    <tr>
                        <th>Number</th>
                        <th>Subject</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>

                <?php
                while ($row = mysqli_fetch_array($contact_tickets)) {
                    $ticket_id = intval($row['ticket_id']);
                    $ticket_prefix = nullable_htmlentities($row['ticket_prefix']);
                    $ticket_number = intval($row['ticket_number']);
                    $ticket_subject = nullable_htmlentities($row['ticket_subject']);
                    $ticket_status = nullable_htmlentities($row['ticket_status_name']);
                ?>

                    <tr>
                        <td>
                            <a href="ticket.php?id=<?= $ticket_id; ?>"><?= "$ticket_prefix$ticket_number"; ?></a>
                        </td>
                        <td>
                            <a href="ticket.php?id=<?= $ticket_id; ?>"><?= $ticket_subject; ?></a>
                        </td>
                        <td><?= $statuses[$ticket_status]; ?></td>
                    </tr>
                <?php
                }
                ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="col-md-2">

        <a href="ticket_add.php" class="btn btn-label-primary btn-block">New ticket</a>

        <hr>

        <a href="?status=Open" class="btn btn-danger btn-block p-3 mb-3 text-left">My Open tickets | <strong><?= $total_tickets_open ?></strong></a>

        <a href="?status=Closed" class="btn btn-success btn-block p-3 mb-3 text-left">Resolved tickets | <strong><?= $total_tickets_closed ?></strong></a>

        <a href="?status=%" class="btn btn-light btn-block p-3 mb-3 text-left">All my tickets | <strong><?= $total_tickets ?></strong></a>
        <?php
        if ($contact_primary == 1 || $contact_is_technical_contact) {
        ?>

        <hr>

        <a href="ticket_view_all.php" class="btn btn-dark btn-block p-2 mb-3">All Tickets</a>

        <?php
        }
        ?>

    </div>
</div>

<?php require_once "portal_footer.php";
?>
