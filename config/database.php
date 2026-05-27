<?php

class Database {
    private $host;
    private $db_name;
    private $username;
    private $password;
    private $port;
    public $conn;

    public function __construct() {
        $this->host     = getenv("MYSQLHOST")     ?: 'mysql.railway.internal';
        $this->db_name  = getenv("MYSQLDATABASE") ?: 'railway';
        $this->username = getenv("MYSQLUSER")     ?: 'root';
        $this->password = getenv("MYSQLPASSWORD") ?: '';
        $this->port     = getenv("MYSQLPORT")     ?: '3306';
    }

    public function getConnection() {
        $this->conn = null;

        try {
            $dsn = "mysql:host={$this->host};port={$this->port};dbname={$this->db_name};charset=utf8mb4";

            $this->conn = new PDO(
                $dsn,
                $this->username,
                $this->password,
                [
                    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_TIMEOUT            => 10,
                ]
            );

        } catch (PDOException $e) {
            // Try public URL as fallback
            try {
                $url = getenv("MYSQL_URL") ?: getenv("MYSQL_PUBLIC_URL");
                if ($url) {
                    $this->conn = new PDO(
                        $url,
                        null,
                        null,
                        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
                    );
                } else {
                    die("Connection error: " . $e->getMessage());
                }
            } catch (PDOException $e2) {
                die("Connection error: " . $e2->getMessage());
            }
        }

        return $this->conn;
    }
}
?>
