<?php require_once "/var/www/itflow-ng/includes/inc_all_modal.php"; ?>
<div class="modal" id="viewFileModal<?= $file_id; ?>" tabindex="-1">
    <div class="modal-dialog modal-xl ">
        <div class="modal-content bg-dark">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fa fa-fw fa-image mr-2"></i><?= $file_name; ?></h5>
                <button type="button" class="close text-white" data-bs-dismiss="modal">
                    <span>&times;</span>
                </button>
            </div>

            <div style="text-align: center;">
                <img class="img-fluid" src="<?= "/uploads/clients/$client_id/$file_reference_name"; ?>">
            </div>

        </div>
    </div>
</div>
