<?php

define('DB_HOST', 'mysql.railway.internal');
define('DB_USER', 'root');
define('DB_PASS', 'AcKPoMUIUVMbBEgdJCqfrJwYvBWjPfUX');
define('DB_NAME', 'railway');
define('DB_PORT', '3306');

class Database {
    public $connection;

    public function getConnection() {

        $this->connection = new mysqli(
            DB_HOST,
            DB_USER,
            DB_PASS,
            DB_NAME,
            DB_PORT
        );

        if ($this->connection->connect_error) {
            die("Connection failed: " . $this->connection->connect_error);
        }

        return $this->connection;
    }
}

?>