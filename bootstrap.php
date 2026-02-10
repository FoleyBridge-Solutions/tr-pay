<?php

// Load Composer's autoloader
require_once 'vendor/autoload.php';

if (! isset($_SESSION)) {
    // Tell client to only send cookie(s) over HTTPS
    ini_set('session.gc_maxlifetime', 2592000); // 30 days
    ini_set('session.cookie_lifetime', 2592000); // 30 days

    session_start();
}

// Database Configuration
$dbConfig = require __DIR__.'/config/database.php';

// Establish Database Connection
try {
    $serverName = $dbConfig['host'] ?? null;
    $database = $dbConfig['database'] ?? null;
    $username = $dbConfig['username'] ?? null;
    $password = $dbConfig['password'] ?? null;

    if (! $serverName || ! $database || ! $username || ! $password) {
        throw new Exception('Database configuration is incomplete. Check config/database.php.');
    }

    $connectionInfo = [
        'Database' => $database,
        'UID' => $username,
        'PWD' => $password,
        'CharacterSet' => 'UTF-8',
    ];

    // Add TrustServerCertificate to bypass SSL verification (for development only!)
    $connectionInfo['TrustServerCertificate'] = true;

    $conn = sqlsrv_connect($serverName, $connectionInfo);

    if (! $conn) {
        error_log('Failed to connect to SQL Server: '.print_r(sqlsrv_errors(), true));
        throw new Exception('Database connection failed.');
    }
} catch (Exception $e) {
    // Handle the database connection error gracefully
    error_log('Database connection error: '.$e->getMessage());
    exit('Failed to connect to the database. Please check the logs for details.');
}

function nullable_htmlentities($value)
{
    return htmlentities($value ?? '', ENT_QUOTES, 'UTF-8');
}

function referWithAlert(
    $alert,
    $type = 'warning',
    $url = null
) {
    if ($url == null) {
        $url = $_SERVER['HTTP_REFERER'];
    }

    $_SESSION['alert_message'] = $alert;
    $_SESSION['alert_type'] = $type;
    header('Location: '.$url);
    exit();
}

// Application is now bootstrapped and ready to run
