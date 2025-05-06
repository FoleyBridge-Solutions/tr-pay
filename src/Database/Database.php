<?php

namespace Fbs\trpay\Database;

class Database {
    private $connection;

    public function __construct(array $config) {
        $serverName = $config['serverName'];
        $connectionOptions = [
            "Database" => $config['databaseName'],
            "Uid" => $config['username'],
            "PWD" => $config['password'],
            "CharacterSet" => "UTF-8",
            "ReturnDatesAsStrings" => true,
            "LoginTimeout" => 30,
            "ConnectionPooling" => 0,
            "TrustServerCertificate" => true,
        ];

        $this->connection = sqlsrv_connect($serverName, $connectionOptions);

        if ($this->connection === false) {
            throw new \Exception("MSSQL Connection Failed: " . print_r(sqlsrv_errors(), true));
        }
    }

    public function getConnection() {
        return $this->connection;
    }
}