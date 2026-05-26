<?php
// Test database connection file
// Access this file to verify your database setup: http://localhost/hg_community/test-connection.php

require_once '../config/database.php';

echo "<h2>HG Community - Database Connection Test</h2>";

try {
    $database = new Database();
    $db = $database->getConnection();
    
    if ($db) {
        echo "<p style='color: green;'>✅ Database connection successful!</p>";
        
        // Test if tables exist
        $tables = ['users', 'channels', 'messages', 'invites', 'user_permissions'];
        echo "<h3>Table Status:</h3>";
        
        foreach ($tables as $table) {
            $query = "SHOW TABLES LIKE '$table'";
            $stmt = $db->prepare($query);
            $stmt->execute();
            
            if ($stmt->rowCount() > 0) {
                echo "<p style='color: green;'>✅ Table '$table' exists</p>";
            } else {
                echo "<p style='color: orange;'>⚠️ Table '$table' not found (will be created automatically)</p>";
            }
        }
        
        // Check if admin user exists
        $adminQuery = "SELECT COUNT(*) as count FROM users WHERE role = 'admin'";
        $adminStmt = $db->prepare($adminQuery);
        $adminStmt->execute();
        $adminResult = $adminStmt->fetch(PDO::FETCH_ASSOC);
        
        if ($adminResult['count'] > 0) {
            echo "<p style='color: green;'>✅ Admin user exists</p>";
        } else {
            echo "<p style='color: red;'>❌ No admin user found</p>";
            echo "<p><strong>Create admin user manually:</strong></p>";
            echo "<code>INSERT INTO users (username, email, password, role, status) VALUES ('admin', 'admin@hgcommunity.com', '\$2y\$10\$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'admin', 'active');</code>";
            echo "<p><em>Username: admin, Password: password</em></p>";
        }
        
        echo "<hr>";
        echo "<p><a href='login.php'>Go to Login Page</a></p>";
        
    } else {
        echo "<p style='color: red;'>❌ Database connection failed!</p>";
    }
    
} catch (Exception $e) {
    echo "<p style='color: red;'>❌ Connection Error: " . $e->getMessage() . "</p>";
    echo "<h3>Troubleshooting:</h3>";
    echo "<ul>";
    echo "<li>Check if MySQL server is running</li>";
    echo "<li>Verify database credentials in config/database.php</li>";
    echo "<li>Ensure database 'hg_community' exists</li>";
    echo "<li>Check PHP PDO MySQL extension is installed</li>";
    echo "</ul>";
}
?>

<style>
body { font-family: Arial, sans-serif; margin: 40px; background: #f5f5f5; }
h2 { color: #333; }
code { background: #f0f0f0; padding: 10px; display: block; margin: 10px 0; border-radius: 4px; }
</style>