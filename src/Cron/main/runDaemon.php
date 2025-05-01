<?php

require_once '/var/www/itflow-ng/cron/interfaces/TaskInterface.php';

function requireOnceAll($path) {
    foreach (glob($path . '/*.php') as $file) {
        // skip files in array
        $skip = [
            
        ];
        if (in_array(basename($file), $skip)){ continue; }
        require_once $file;
    }
}

$tasksDir = opendir('/var/www/itflow-ng/cron/tasks/');
if (!$tasksDir) {
    exit("Could not open tasks directory -- Quitting..");
}

//requireOnceAll for tasks
requireOnceAll('/var/www/itflow-ng/cron/tasks');

require_once 'daemon.php';
require_once '/var/www/itflow-ng/config.php';
require_once '/var/www/itflow-ng/vendor/autoload.php';

use Twetech\Nestogy\Core\Daemon;

$config = require '/var/www/itflow-ng/config.php';

// Pass the cron key from command-line arguments
if (!isset($argv[1])) {
    exit("Cron Key missing -- Quitting..");
}

$cronKey = $argv[1];

$daemon = new Daemon($config, $cronKey);
$daemon->run();

