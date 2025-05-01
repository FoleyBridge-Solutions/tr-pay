<?php

/*
 * ITFlow - Main GET/POST request handler
 */

require_once "/var/www/itflow-ng/includes/tenant_db.php";

require_once "/var/www/itflow-ng/includes/config/config.php";

require_once "/var/www/itflow-ng/includes/functions/functions.php";

require_once "/var/www/itflow-ng/includes/check_login.php";

requireOnceAll("/var/www/itflow-ng/includes/post");

echo "<pre>";
print_r($_POST);
echo "</pre>";



?>
