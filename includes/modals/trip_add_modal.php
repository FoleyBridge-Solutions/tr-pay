<?php require_once "/var/www/itflow-ng/includes/inc_all_modal.php"; ?>

<div class="modal" id="addTripModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content bg-dark">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-fw fa-route mr-2"></i>New Trip</h5>
                <button type="button" class="close text-white" data-bs-dismiss="modal">
                    <span>&times;</span>
                </button>
            </div>
            <form action="/post.php" method="post" autocomplete="off">
                <div class="modal-body bg-white">
                    <input type="hidden" name="roundtrip" value="0">

                    <div class="form-row">
                        <div class="form-group col">
                            <label>Date <strong class="text-danger">*</strong></label>
                            <div class="input-group">
                                <div class="input-group-prepend">
                                    <span class="input-group-text"><i class="fa fa-fw fa-calendar"></i></span>
                                </div>
                                <input type="date" class="form-control" name="date" max="2999-12-31" value="<?= date("Y-m-d"); ?>" required>
                            </div>
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="origin">Origin <strong class="text-danger">*</strong></label>
                        <div class="input-group">
                            <div class="input-group-prepend">
                                <span class="input-group-text"><i class="fa fa-fw fa-map-marker-alt"></i></span>
                            </div>
                            <input type="text" class="form-control" id="origin" name="origin" placeholder="Enter your starting location" required>
                        </div>
                    </div>


                    <div class="form-group">
                        <label for="destination">Destination <strong class="text-danger">*</strong></label>
                        <div class="input-group">
                            <div class="input-group-prepend">
                                <span class="input-group-text"><i class="fa fa-fw fa-arrow-right"></i></span>
                            </div>
                            <?php if (isset($_GET['client_id'])) { ?>
                            <select class="form-control select2" id="destination" name="destination" data-tags="true" data-placeholder="- Select / Input Destination -" required onchange="calculateDistance()">
                                <option value=""></option>
                                <?php
                                    $client_id = intval($_GET['client_id']);
                                    $sql_locations = mysqli_query($mysqli, "SELECT * FROM locations WHERE location_archived_at IS NULL AND location_client_id = $client_id ORDER BY location_name ASC");
                                    while ($row = mysqli_fetch_array($sql_locations)) {
                                        $location_name = nullable_htmlentities($row['location_name']);
                                        $location_address = nullable_htmlentities($row['location_address']);
                                        $location_city = nullable_htmlentities($row['location_city']);
                                        $location_state = nullable_htmlentities($row['location_state']);
                                        $location_zip = nullable_htmlentities($row['location_zip']);
                                        ?>
                                        <option><?= "$location_address $location_city $location_state $location_zip"; ?></option>
                                        <?php
                                    } 
                                ?>
                            </select>
                            <?php } else { ?>
                                <input type="text" class="form-control" id="destination" name="destination" placeholder="Enter your destination" required onchange="calculateDistance()">
                            <?php } ?>
                        </div>
                    </div>

                    <div class="form-group">
                        <label>Miles <strong class="text-danger">*</strong> / <span class="text-secondary">Roundtrip</span></label>
                        <div class="input-group">
                            <div class="input-group-prepend">
                                <span class="input-group-text"><i class="bx bx-car"></i></span>
                            </div>
                            <input type="text" class="form-control" id="miles" name="miles" placeholder="0.0" required readonly>
                            <div class="input-group-append">
                                <div class="input-group-text">
                                    <input type="checkbox" name="roundtrip" value="1" onchange="updateMiles(this.checked)">
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="form-group">
                        <label>Purpose <strong class="text-danger">*</strong></label>
                        <textarea rows="4" class="form-control" placeholder="Enter a purpose" name="purpose" required></textarea>
                    </div>

                    <div class="form-group">
                        <label>Driver</label>
                        <div class="input-group">
                            <div class="input-group-prepend">
                                <span class="input-group-text"><i class="fa fa-fw fa-user"></i></span>
                            </div>
                            <select class="form-control select2" id="select2" name="user" required>
                                <option value="">- Driver -</option>
                                <?php
                                $sql = mysqli_query($mysqli, "SELECT users.user_id, user_name FROM users
                                    LEFT JOIN user_settings on users.user_id = user_settings.user_id
                                    WHERE user_archived_at IS NULL ORDER BY user_name ASC"
                                );
                                while ($row = mysqli_fetch_array($sql)) {
                                    $user_id = intval($row['user_id']);
                                    $user_name = nullable_htmlentities($row['user_name']);
                                    ?>
                                    <option <?php if ($user_id == $user_id) { echo "selected"; } ?> value="<?= $user_id; ?>"><?= $user_name; ?></option>
                                    <?php
                                }
                                ?>
                            </select>
                        </div>
                    </div>

                    <?php if (isset($_GET['client_id'])) { ?>
                        <input type="hidden" name="client" value="<?= $client_id; ?>">
                    <?php } else { ?>
                        <div class="form-group">
                            <label>Client</label>
                            <div class="input-group">
                                <div class="input-group-prepend">
                                    <span class="input-group-text"><i class="fa fa-fw fa-user"></i></span>
                                </div>
                                <select class="form-control select2" id="select2" name="client" required>
                                    <option value="0">- Client (Optional) -</option>
                                    <?php
                                    $sql = mysqli_query($mysqli, "SELECT * FROM clients WHERE client_archived_at is NULL ORDER BY client_name ASC");
                                    while ($row = mysqli_fetch_array($sql)) {
                                        $client_id = intval($row['client_id']);
                                        $client_name = nullable_htmlentities($row['client_name']);
                                        ?>
                                        <option value="<?= $client_id; ?>"><?= $client_name; ?></option>
                                        <?php
                                    }
                                    ?>
                                </select>
                            </div>
                        </div>
                    <?php } ?>
                </div>

                <div class="modal-footer bg-white">
                    <button type="submit" name="add_trip" class="btn btn-label-primary text-bold"></i>Create</button>
                    <button type="button" class="btn btn-light" data-bs-dismiss="modal"></i>Cancel</button>
                    <script src="/includes/js/trip_add_modal.js"></script> <!-- TRIP ADD MODAL JS -->
                </div>
            </form>
        </div> <!-- Modal Content -->
    </div> <!-- Modal Dialog -->
</div> <!-- Modal -->