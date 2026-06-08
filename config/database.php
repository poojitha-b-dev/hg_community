<?php

class Database {
    public $conn;

    public function getConnection() {
        $host     = getenv("MYSQLHOST")     ?: 'localhost';
        $db_name  = getenv("MYSQLDATABASE") ?: 'hg_community';
        $username = getenv("MYSQLUSER")     ?: 'root';
        $password = getenv("MYSQLPASSWORD") ?: '';
        $port     = getenv("MYSQLPORT")     ?: '3306';

        $this->conn = null;

        try {
            $dsn = "mysql:host={$host};port={$port};dbname={$db_name};charset=utf8mb4";
            $this->conn = new PDO($dsn, $username, $password, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_TIMEOUT            => 10,
                PDO::ATTR_EMULATE_PREPARES   => false, // true prepared statements — blocks SQLi
            ]);
        } catch (PDOException $e) {
            http_response_code(500);
            header('Content-Type: application/json');
            die(json_encode(['success' => false, 'message' => 'Database connection failed.']));
        }

        return $this->conn;
    }
}
