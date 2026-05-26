<?php

// Railway MySQL Configuration
define('DB_HOST', 'mysql.railway.internal');
define('DB_USER', 'root');
define('DB_PASS', 'AcKPoMUIUVMbBEgdJCqfrJwYvBWjPfUX');
define('DB_NAME', 'railway');
define('DB_PORT', '3306');

class Database {
    private $host = DB_HOST;
    private $user = DB_USER;
    private $password = DB_PASS;
    private $database = DB_NAME;
    private $port = DB_PORT;

    public $connection;

    public function getConnection() {
        $this->connection = null;

        try {
            $this->connection = new PDO(
                "mysql:host=" . $this->host .
                ";port=" . $this->port .
                ";dbname=" . $this->database,
                $this->user,
                $this->password
            );

            $this->connection->exec("set names utf8");
            $this->connection->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        } catch(PDOException $exception) {
            die("Connection error: " . $exception->getMessage());
        }

        return $this->connection;
    }
}

?>