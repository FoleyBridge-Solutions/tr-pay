<!-- Right -->

<div class="col<?= $mobile ? '' : '-3'; ?> ">
    <div class="card card-action mb-3">
        <div class="card-header">
            <div class="card-action-title row">
                <div class="col">
                    <h5 class="card-title">Ticket <?= $ticket_prefix . $ticket_number ?></h5>
                </div>
            </div>
            <div class="card-action-element">
                <ul class="list-inline mb-0">
                    <li class="list-inline-item">
                        <a href="javascript:void(0);" class="card-collapsible"><i class="tf-icons bx bx-chevron-up"></i></a>
                    </li>
                    <li class="list-inline-item">
                        <div class="dropdown dropleft text-center d-print-none">
                            <button class="btn btn-light btn-sm float-right" type="button" id="dropdownMenuButton" data-bs-toggle="dropdown">
                                <i class="fas fa-fw fa-ellipsis-v"></i>
                            </button>
                            <div class="dropdown-menu" aria-labelledby="dropdownMenuButton">
                                <a href="#"  class="dropdown-item loadModalContentBtn"data-bs-toggle="modal" data-bs-target="#dynamicModal" data-modal-file="ticket_edit_modal.php?ticket_id=<?= $ticket_id; ?>">
                                    <i class="fas fa-fw fa-edit mr-2"></i>Edit
                                </a>
                                <a href="#" class="dropdown-item loadModalContentBtn" data-bs-toggle="modal" data-bs-target="#dynamicModal" data-modal-file="ticket_merge_modal.php?ticket_id=<?= $ticket_id; ?>">
                        
                                    <i class="fas fa-fw fa-clone mr-2"></i>Merge
                                </a>
                                <a href="#" class="dropdown-item loadModalContentBtn"  data-bs-toggle="modal" data-bs-target="#dynamicModal" data-modal-file="ticket_change_client_modal.php?ticket_id=<?= $ticket_id; ?>">
                                    <i class="fas fa-fw fa-people-carry mr-2"></i>Change Client
                                </a>
                                <a href="#" class="dropdown-item loadModalContentBtn" data-bs-toggle="modal" data-bs-target="#dynamicModal" data-modal-file="ticket_edit_contact_modal.php?ticket_id=<?= $ticket_id; ?>">
                                    <i class="fas fa-fw fa-user mr-2"></i>Change Contact
                                </a>

                                <?php if ($user_role == 3) { ?>
                                <div class="dropdown-divider"></div>
                                <a class="dropdown-item text-danger text-bold confirm-link" href="/post.php?delete_ticket=<?= $ticket_id; ?>">
                                    <i class="fas fa-fw fa-trash mr-2"></i>Delete
                                </a>
                                <?php } ?>
                            </div>
                        </div>
                    </li>
                </ul>
            </div>
        </div>
        <div class="collapse <?= !$mobile ? 'show' : ''; ?>">
            <div class="card-body">
                <div class="row">
                    <h5><strong><?= $client_name; ?></strong></h5>
                    <?php
                            if (!empty($location_phone)) { ?>
                    <div class="mt-1">
                        <i class="fa fa-fw fa-phone text-secondary ml-1 mr-2 mb-2"></i><?= $location_phone; ?>
                    </div>
                    <?php } ?>

                <?php if (!empty($contact_id)) { ?>

                    <!-- Contact table to replace card -->
                    <table class="table table-sm table-borderless table-striped table-hover table-responsive-md">
                        <thead>
                            <tr>
                                <th>Contact</th>
                                <th><a class="loadModalContentBtn" href="#" data-bs-toggle="modal" data-bs-target="#dynamicModal" data-modal-file="ticket_edit_contact_modal.php?ticket_id=<?= $ticket_id; ?>">Edit</a></th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td>Name:</td>
                                <td><?= $contact_name; ?>
                            </td>
                            </tr>
                            <?php if (!empty($location_name)) { ?>
                            <tr>
                                <td>Location:</td>
                                <td><?= $location_name; ?></td>
                            </tr>
                            <?php }
                            if (!empty($contact_email)) { ?>
                            <tr>
                                <td>Email:</td>
                                <td><a href="mailto:<?= $contact_email; ?>"><?= $contact_email; ?></a></td>
                            </tr>
                            <?php }
                            if (!empty($contact_phone)) { ?>
                            <tr>
                                <td>Phone:</td>
                                <td><a href="tel:<?= $contact_phone; ?>"><?= $contact_phone; ?></a></td>
                            </tr>
                            <?php }
                            if (!empty($contact_mobile)) { ?>
                            <tr>
                                <td>Mobile:</td>
                                <td><a href="tel:<?= $contact_mobile; ?>"><?= $contact_mobile; ?></a></td>
                            </tr>
                            <?php } ?>
                            <?php
                            // Previous tickets
                            $prev_ticket_id = false; //TODO: Add previous ticket ID to ticket table
                            ?>
                            <?php if ($prev_ticket_id) { ?>
                            <tr>
                                <td>Previous Ticket:</td>
                                <td>
                                        <div class="row">
                                            <div class="col-6 col-md-12">
                                                <a href="/public/?page=ticket&ticket_id=<?= $prev_ticket_id; ?>" title="View Ticket #<?= $prev_ticket_id; ?>">
                                                    <?= $prev_ticket_subject; ?>
                                                </a>
                                            </div>
                                            <div class="col-6 col-md-12">
                                                <strong>Status:</strong> <?= $prev_ticket_status; ?>
                                                <br>
                                                <strong>Assigned to:</strong> <?= $prev_ticket_assigned_to; ?>
                                            </div>
                                        </div>
                                </td>
                            </tr>
                        <?php } ?>
                        </tbody>
                    </table>
                    <!-- End Contact table -->

                <?php } ?>

                </div>
                <!-- End Client row -->
                    <div class="row small">
                        <table class="me-2 table table-sm table-borderless table-striped table-hover table-responsive-md">
                            <thead>
                                <tr>
                                    <th>Details</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr>
                                    <td>Priority:</td>
                                    <td><span class="badge rounded-pill bg-label-secondary"><?= $ticket_priority; ?></span></td>
                                </tr>
                                <tr>
                                    <td>Status:</td>
                                    <td><span class="badge rounded-pill bg-label-secondary"><?= $ticket_status; ?></span></td>
                                </tr>
                                <tr>
                                    <td>Billable:</td>
                                    <td>
                                        <a href="/post.php?ticket_<?php if($ticket_billable == 1){?>un<?php }?>billable=<?= $ticket_id; ?>">
                                            <?php if ($ticket_billable == 1) { ?>
                                                <span class="badge rounded-pill bg-label-success p-2">$</span>
                                            <?php } else { ?>
                                                <span class="badge rounded-pill bg-label-secondary p-2">X</span>
                                            <?php } ?>
                                        </a>
                                    </td>
                                </tr>
                                <tr>
                                    <td>Time Tracked:</td>
                                    <td><span class="badge rounded-pill bg-label-secondary"><?= $ticket_total_reply_time; ?></span></td>
                                    
                                </tr>
                                <tr>
                                    <td>Tasks Completed:</td>
                                    <td>
                                        <div class="progress">
                                            <?php if ($tasks_completed_percent < 15) {
                                                $tasks_completed_percent_display = 15;
                                            } else {
                                                $tasks_completed_percent_display = $tasks_completed_percent;
                                            } 
                                            if ($task_count == 0) {
                                                $tasks_completed_percent_display = 100;
                                                $tasks_completed_percent = 100;
                                            } ?>
                                            <div class="progress-bar progress-bar-striped bg-primary" role="progressbar" style="width: <?= $tasks_completed_percent_display; ?>%;" aria-valuenow="<?= $tasks_completed_percent_display; ?>" aria-valuemin="0" aria-valuemax="100"><?= $tasks_completed_percent; ?>%</div>
                                        </div>
                                        <div>
                                            <small>
                                                <?= $completed_task_count; ?> of
                                                <?= $task_count; ?> tasks completed
                                            </small>
                                        </div>
                                    </td>
                                </tr>
                                <tr>
                                    <td>Trips:</td>
                                    <td>
                                        
                                        <a class="loadModalContentBtn" href="#" data-bs-toggle="modal" data-bs-target="#dynamicModal" data-modal-file="trip_add_modal.php?client_id=<?= $client_id; ?>">
                                            <span class="badge rounded-pill bg-label-secondary">Add a Trip</span>
                                        </a>
                                    </td>
                                </tr>
                                <tr>
                                    <td>
                                        Collaborators:
                                    </td>
                                    <td>
                                        <?php
                                        if (empty($ticket_collaborators)) {
                                            ?>
                                            <a class="loadModalContentBtn" href="#" data-bs-toggle="modal" data-bs-target="#dynamicModal" data-modal-file="ticket_add_collaborator_modal.php?ticket_id=<?= $ticket_id; ?>">
                                                <span class="badge rounded-pill bg-label-secondary">Add a Collaborator</span>
                                            </a>
                                            <?php
                                        } else {
                                            ?>
                                            <span class="badge rounded-pill bg-label-secondary">
                                            <?php
                                            foreach ($ticket_collaborators as $collaborator) {
                                                echo $collaborator;
                                            }
                                            ?>
                                            </span>
                                            <?php
                                        }
                                        ?>

                                    
                                    </td>
                                <tr>
                                    <td>Created:</td>
                                    <td><span class="badge rounded-pill bg-label-secondary"><?= $ticket_created_at; ?></span></td>
                                </tr>
                                <tr>
                                    <td>Updated:</td>
                                    <td><span class="badge rounded-pill bg-label-secondary"><?= $ticket_updated_at; ?></span></td>
                                </tr>
                                <tr>
                                    <td>Scheduled:</td>
                                    <td>
                                        <a class="loadModalContentBtn" href="#" data-bs-toggle="modal" data-bs-target="#dynamicModal" data-modal-file="ticket_edit_schedule_modal.php?ticket_id=<?= $ticket_id; ?>">
                                            <span class="badge rounded-pill bg-label-secondary"><?= $ticket_schedule ? $ticket_schedule : 'Add Schedule'; ?></span>
                                        </a>
                                    </td>
                                </tr>
                                <tr>
                                    <td>Assigned to:</td>
                                    <td><a class="loadModalContentBtn" href="#" data-bs-toggle="modal" data-bs-target="#dynamicModal" data-modal-file="ticket_assign_modal.php?ticket_id=<?= $ticket_id; ?>">
                                        <span class="badge rounded-pill bg-label-secondary"><?= $ticket_assigned_to; ?></span></a>
                                    </td>
                                </tr>
                                <?php if (empty($contact_id)) { ?> 
                                    <tr>
                                        <td>Contact:</td>
                                        <td>
                                            <a class="loadModalContentBtn" href="#" data-bs-toggle="modal" data-bs-target="#dynamicModal" data-modal-file="ticket_edit_contact_modal.php?ticket_id=<?= $ticket_id; ?>">
                                                <span class="badge rounded-pill bg-label-secondary">Add Contact</span>
                                            </a>
                                        </td>
                                    </tr>
                                <?php } ?>
                                <tr>
                                    <td>
                                        Watchers:
                                    </td>
                                    <td>
                                    <?php if (empty($ticket_watcher_row))
                                    {
                                        ?>
                                        <a class="loadModalContentBtn" href="#" data-bs-toggle="modal" data-bs-target="#dynamicModal" data-modal-file="ticket_add_watcher_modal.php?ticket_id=<?= $ticket_id; ?>">
                                            <span class="badge rounded-pill bg-label-secondary">Add a Watcher</span>
                                        </a>
                                        <?php
                                    }
                                    else
                                    {
                                        echo $ticket_watcher_row;
                                    }
                                    ?>
                                    </td>
                                </tr>
                                <tr>
                                    <td>Asset:</td>
                                    <td>
                                    <?php if (empty($asset_id))
                                    {
                                        ?>
                                        <a class="loadModalContentBtn" href="#" data-bs-toggle="modal" data-bs-target="#dynamicModal" data-modal-file="ticket_edit_asset_modal.php?ticket_id=<?= $ticket_id; ?>">
                                            <span class="badge rounded-pill bg-label-secondary">Add an Asset</span>
                                        </a>
                                        <?php
                                    }
                                    else
                                    {
                                        echo $asset_name;
                                    }
                                    ?>
                                    </td>
                                </tr>
                                <tr>
                                    <td>Vendor:</td>
                                    <td>
                                    <?php if (empty($vendor_id))
                                    {
                                        ?>
                                        <a class="loadModalContentBtn" href="#" data-bs-toggle="modal" data-bs-target="#dynamicModal" data-modal-file="ticket_edit_vendor_modal.php?ticket_id=<?= $ticket_id; ?>">
                                            <span class="badge rounded-pill bg-label-secondary">Add a Vendor</span>
                                        </a>
                                        <?php
                                    }
                                    else
                                    {
                                        echo $vendor_name;
                                    }
                                    ?>
                                    </td>
                                </tr>
                                <tr>
                                    <td>Products:</td>
                                    <td>
                                    <?php if (empty($ticket_products_display))
                                    {
                                        ?>
                                        <a class="loadModalContentBtn" href="#" data-bs-toggle="modal" data-bs-target="#dynamicModal" data-modal-file="ticket_add_product_modal.php?ticket_id=<?= $ticket_id; ?>">
                                            <span class="badge rounded-pill bg-label-secondary">Manage Products</span>
                                        </a>
                                        <?php
                                    }
                                    else
                                    {
                                        echo $ticket_products_display;
                                    }
                                    ?>
                                    </td>
                                </tr>
                                <!-- Ticket closure info -->
                                <?php if ($ticket_status == "Closed") {
                                    ?>
                                    <tr>
                                        <td>Feedback:</td>
                                        <td><?= $ticket_feedback; ?></td>
                                    </tr>
                                <?php } ?>
                                <!-- END Ticket closure info -->
                            </tbody>
                        </table>

                                            <!-- Ticket Actions -->
                    <?php
                    if ($ticket_status_id != 5) {
                        $close_ticket_button = true;
                    }
                    if ($ticket_billable) {
                        $invoice_ticket_button = true;
                    }

                    if ($close_ticket_button || $invoice_ticket_button) {
                ?>
                    <div class="mt-3">
                        <div class="row">
                            <?php if (isset($invoice_ticket_button)) { ?>
                                <div class="col">
                                    <a href="#" class="btn btn-primary btn-block mb-3 loadModalContentBtn" data-bs-toggle="modal" data-bs-target="#dynamicModal" data-modal-file="ticket_invoice_add_modal.php?ticket_id=<?= $ticket_id; ?>&ticket_total_reply_time=<?= $ticket_total_reply_time; ?>">
                                        <i class="fas fa-fw fa-file-invoice mr-2"></i>Invoice Ticket
                                    </a>
                                </div>
                            <?php } ?>
                            <?php if (isset($close_ticket_button)) { ?>
                                <div class="col">
                                    <a href="/post.php?close_ticket=<?= $ticket_id; ?>" class="btn btn-secondary btn-block confirm-link" id="ticket_close">
                                        <i class="fas fa-fw fa-gavel mr-2"></i>Close Ticket
                                    </a>
                                </div>
                            <?php } ?>
                        </div>
                    </div>
                    <?php } ?>

                <!-- End Ticket Actions -->
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
