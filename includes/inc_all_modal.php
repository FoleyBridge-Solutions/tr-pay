<?php

use Twetech\Nestogy\Database; 

require_once "/var/www/itflow-ng/includes/config/config.php";

require_once "/var/www/itflow-ng/includes/functions/functions.php";

require_once "/var/www/itflow-ng/includes/check_login.php";

$domain = $_SERVER['HTTP_HOST'];
$config = require "/var/www/itflow-ng/config/$domain/config.php";


$database = new Database($config['db']);
$pdo = $database->getConnection();


?>
