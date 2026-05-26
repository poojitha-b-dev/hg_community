<?php

class Database {
    private $host;
    private $db_name;
    private $username;
    private $password;
    private $port;
    public $conn;

    public function __construct() {
        $this->host = getenv("MYSQLHOST");
        $this->db_name = getenv("MYSQLDATABASE");
        $this->username = getenv("MYSQLUSER");
        $this->password = getenv("MYSQLPASSWORD");
        $this->port = getenv("MYSQLPORT");
    }

    public function getConnection() {
        $this->conn = null;

        try {

            $dsn = "mysql:host={$this->host};port={$this->port};dbname={$this->db_name}";

            $this->conn = new PDO(
                $dsn,
                $this->username,
                $this->password
            );

            $this->conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        } catch(PDOException $exception) {

            die("Connection error: " . $exception->getMessage());

        }

        return $this->conn;
    }
}
?>