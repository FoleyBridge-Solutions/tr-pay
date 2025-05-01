<?php require_once "/var/www/itflow-ng/includes/inc_all_modal.php"; ?>

<div class="modal" id="assetDocumentsModal<?= $asset_id; ?>" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content bg-dark">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fa fa-fw fa-<?= $device_icon; ?> mr-2"></i><?= $asset_name; ?> Documents</h5>
                <button type="button" class="close text-white" data-bs-dismiss="modal">
                    <span>&times;</span>
                </button>
            </div>

            <div class="modal-body bg-white">
                <?php
                while ($row = mysqli_fetch_array($sql_related_documents)) {
                    $related_document_id = intval($row['document_id']);
                    $related_document_name = nullable_htmlentities($row['document_name']);
                    ?>
                    <p>
                        <i class="fas fa-fw fa-document text-secondary"></i>
                        <?= $related_document_name; ?> <a href="client_documents.php?q=<?= $related_document_name; ?>"><?= $related_document_name; ?></a>
                    </p>
                <?php } ?>
            </div>
            <div class="modal-footer bg-white">
                <button type="button" class="btn btn-light" data-bs-dismiss="modal"></i>Cancel</button>
            </div>

        </div>
    </div>
</div>
