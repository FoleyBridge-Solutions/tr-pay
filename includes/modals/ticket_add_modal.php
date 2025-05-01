<?php require_once "/var/www/itflow-ng/includes/inc_all_modal.php";

if (isset($_GET['project_id'])) {
    $project_id = intval($_GET['project_id']);
    $project_sql = mysqli_query($mysqli, "SELECT * FROM projects WHERE project_id = $project_id");
    $project_row = mysqli_fetch_array($project_sql);
    $_GET['client_id'] = intval($project_row['project_client_id']);
}

$client_id = intval($_GET['client_id']);

?>

<div class="modal fade" id="addTicketModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content bg-dark">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-fw fa-life-ring mr-2"></i>New Ticket</h5>
                <button type="button" class="close text-white" data-bs-dismiss="modal">
                    <span>&times;</span>
                </button>
            </div>
            <form action="/post.php" method="post" autocomplete="off">
                <div class="modal-body bg-white">
                    <?php if (isset($_GET['client_id'])) { ?>
                        <ul class="nav nav-pills  mb-3">
                            <li class="nav-item">
                                <a class="nav-link active" role="tab" data-bs-toggle="tab" href="#pills-details"><i class="fa fa-fw fa-life-ring mr-2"></i>Details</a>
                            </li>
                            <li class="nav-item">
                                <a class="nav-link" role="tab" data-bs-toggle="tab" href="#pills-contacts"><i class="fa fa-fw fa-users mr-2"></i>Contact</a>
                            </li>
                            <li class="nav-item">
                                <a class="nav-link" role="tab" data-bs-toggle="tab" href="#pills-assets"><i class="fa fa-fw fa-desktop mr-2"></i>Asset</a>
                            </li>
                            <li class="nav-item">
                                <a class="nav-link" role="tab" data-bs-toggle="tab" href="#pills-locations"><i class="fa fa-fw fa-map-marker-alt mr-2"></i>Location</a>
                            </li>
                            <li class="nav-item">
                                <a class="nav-link" role="tab" data-bs-toggle="tab" href="#pills-vendors"><i class="fa fa-fw fa-building mr-2"></i>Vendor</a>
                            </li>
                        </ul>

                        <hr>

                    <?php } ?>

                    <div class="tab-content">

                        <div class="tab-pane fade show active" id="pills-details">

                            <div class="form-group">
                                <label>Subject <strong class="text-danger">*</strong></label>
                                <div class="input-group">
                                    <div class="input-group-prepend">
                                        <span class="input-group-text"><i class="fa fa-fw fa-tag"></i></span>
                                    </div>
                                    <?php if (isset($_GET['project_id'])) { ?>
                                        <input type="text" class="form-control" name="subject" required value="<?= $project_row['project_prefix'] . $project_row['project_id'] . ': ' . $project_row['project_name']; ?> - ">
                                    <?php } else { ?>
                                        <input type="text" class="form-control" name="subject" placeholder="Subject" required>
                                    <?php } ?>
                                </div>
                            </div>


                            <div class="form-group">
                                <textarea  class="form-control tinymce" rows="5" name="details" placeholder="Enter problem description here..."></textarea>
                            </div>

                            <?php if (empty($_GET['client_id'])) { ?>

                                <div class="form-group">
                                    <label>Client <strong class="text-danger">*</strong></label>
                                    <div class="input-group">
                                        <div class="input-group-prepend">
                                            <span class="input-group-text"><i class="fa fa-fw fa-user"></i></span>
                                        </div>
                                        <select class="form-control select2" id="select2-client" name="client_id" required>
                                            <option value="">- Client -</option>
                                            <?php

                                            $sql = mysqli_query($mysqli, "SELECT * FROM clients WHERE client_archived_at IS NULL ORDER BY client_name ASC");
                                            while ($row = mysqli_fetch_array($sql)) {
                                                $client_id = intval($row['client_id']);
                                                $client_name = nullable_htmlentities($row['client_name']); ?>
                                                <option value="<?= $client_id; ?>"><?= $client_name; ?></option>

                                            <?php } ?>
                                        </select>
                                    </div>
                                </div>

                                <div class="form-group">
                                    <div class="custom-control custom-checkbox">
                                        <input class="custom-control-input" type="checkbox" id="primaryContactCheckbox" name="use_primary_contact" value="1">
                                        <label for="primaryContactCheckbox" class="custom-control-label">Use Primary Contact</label>
                                    </div>
                                </div>

                            <?php } else { ?>

                                <input type="hidden" name="client_id" value="<?= $client_id; ?>">

                            <?php } ?>

                            <div class="form-group">
                                <label>Priority <strong class="text-danger">*</strong></label>
                                <div class="input-group">
                                    <div class="input-group-prepend">
                                        <span class="input-group-text"><i class="fa fa-fw fa-thermometer-half"></i></span>
                                    </div>
                                    <select class="form-control select2"  name="priority" required>
                                        <option>Low</option>
                                        <option>Medium</option>
                                        <option>High</option>
                                    </select>
                                </div>
                            </div>

                            <div class="form-group">
                                <label>Assign to</label>
                                <div class="input-group">
                                    <div class="input-group-prepend">
                                        <span class="input-group-text"><i class="fa fa-fw fa-user-check"></i></span>
                                    </div>
                                    <select class="form-control select2" id='select2-assign' name="assigned_to">
                                        <option value="0">Not Assigned</option>
                                        <?php

                                        $sql = mysqli_query(
                                            $mysqli,
                                            "SELECT users.user_id, user_name FROM users
                                            LEFT JOIN user_settings on users.user_id = user_settings.user_id
                                            WHERE user_status = 1 AND user_archived_at IS NULL ORDER BY user_name ASC"
                                        );
                                        while ($row = mysqli_fetch_array($sql)) {
                                            $user_id = intval($row['user_id']);
                                            $user_name = nullable_htmlentities($row['user_name']); ?>
                                            <option <?php if ($user_id == $user_id) { echo "selected"; } ?> value="<?= $user_id; ?>"><?= $user_name; ?></option>
                                        <?php } ?>
                                    </select>
                                </div>
                            </div>

                        </div>

                        <?php if (isset($_GET['client_id'])) { ?>

                            <div class="tab-pane fade" role="tabpanel" id="pills-contacts">

                                <input type="hidden" name="client" value="<?= $client_id; ?>">

                                <div class="form-group">
                                    <label>Contact</label>
                                    <div class="input-group">
                                        <div class="input-group-prepend">
                                            <span class="input-group-text"><i class="fa fa-fw fa-user"></i></span>
                                        </div>
                                        <select class="form-control select2" id='select2-contact' name="contact">
                                            <option value="0">- No One -</option>
                                            <?php
                                            $sql = mysqli_query($mysqli, "SELECT * FROM contacts WHERE contact_client_id = $client_id AND contact_archived_at IS NULL ORDER BY contact_primary DESC, contact_technical DESC, contact_name ASC");
                                            while ($row = mysqli_fetch_array($sql)) {
                                                $contact_id = intval($row['contact_id']);
                                                $contact_name = nullable_htmlentities($row['contact_name']);
                                                $contact_primary = intval($row['contact_primary']);
                                                if($contact_primary == 1) {
                                                    $contact_primary_display = " (Primary)";
                                                } else {
                                                    $contact_primary_display = "";
                                                }
                                                $contact_technical = intval($row['contact_technical']);
                                                if($contact_technical == 1) {
                                                    $contact_technical_display = " (Technical)";
                                                } else {
                                                    $contact_technical_display = "";
                                                }
                                                $contact_title = nullable_htmlentities($row['contact_title']);
                                                if(!empty($contact_title)) {
                                                    $contact_title_display = " - $contact_title";
                                                } else {
                                                    $contact_title_display = "";
                                                }

                                                ?>
                                                <option value="<?= $contact_id; ?>" <?php if ($contact_primary == 1) { echo "selected"; } ?>><?= "$contact_name$contact_title_display$contact_primary_display$contact_technical_display"; ?></option>
                                            <?php } ?>
                                        </select>
                                    </div>
                                </div>

                                <div class="form-group">
                                    <label>Watchers</label>
                                    <div class="input-group">
                                        <div class="input-group-prepend">
                                            <span class="input-group-text"><i class="fa fa-fw fa-envelope"></i></span>
                                        </div>
                                        <select class="form-control select2"  name="watchers[]" data-tags="true" data-placeholder="Enter or select email address" multiple>
                                            <option value="">aa</option>
                                            <?php
                                            $sql = mysqli_query($mysqli, "SELECT * FROM contacts WHERE contact_client_id = $client_id AND contact_archived_at IS NULL AND contact_email IS NOT NULL ORDER BY contact_email ASC");
                                            while ($row = mysqli_fetch_array($sql)) {
                                                $contact_email = nullable_htmlentities($row['contact_email']);
                                                ?>
                                                <option><?= $contact_email; ?></option>

                                            <?php } ?>
                                        </select>
                                    </div>
                                </div>

                            </div>

                            <div class="tab-pane fade" role="tabpanel" id="pills-assets">

                                <div class="form-group">
                                    <label>Asset</label>
                                    <div class="input-group">
                                        <div class="input-group-prepend">
                                            <span class="input-group-text"><i class="fa fa-fw fa-desktop"></i></span>
                                        </div>
                                        <select class="form-control select2"  name="asset">
                                            <option value="0">- None -</option>
                                            <?php

                                            $sql_assets = mysqli_query($mysqli, "SELECT * FROM assets LEFT JOIN contacts ON contact_id = asset_contact_id WHERE asset_client_id = $client_id AND asset_archived_at IS NULL ORDER BY asset_name ASC");
                                            while ($row = mysqli_fetch_array($sql_assets)) {
                                                $asset_id_select = intval($row['asset_id']);
                                                $asset_name_select = nullable_htmlentities($row['asset_name']);
                                                $asset_contact_name_select = nullable_htmlentities($row['contact_name']);
                                            ?>
                                                <option value="<?= $asset_id_select; ?>"><?= "$asset_name_select - $asset_contact_name_select"; ?></option>

                                            <?php } ?>
                                        </select>
                                    </div>
                                </div>

                            </div>

                            <div class="tab-pane fade" role="tabpanel" id="pills-locations">

                                <div class="form-group">
                                    <label>Location</label>
                                    <div class="input-group">
                                        <div class="input-group-prepend">
                                            <span class="input-group-text"><i class="fa fa-fw fa-map-marker-alt"></i></span>
                                        </div>
                                        <select class="form-control select2"  name="location">
                                            <option value="0">- None -</option>
                                            <?php

                                            $sql_locations = mysqli_query($mysqli, "SELECT * FROM locations WHERE location_client_id = $client_id AND location_archived_at IS NULL ORDER BY location_name ASC");
                                            while ($row = mysqli_fetch_array($sql_locations)) {
                                                $location_id_select = intval($row['location_id']);
                                                $location_name_select = nullable_htmlentities($row['location_name']);
                                            ?>
                                                <option value="<?= $location_id_select; ?>"><?= $location_name_select; ?></option>

                                            <?php } ?>
                                        </select>
                                    </div>
                                </div>

                            </div>

                            <div class="tab-pane fade" role="tabpanel" id="pills-vendors">

                                <div class="form-group">
                                    <label>Vendor</label>
                                    <div class="input-group">
                                        <div class="input-group-prepend">
                                            <span class="input-group-text"><i class="fa fa-fw fa-building"></i></span>
                                        </div>
                                        <select class="form-control select2"  name="vendor">
                                            <option value="0">- None -</option>
                                            <?php

                                            $sql_vendors = mysqli_query($mysqli, "SELECT * FROM vendors WHERE vendor_client_id = $client_id AND vendor_template = 0 AND vendor_archived_at IS NULL ORDER BY vendor_name ASC");
                                            while ($row = mysqli_fetch_array($sql_vendors)) {
                                                $vendor_id_select = intval($row['vendor_id']);
                                                $vendor_name_select = nullable_htmlentities($row['vendor_name']); ?>
                                                <option value="<?= $vendor_id_select; ?>"><?= $vendor_name_select; ?></option>

                                            <?php } ?>
                                        </select>
                                    </div>
                                </div>

                                <div class="form-group">
                                    <label>Vendor Ticket Number</label>
                                    <div class="input-group">
                                        <div class="input-group-prepend">
                                            <span class="input-group-text"><i class="fa fa-fw fa-tag"></i></span>
                                        </div>
                                        <input type="text" class="form-control" name="vendor_ticket_number" placeholder="Vendor ticket number">
                                    </div>
                                </div>

                            </div>

                        <?php } ?>

                    </div>

                </div>
                <div class="modal-footer bg-white">
                    <button type="submit" name="add_ticket" class="btn btn-label-primary text-bold"><i class="fas fa-check mr-2"></i>Create</button>
                    <button type="button" class="btn btn-light" data-bs-dismiss="modal"><i class="fas fa-times mr-2"></i>Cancel</button>
                </div>
            </form>
        </div>
    </div>
</div>
