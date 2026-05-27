<?php

class Database {
    public $conn;

    public function getConnection() {
        $host     = getenv("MYSQLHOST");
        $db_name  = getenv("MYSQLDATABASE");
        $username = getenv("MYSQLUSER");
        $password = getenv("MYSQLPASSWORD");
        $port     = getenv("MYSQLPORT") ?: '3306';

        $this->conn = null;

        try {
            $dsn = "mysql:host={$host};port={$port};dbname={$db_name};charset=utf8mb4";
            $this->conn = new PDO($dsn, $username, $password);
            $this->conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        } catch (PDOException $e) {
            die("Connection error: " . $e->getMessage());
        }

        return $this->conn;
    }
}
?>
