<?php
// One-time script to create admin user
// Access this file once to create your first admin: http://localhost/hg_community/create-admin.php
// DELETE this file after creating admin for security!

require_once '../config/database.php';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $username = $_POST['username'];
    $email = $_POST['email'];
    $password = $_POST['password'];
    
    try {
        $database = new Database();
        $db = $database->getConnection();
        
        // Check if admin already exists
        $checkQuery = "SELECT COUNT(*) as count FROM users WHERE role = 'admin'";
        $checkStmt = $db->prepare($checkQuery);
        $checkStmt->execute();
        $result = $checkStmt->fetch(PDO::FETCH_ASSOC);
        
        if ($result['count'] > 0) {
            $message = "Admin user already exists!";
            $messageType = "error";
        } else {
            // Create admin user
            $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
            $insertQuery = "INSERT INTO users (username, email, password, role, status) VALUES (:username, :email, :password, 'admin', 'active')";
            $insertStmt = $db->prepare($insertQuery);
            $insertStmt->bindParam(':username', $username);
            $insertStmt->bindParam(':email', $email);
            $insertStmt->bindParam(':password', $hashedPassword);
            
            if ($insertStmt->execute()) {
                $message = "Admin user created successfully! You can now login.";
                $messageType = "success";
            } else {
                $message = "Failed to create admin user.";
                $messageType = "error";
            }
        }
    } catch (Exception $e) {
        $message = "Error: " . $e->getMessage();
        $messageType = "error";
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Create Admin - HG Community</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 0; padding: 40px; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); min-height: 100vh; }
        .container { max-width: 400px; margin: 0 auto; background: white; padding: 40px; border-radius: 10px; box-shadow: 0 10px 30px rgba(0,0,0,0.2); }
        h2 { text-align: center; color: #333; margin-bottom: 30px; }
        .form-group { margin-bottom: 20px; }
        label { display: block; margin-bottom: 5px; color: #555; font-weight: bold; }
        input { width: 100%; padding: 12px; border: 1px solid #ddd; border-radius: 5px; font-size: 16px; }
        button { width: 100%; padding: 12px; background: #667eea; color: white; border: none; border-radius: 5px; font-size: 16px; cursor: pointer; }
        button:hover { background: #5a67d8; }
        .message { padding: 15px; margin-bottom: 20px; border-radius: 5px; text-align: center; }
        .success { background: #d4edda; color: #155724; border: 1px solid #c3e6cb; }
        .error { background: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }
        .warning { background: #fff3cd; color: #856404; border: 1px solid #ffeaa7; padding: 15px; border-radius: 5px; margin-bottom: 20px; }
        .links { text-align: center; margin-top: 20px; }
        .links a { color: #667eea; text-decoration: none; margin: 0 10px; }
    </style>
</head>
<body>
    <div class="container">
        <h2>Create Admin User</h2>
        
        <div class="warning">
            <strong>Security Notice:</strong> Delete this file (create-admin.php) after creating your admin user!
        </div>
        
        <?php if (isset($message)): ?>
            <div class="message <?php echo $messageType; ?>">
                <?php echo htmlspecialchars($message); ?>
            </div>
        <?php endif; ?>
        
        <form method="POST">
            <div class="form-group">
                <label for="username">Admin Username</label>
                <input type="text" id="username" name="username" required value="admin">
            </div>
            
            <div class="form-group">
                <label for="email">Admin Email</label>
                <input type="email" id="email" name="email" required value="admin@hgcommunity.com">
            </div>
            
            <div class="form-group">
                <label for="password">Admin Password</label>
                <input type="password" id="password" name="password" required placeholder="Enter secure password">
            </div>
            
            <button type="submit">Create Admin User</button>
        </form>
        
        <div class="links">
            <a href="test-connection.php">Test Database</a> |
            <a href="login.php">Login Page</a> |
            <a href="index.html">Setup Guide</a>
        </div>
    </div>
</body>
</html>