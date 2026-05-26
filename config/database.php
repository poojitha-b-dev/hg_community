<?php

$host = getenv("MYSQLHOST");
$user = getenv("MYSQLUSER");
$pass = getenv("MYSQLPASSWORD");
$db   = getenv("MYSQLDATABASE");
$port = getenv("MYSQLPORT");

class Database {
    public $connection;

    public function getConnection() {

        global $host, $user, $pass, $db, $port;

        $this->connection = new mysqli(
            $host,
            $user,
            $pass,
            $db,
            $port
        );

        if ($this->connection->connect_error) {
            die("Connection failed: " . $this->connection->connect_error);
        }

        return $this->connection;
    }
}

?>