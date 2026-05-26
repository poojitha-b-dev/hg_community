<?php
// Database configuration
define('DB_HOST', 'localhost');     // Your MySQL server (usually localhost)
define('DB_USER', 'root');          // Your MySQL username (change if different)  
define('DB_PASS', '');              // Your MySQL password (add your password here)
define('DB_NAME', 'hg_community');  // Database name

class Database {
    private $host = DB_HOST;
    private $user = DB_USER;
    private $password = DB_PASS;
    private $database = DB_NAME;
    public $connection;

    public function getConnection() {
        $this->connection = null;
        
        try {
            $this->connection = new PDO("mysql:host=" . $this->host . ";dbname=" . $this->database, $this->user, $this->password);
            $this->connection->exec("set names utf8");
            $this->connection->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        } catch(PDOException $exception) {
            throw new Exception("Connection error: " . $exception->getMessage());
        }
        
        return $this->connection;
    }
}
?>