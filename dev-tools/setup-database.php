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
echo "<style>body{font-family:Arial,sans-serif;margin:40px;background:#f5f5f5;}h2,h3{color:#333;}code{background:#eee;padding:2px 6px;border-radius:3px;font-size:.85em;}a{color:#667eea;text-decoration:none;}a:hover{text-decoration:underline;}</style>";
echo "<h2>HG Community - Database Setup</h2>";
echo "<p style='background:#d4edda;padding:10px;border-radius:5px;color:#155724;'>🔒 Authenticated via secret key.</p>";

try {
    $database = new Database();
    $db = $database->getConnection();
    if (!$db) throw new Exception("Database connection failed");
    echo "<p style='color:green;'>✅ Connected to database successfully!</p>";

    $tables = [
        "users" => "CREATE TABLE IF NOT EXISTS users (id INT AUTO_INCREMENT PRIMARY KEY, username VARCHAR(50) UNIQUE NOT NULL, email VARCHAR(100) UNIQUE NOT NULL, phone VARCHAR(15), password VARCHAR(255) NOT NULL, role ENUM('admin','moderator','member') DEFAULT 'member', status ENUM('active','banned','restricted','muted') DEFAULT 'active', avatar VARCHAR(255) DEFAULT 'default-avatar.png', bio TEXT, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, last_active TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP)",
        "channels" => "CREATE TABLE IF NOT EXISTS channels (id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(100) NOT NULL, description TEXT, type ENUM('announcement','general','team','technical') DEFAULT 'general', team_name VARCHAR(50), created_by INT, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, FOREIGN KEY (created_by) REFERENCES users(id))",
        "messages" => "CREATE TABLE IF NOT EXISTS messages (id INT AUTO_INCREMENT PRIMARY KEY, channel_id INT, user_id INT, content TEXT NOT NULL, file_path VARCHAR(255), file_type VARCHAR(50), is_pinned TINYINT(1) DEFAULT 0, is_deleted TINYINT(1) DEFAULT 0, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, edited_at TIMESTAMP NULL DEFAULT NULL, FOREIGN KEY (channel_id) REFERENCES channels(id) ON DELETE CASCADE, FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE)",
        "invites" => "CREATE TABLE IF NOT EXISTS invites (id INT AUTO_INCREMENT PRIMARY KEY, invite_code VARCHAR(32) UNIQUE NOT NULL, created_by INT, email VARCHAR(100), phone VARCHAR(15), role ENUM('moderator','member') DEFAULT 'member', expires_at TIMESTAMP, used_at TIMESTAMP NULL, used_by INT NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, FOREIGN KEY (created_by) REFERENCES users(id), FOREIGN KEY (used_by) REFERENCES users(id))",
        "user_permissions" => "CREATE TABLE IF NOT EXISTS user_permissions (id INT AUTO_INCREMENT PRIMARY KEY, user_id INT, channel_id INT, permission ENUM('read','write','moderate','manage') DEFAULT 'read', granted_by INT, granted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE, FOREIGN KEY (channel_id) REFERENCES channels(id) ON DELETE CASCADE, FOREIGN KEY (granted_by) REFERENCES users(id))",
        "direct_messages" => "CREATE TABLE IF NOT EXISTS direct_messages (id INT AUTO_INCREMENT PRIMARY KEY, sender_id INT NOT NULL, recipient_id INT NOT NULL, content TEXT NOT NULL, file_path VARCHAR(255), file_type VARCHAR(50), is_read TINYINT(1) DEFAULT 0, is_deleted TINYINT(1) DEFAULT 0, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, edited_at TIMESTAMP NULL DEFAULT NULL, FOREIGN KEY (sender_id) REFERENCES users(id) ON DELETE CASCADE, FOREIGN KEY (recipient_id) REFERENCES users(id) ON DELETE CASCADE)",
        "typing_indicators" => "CREATE TABLE IF NOT EXISTS typing_indicators (id INT AUTO_INCREMENT PRIMARY KEY, user_id INT NOT NULL, channel_id INT NOT NULL, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, UNIQUE KEY uniq_user_channel (user_id, channel_id), FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE, FOREIGN KEY (channel_id) REFERENCES channels(id) ON DELETE CASCADE)",
        "unread_counts" => "CREATE TABLE IF NOT EXISTS unread_counts (id INT AUTO_INCREMENT PRIMARY KEY, user_id INT NOT NULL, channel_id INT NOT NULL, count INT DEFAULT 0, last_read TIMESTAMP DEFAULT CURRENT_TIMESTAMP, UNIQUE KEY uniq_user_channel (user_id, channel_id), FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE, FOREIGN KEY (channel_id) REFERENCES channels(id) ON DELETE CASCADE)"
    ];

    echo "<h3>Creating / Verifying Tables:</h3>";
    foreach ($tables as $tableName => $sql) {
        try { $db->exec($sql); echo "<p style='color:green;'>✅ Table '<strong>$tableName</strong>' OK</p>"; }
        catch (Exception $e) { echo "<p style='color:red;'>❌ Error on '$tableName': ".$e->getMessage()."</p>"; }
    }

    echo "<h3>Running Schema Migrations:</h3>";
    $migrations = [
        "ALTER TABLE messages ADD COLUMN IF NOT EXISTS is_pinned TINYINT(1) DEFAULT 0",
        "ALTER TABLE messages ADD COLUMN IF NOT EXISTS is_deleted TINYINT(1) DEFAULT 0",
        "ALTER TABLE messages ADD COLUMN IF NOT EXISTS edited_at TIMESTAMP NULL DEFAULT NULL",
        "ALTER TABLE users ADD COLUMN IF NOT EXISTS bio TEXT AFTER avatar",
    ];
    foreach ($migrations as $sql) {
        try { $db->exec($sql); echo "<p style='color:green;'>✅ Migration OK</p>"; }
        catch (Exception $e) {
            if (strpos($e->getMessage(), 'Duplicate column') !== false) echo "<p style='color:blue;'>ℹ️ Column already exists.</p>";
            else echo "<p style='color:orange;'>⚠️ Skipped: ".$e->getMessage()."</p>";
        }
    }

    echo "<h3>Creating Default Channels:</h3>";
    $defaultChannels = [
        ['Announcements','Important updates','announcement',null],
        ['General Chat','General discussions','general',null],
        ['Frontend Team','Frontend discussions','team','Frontend'],
        ['Backend Team','Backend discussions','team','Backend'],
        ['R&D Team','R&D discussions','team','R&D'],
        ['Technical Discussions','Coding topics','technical',null],
        ['Error Resolutions','Debugging help','technical',null],
    ];
    $checkQ = $db->prepare("SELECT COUNT(*) FROM channels WHERE name = ?");
    $insertQ = $db->prepare("INSERT INTO channels (name, description, type, team_name) VALUES (?, ?, ?, ?)");
    foreach ($defaultChannels as [$name, $desc, $type, $team]) {
        $checkQ->execute([$name]);
        if ($checkQ->fetchColumn() == 0) { $insertQ->execute([$name,$desc,$type,$team]); echo "<p style='color:green;'>✅ Channel '<strong>$name</strong>' created</p>"; }
        else echo "<p style='color:blue;'>ℹ️ Channel '<strong>$name</strong>' already exists</p>";
    }

    echo "<hr><h3>Setup Complete! 🎉</h3><ol>";
    echo "<li><a href='create-admin.php'>Create Admin User</a></li>";
    echo "<li><a href='test-connection.php'>Test Connection</a></li>";
    echo "<li><a href='../login.php'>Login to HG Community</a></li></ol>";
} catch (Exception $e) {
    echo "<p style='color:red;'>❌ Setup Error: ".$e->getMessage()."</p>";
}
