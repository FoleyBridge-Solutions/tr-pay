<?php
/*
 * Client Portal
 * User profile
 */



require_once '/var/www/itflow-ng/includes/inc_portal.php';


?>

    <h2>Profile</h2>

    <p>Name: <?= stripslashes(nullable_htmlentities($contact_name)); ?></p>
    <p>Email: <?= $contact_email ?></p>
    <p>PIN: <?= $contact_pin ?></p>
    <p>Client: <?= $client_name ?></p>
    <br>
    <p>Client Primary Contact: <?php if ($contact_primary == 1) {echo "Yes"; } else {echo "No";} ?></p>
    <p>Client Technical Contact: <?php if ($contact_is_technical_contact) {echo "Yes"; } else {echo "No";} ?></p>
    <p>Client Billing Contact: <?php if ($contact_is_billing_contact == $contact_id) {echo "Yes"; } else {echo "No";} ?></p>


    <p>Login via: <?= $_SESSION['login_method'] ?> </p>


    <!--  // Show option to change password if auth provider is local -->
<?php if ($_SESSION['login_method'] == 'local'): ?>
    <hr>
    <div class="col-md-6">
        <h4>Password</h4>
        <form action="portal_post.php" method="post" autocomplete="off">
            <div class="form-group">
                <label>New Password</label>
                <div class="input-group">
                    <div class="input-group-prepend">
                        <span class="input-group-text"><i class="fa fa-fw fa-lock"></i></span>
                    </div>
                    <input type="password" class="form-control" minlength="8" required data-bs-toggle="password" name="new_password" placeholder="Leave blank for no change" autocomplete="new-password">
                </div>
            </div>
            <button type="submit" name="edit_profile" class="btn btn-label-primary text-bold mt-3"><i class="fas fa-check mr-2"></i>Save password</button>
        </form>
    </div>
<?php endif ?>

<?php
require_once 'portal_footer.php';

