<?php
// Protected with secret key
define('DEV_SECRET', 'HGAdmin@2026');

$authenticated = false;
$authError = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['dev_secret'])) {
    if ($_POST['dev_secret'] === DEV_SECRET) {
        $authenticated = true;
        session_start();
        $_SESSION['dev_auth'] = true;
    } else {
        $authError = 'Incorrect secret key.';
    }
} else {
    session_start();
    if (!empty($_SESSION['dev_auth'])) {
        $authenticated = true;
    }
}

if (!$authenticated) {
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <title>Dev Access - HG Community</title>
        <style>
            body { font-family: Arial, sans-serif; margin: 0; padding: 40px; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); min-height: 100vh; }
            .container { max-width: 360px; margin: 80px auto; background: white; padding: 40px; border-radius: 10px; box-shadow: 0 10px 30px rgba(0,0,0,0.2); }
            h2 { text-align: center; color: #333; }
            input { width: 100%; padding: 12px; border: 1px solid #ddd; border-radius: 5px; font-size: 16px; box-sizing: border-box; margin-bottom: 15px; }
            button { width: 100%; padding: 12px; background: #667eea; color: white; border: none; border-radius: 5px; font-size: 16px; cursor: pointer; }
            .error { color: #721c24; background: #f8d7da; padding: 10px; border-radius: 5px; margin-bottom: 15px; text-align: center; }
        </style>
    </head>
    <body>
        <div class="container">
            <h2>🔒 Dev Access</h2>
            <?php if ($authError): ?><div class="error"><?= htmlspecialchars($authError) ?></div><?php endif; ?>
            <form method="POST">
                <input type="password" name="dev_secret" placeholder="Enter secret key" required autofocus>
                <button type="submit">Unlock</button>
            </form>
        </div>
    </body>
    </html>
    <?php
    exit;
}

// ── Original create-admin.php logic below ──────────────────────────────────
require_once '../config/database.php';

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['username'])) {
    $username = $_POST['username'];
    $email = $_POST['email'];
    $password = $_POST['password'];
    
    try {
        $database = new Database();
        $db = $database->getConnection();
        
        $checkQuery = "SELECT COUNT(*) as count FROM users WHERE role = 'admin'";
        $checkStmt = $db->prepare($checkQuery);
        $checkStmt->execute();
        $result = $checkStmt->fetch(PDO::FETCH_ASSOC);
        
        if ($result['count'] > 0) {
            $message = "Admin user already exists!";
            $messageType = "error";
        } else {
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
        input { width: 100%; padding: 12px; border: 1px solid #ddd; border-radius: 5px; font-size: 16px; box-sizing: border-box; }
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
        <div class="warning"><strong>🔒 Authenticated.</strong> You have dev access.</div>
        <?php if (isset($message)): ?>
            <div class="message <?php echo $messageType; ?>"><?php echo htmlspecialchars($message); ?></div>
        <?php endif; ?>
        <form method="POST">
            <div class="form-group">
                <label>Admin Username</label>
                <input type="text" name="username" required value="admin">
            </div>
            <div class="form-group">
                <label>Admin Email</label>
                <input type="email" name="email" required value="admin@hgcommunity.com">
            </div>
            <div class="form-group">
                <label>Admin Password</label>
                <input type="password" name="password" required placeholder="Enter secure password">
            </div>
            <button type="submit">Create Admin User</button>
        </form>
        <div class="links">
            <a href="test-connection.php">Test Database</a> |
            <a href="../login.php">Login Page</a>
        </div>
    </div>
</body>
</html>
