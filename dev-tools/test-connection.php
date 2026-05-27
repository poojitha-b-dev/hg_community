<?php
define('DEV_SECRET', 'HGAdmin@2026');

session_start();
$authenticated = false;
$authError = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['dev_secret'])) {
    if ($_POST['dev_secret'] === DEV_SECRET) {
        $_SESSION['dev_auth'] = true;
        $authenticated = true;
    } else {
        $authError = 'Incorrect secret key.';
    }
} elseif (!empty($_SESSION['dev_auth'])) {
    $authenticated = true;
}

if (!$authenticated) {
    ?>
    <!DOCTYPE html><html><head><meta charset="UTF-8"><title>Dev Access</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 0; padding: 40px; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); min-height: 100vh; }
        .container { max-width: 360px; margin: 80px auto; background: white; padding: 40px; border-radius: 10px; box-shadow: 0 10px 30px rgba(0,0,0,0.2); }
        h2 { text-align: center; color: #333; }
        input { width: 100%; padding: 12px; border: 1px solid #ddd; border-radius: 5px; font-size: 16px; box-sizing: border-box; margin-bottom: 15px; }
        button { width: 100%; padding: 12px; background: #667eea; color: white; border: none; border-radius: 5px; font-size: 16px; cursor: pointer; }
        .error { color: #721c24; background: #f8d7da; padding: 10px; border-radius: 5px; margin-bottom: 15px; text-align: center; }
    </style></head><body>
    <div class="container">
        <h2>🔒 Dev Access</h2>
        <?php if ($authError): ?><div class="error"><?= htmlspecialchars($authError) ?></div><?php endif; ?>
        <form method="POST">
            <input type="password" name="dev_secret" placeholder="Enter secret key" required autofocus>
            <button type="submit">Unlock</button>
        </form>
    </div></body></html>
    <?php
    exit;
}

require_once '../config/database.php';
echo "<style>body{font-family:Arial,sans-serif;margin:40px;background:#f5f5f5;}h2{color:#333;}code{background:#f0f0f0;padding:10px;display:block;margin:10px 0;border-radius:4px;}a{color:#667eea;}</style>";
echo "<h2>HG Community - Connection Test</h2>";
echo "<p style='background:#d4edda;padding:10px;border-radius:5px;color:#155724;'>🔒 Authenticated via secret key.</p>";

try {
    $database = new Database();
    $db = $database->getConnection();
    if ($db) {
        echo "<p style='color:green;'>✅ Database connection successful!</p>";
        $tables = ['users','channels','messages','invites','user_permissions','direct_messages','typing_indicators','unread_counts'];
        echo "<h3>Table Status:</h3>";
        foreach ($tables as $table) {
            $stmt = $db->prepare("SHOW TABLES LIKE '$table'");
            $stmt->execute();
            if ($stmt->rowCount() > 0) echo "<p style='color:green;'>✅ Table '$table' exists</p>";
            else echo "<p style='color:orange;'>⚠️ Table '$table' not found</p>";
        }
        $adminStmt = $db->prepare("SELECT COUNT(*) as count FROM users WHERE role = 'admin'");
        $adminStmt->execute();
        $adminResult = $adminStmt->fetch(PDO::FETCH_ASSOC);
        if ($adminResult['count'] > 0) echo "<p style='color:green;'>✅ Admin user exists</p>";
        else echo "<p style='color:red;'>❌ No admin user found — <a href='create-admin.php'>Create one</a></p>";
        echo "<hr><p><a href='../login.php'>Go to Login Page</a></p>";
    } else {
        echo "<p style='color:red;'>❌ Database connection failed!</p>";
    }
} catch (Exception $e) {
    echo "<p style='color:red;'>❌ Connection Error: ".$e->getMessage()."</p>";
}
