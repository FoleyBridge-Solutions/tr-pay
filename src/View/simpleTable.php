<?php
// src/View/simpleTable.php

$card_title = $card['title'];

$table_header_rows = $table['header_rows'];
$table_body_rows = $table['body_rows'];

// if action is not an array of arrays, make it an array of arrays
if (isset($action)) {
    if (! is_array($action[0])) {
        $action = [$action];
    }
}
if (isset($table['footer_row'])) {
    if (! is_array($table['footer_row'])) {
        $table['footer_row'] = [$table['footer_row']];
    }
}

?>
<?php if (isset($header_cards)) { ?>
    <div class="row">
        <?php foreach ($header_cards as $header_card) { ?>
            <?php $card_count = count($header_cards); ?>
            <div class="col-md-<?= 12 / $card_count ?>">
                <div class="card mb-3">
                    <div class="card-header">
                        <h5 class="card-title"><?= $header_card['title'] ?></h5>
                    </div>
                    <div class="card-body">
                        <?= $header_card['body'] ?>
                    </div>
                </div>
            </div>
        <?php } ?>
    </div>
<?php } ?>


<div class="card">
    <div class="card-header">
        <h5 class="card-title"><?= $card_title ?></h5>
        <div class="ms-auto">
            <!-- Card Action -->
            <?php foreach ($action as $action_item) { ?>
                <?php if (isset($action_item['page'])) { ?>
                    <a href="/?page=<?= $action_item['page'] ?>" class="btn btn-primary">
                        <?= $action_item['title'] ?>
                    </a>
                <?php } ?>
            <?php } ?>
        </div>
    </div>
    <div class="card-body">
        <div class="table-responsive" id="<?= $table['id'] ?? 'simpleTable' ?>">
            <table class="table table-striped table-bordered" id="dataTable">
                <thead>
                    <tr>
                        <?php foreach ($table_header_rows as $header_row) { ?>
                            <th><?= $header_row ?></th>
                        <?php } ?>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($table_body_rows as $body_row) { ?>
                        <tr>
                            <?php foreach ($body_row as $cell) { ?>
                                <td><?= $cell ?></td>
                            <?php } ?>
                        </tr>
                    <?php } ?>
                </tbody>
            </table>
        </div>
        <?php if (isset($table['footer_row'])) { ?>
            <?php foreach ($table['footer_row'] as $footer_row) { ?>
                <div class="card-footer">
                    <?= $footer_row ?>
                </div>
            <?php } ?>
        <?php } ?>
    </div>
</div>

<?php if (isset($script)) { ?>
    <script>
        <?= $script ?>
    </script>
<?php } ?>

<script>
    $(document).ready(function() {
        $('#dataTable').DataTable();
    });
</script>