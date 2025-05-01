<?php
/*
 * Client Portal
 * Docs for PTC / technical contacts
 */



require_once "/var/www/itflow-ng/includes/inc_portal.php";

if ($contact_primary == 0 && !$contact_is_technical_contact) {
    header("Location: portal_post.php?logout");
    exit();
}

$documents_sql = mysqli_query($mysqli, "SELECT document_id, document_name, document_created_at, folder_name FROM documents LEFT JOIN folders ON document_folder_id = folder_id WHERE document_client_id = $client_id AND document_template = 0 ORDER BY folder_id, document_name DESC");
?>

<div class="row">

    <div class="col-md-10">

        <table id=responsive class="responsive table tabled-bordered border border-dark">
            <thead class="thead-dark">
            <tr>
                <th>Name</th>
                <th>Created</th>
            </tr>
            </thead>
            <tbody>

            <?php
            while ($row = mysqli_fetch_array($documents_sql)) {
                $document_id = intval($row['document_id']);
                $folder_name = nullable_htmlentities($row['folder_name']);
                $document_name = nullable_htmlentities($row['document_name']);
                $document_created_at = nullable_htmlentities($row['document_created_at']);

                ?>

                <tr>
                    <td><a href="document.php?id=<?= $document_id?>">
                            <?php
                            if (!empty($folder_name)) {
                                echo "$folder_name / ";
                            }
                            echo $document_name;
                            ?>
                        </a>
                    </td>
                    <td><?= $document_created_at; ?></td>
                </tr>
            <?php } ?>

            </tbody>
        </table>

    </div>

</div>

<?php
require_once "portal_footer.php";
