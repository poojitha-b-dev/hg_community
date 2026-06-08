<?php
/**
 * HG Community — setup-database.php (Phase 1 consolidated)
 * All tables including batch migrations in one place.
 * Protected by dev secret key.
 */
define('DEV_SECRET', getenv('DEV_SECRET') ?: 'HGAdmin@2026');

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

if (!$authenticated) { ?>
<!DOCTYPE html><html><head><meta charset="UTF-8"><title>Dev Access</title>
<style>body{font-family:Arial,sans-serif;margin:0;padding:40px;background:linear-gradient(135deg,#667eea,#764ba2);min-height:100vh}
.c{max-width:360px;margin:80px auto;background:#fff;padding:40px;border-radius:10px;box-shadow:0 10px 30px rgba(0,0,0,.2)}
h2{text-align:center;color:#333}input{width:100%;padding:12px;border:1px solid #ddd;border-radius:5px;font-size:16px;box-sizing:border-box;margin-bottom:15px}
button{width:100%;padding:12px;background:#667eea;color:#fff;border:none;border-radius:5px;font-size:16px;cursor:pointer}
.e{color:#721c24;background:#f8d7da;padding:10px;border-radius:5px;margin-bottom:15px;text-align:center}</style>
</head><body><div class="c"><h2>🔒 Dev Access</h2>
<?php if($authError):?><div class="e"><?=htmlspecialchars($authError)?></div><?php endif?>
<form method="POST"><input type="password" name="dev_secret" placeholder="Enter secret key" required autofocus>
<button type="submit">Unlock</button></form></div></body></html>
<?php exit; }

require_once '../config/database.php';
$database = new Database();
$db = $database->getConnection();

echo "<style>body{font-family:Arial,sans-serif;margin:40px;background:#f5f5f5}h2,h3{color:#333}a{color:#667eea}</style>";
echo "<h2>HG Community — Database Setup</h2>";
echo "<p style='background:#d4edda;padding:10px;border-radius:5px;color:#155724'>🔒 Authenticated.</p>";

$tables = [
"users" =>
    "CREATE TABLE IF NOT EXISTS users (
        id               INT AUTO_INCREMENT PRIMARY KEY,
        username         VARCHAR(50)  UNIQUE NOT NULL,
        email            VARCHAR(100) UNIQUE NOT NULL,
        phone            VARCHAR(15),
        password         VARCHAR(255) NOT NULL,
        role             ENUM('admin','moderator','member') DEFAULT 'member',
        status           ENUM('active','banned','restricted','muted') DEFAULT 'active',
        avatar           VARCHAR(255) DEFAULT 'default-avatar.png',
        bio              TEXT,
        avatar_visibility ENUM('everyone','connections','nobody') DEFAULT 'everyone',
        created_at       TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        last_active      TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_last_active (last_active),
        INDEX idx_role (role),
        INDEX idx_status (status)
    )",

"login_attempts" =>
    "CREATE TABLE IF NOT EXISTS login_attempts (
        id           INT AUTO_INCREMENT PRIMARY KEY,
        attempt_key  VARCHAR(64) UNIQUE NOT NULL,
        attempts     INT DEFAULT 1,
        locked_until TIMESTAMP NULL,
        last_attempt TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_key (attempt_key)
    )",

"channels" =>
    "CREATE TABLE IF NOT EXISTS channels (
        id          INT AUTO_INCREMENT PRIMARY KEY,
        name        VARCHAR(100) NOT NULL,
        description TEXT,
        type        ENUM('announcement','general','team','technical') DEFAULT 'general',
        team_name   VARCHAR(50),
        created_by  INT,
        created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (created_by) REFERENCES users(id),
        INDEX idx_type (type)
    )",

"messages" =>
    "CREATE TABLE IF NOT EXISTS messages (
        id          INT AUTO_INCREMENT PRIMARY KEY,
        channel_id  INT,
        user_id     INT,
        content     TEXT NOT NULL,
        file_path   VARCHAR(255),
        file_type   VARCHAR(100),
        is_pinned   TINYINT(1) DEFAULT 0,
        is_deleted  TINYINT(1) DEFAULT 0,
        created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        edited_at   TIMESTAMP NULL DEFAULT NULL,
        FOREIGN KEY (channel_id) REFERENCES channels(id) ON DELETE CASCADE,
        FOREIGN KEY (user_id)    REFERENCES users(id)    ON DELETE CASCADE,
        INDEX idx_channel_created (channel_id, created_at),
        INDEX idx_user (user_id)
    )",

"invites" =>
    "CREATE TABLE IF NOT EXISTS invites (
        id          INT AUTO_INCREMENT PRIMARY KEY,
        invite_code VARCHAR(32) UNIQUE NOT NULL,
        created_by  INT,
        email       VARCHAR(100),
        phone       VARCHAR(15),
        role        ENUM('moderator','member') DEFAULT 'member',
        invite_type ENUM('single','group') DEFAULT 'single',
        expires_at  TIMESTAMP,
        used_at     TIMESTAMP NULL,
        used_by     INT NULL,
        created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (created_by) REFERENCES users(id),
        FOREIGN KEY (used_by)    REFERENCES users(id)
    )",

"user_permissions" =>
    "CREATE TABLE IF NOT EXISTS user_permissions (
        id          INT AUTO_INCREMENT PRIMARY KEY,
        user_id     INT,
        channel_id  INT,
        permission  ENUM('read','write','moderate','manage') DEFAULT 'read',
        granted_by  INT,
        granted_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (user_id)    REFERENCES users(id)    ON DELETE CASCADE,
        FOREIGN KEY (channel_id) REFERENCES channels(id) ON DELETE CASCADE,
        FOREIGN KEY (granted_by) REFERENCES users(id)
    )",

"direct_messages" =>
    "CREATE TABLE IF NOT EXISTS direct_messages (
        id           INT AUTO_INCREMENT PRIMARY KEY,
        sender_id    INT NOT NULL,
        recipient_id INT NOT NULL,
        content      TEXT NOT NULL,
        file_path    VARCHAR(255),
        file_type    VARCHAR(100),
        is_read      TINYINT(1) DEFAULT 0,
        is_deleted   TINYINT(1) DEFAULT 0,
        created_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        edited_at    TIMESTAMP NULL DEFAULT NULL,
        FOREIGN KEY (sender_id)    REFERENCES users(id) ON DELETE CASCADE,
        FOREIGN KEY (recipient_id) REFERENCES users(id) ON DELETE CASCADE,
        INDEX idx_conversation (sender_id, recipient_id, created_at),
        INDEX idx_unread (recipient_id, is_read)
    )",

"typing_indicators" =>
    "CREATE TABLE IF NOT EXISTS typing_indicators (
        id          INT AUTO_INCREMENT PRIMARY KEY,
        user_id     INT NOT NULL,
        channel_id  INT NOT NULL,
        updated_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_user_channel (user_id, channel_id),
        FOREIGN KEY (user_id)    REFERENCES users(id)    ON DELETE CASCADE,
        FOREIGN KEY (channel_id) REFERENCES channels(id) ON DELETE CASCADE
    )",

"unread_counts" =>
    "CREATE TABLE IF NOT EXISTS unread_counts (
        id          INT AUTO_INCREMENT PRIMARY KEY,
        user_id     INT NOT NULL,
        channel_id  INT NOT NULL,
        count       INT DEFAULT 0,
        last_read   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_user_channel (user_id, channel_id),
        FOREIGN KEY (user_id)    REFERENCES users(id)    ON DELETE CASCADE,
        FOREIGN KEY (channel_id) REFERENCES channels(id) ON DELETE CASCADE
    )",

"team_members" =>
    "CREATE TABLE IF NOT EXISTS team_members (
        id         INT AUTO_INCREMENT PRIMARY KEY,
        channel_id INT NOT NULL,
        user_id    INT NOT NULL,
        added_by   INT NOT NULL,
        added_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_team_member (channel_id, user_id),
        FOREIGN KEY (channel_id) REFERENCES channels(id) ON DELETE CASCADE,
        FOREIGN KEY (user_id)    REFERENCES users(id)    ON DELETE CASCADE,
        FOREIGN KEY (added_by)   REFERENCES users(id)    ON DELETE CASCADE
    )",

"connections" =>
    "CREATE TABLE IF NOT EXISTS connections (
        id           INT AUTO_INCREMENT PRIMARY KEY,
        requester_id INT NOT NULL,
        addressee_id INT NOT NULL,
        status       ENUM('pending','accepted') DEFAULT 'pending',
        created_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        accepted_at  TIMESTAMP NULL,
        UNIQUE KEY uniq_connection (requester_id, addressee_id),
        FOREIGN KEY (requester_id) REFERENCES users(id) ON DELETE CASCADE,
        FOREIGN KEY (addressee_id) REFERENCES users(id) ON DELETE CASCADE,
        INDEX idx_addressee_status (addressee_id, status)
    )",

"group_chats" =>
    "CREATE TABLE IF NOT EXISTS group_chats (
        id         INT AUTO_INCREMENT PRIMARY KEY,
        name       VARCHAR(100) NOT NULL,
        created_by INT NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE CASCADE
    )",

"group_chat_members" =>
    "CREATE TABLE IF NOT EXISTS group_chat_members (
        id        INT AUTO_INCREMENT PRIMARY KEY,
        group_id  INT NOT NULL,
        user_id   INT NOT NULL,
        joined_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_group_member (group_id, user_id),
        FOREIGN KEY (group_id) REFERENCES group_chats(id) ON DELETE CASCADE,
        FOREIGN KEY (user_id)  REFERENCES users(id)       ON DELETE CASCADE
    )",

"group_messages" =>
    "CREATE TABLE IF NOT EXISTS group_messages (
        id         INT AUTO_INCREMENT PRIMARY KEY,
        group_id   INT NOT NULL,
        sender_id  INT NOT NULL,
        content    TEXT NOT NULL,
        is_deleted TINYINT(1) DEFAULT 0,
        is_read_by TEXT DEFAULT '',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (group_id)  REFERENCES group_chats(id) ON DELETE CASCADE,
        FOREIGN KEY (sender_id) REFERENCES users(id)       ON DELETE CASCADE,
        INDEX idx_group_created (group_id, created_at)
    )",
];

echo "<h3>Creating / Verifying Tables:</h3>";
foreach ($tables as $name => $sql) {
    try {
        $db->exec($sql);
        echo "<p style='color:green'>✅ <strong>$name</strong></p>";
    } catch (Exception $e) {
        echo "<p style='color:red'>❌ $name: " . $e->getMessage() . "</p>";
    }
}

// Safe migrations for existing installs
$migrations = [
    "ALTER TABLE users ADD COLUMN IF NOT EXISTS avatar_visibility ENUM('everyone','connections','nobody') DEFAULT 'everyone' AFTER bio",
    "ALTER TABLE invites ADD COLUMN IF NOT EXISTS invite_type ENUM('single','group') DEFAULT 'single' AFTER role",
    "ALTER TABLE messages ADD COLUMN IF NOT EXISTS is_pinned  TINYINT(1) DEFAULT 0",
    "ALTER TABLE messages ADD COLUMN IF NOT EXISTS is_deleted TINYINT(1) DEFAULT 0",
    "ALTER TABLE messages ADD COLUMN IF NOT EXISTS edited_at  TIMESTAMP NULL DEFAULT NULL",
    "ALTER TABLE messages MODIFY file_type VARCHAR(100)",
    "ALTER TABLE direct_messages MODIFY file_type VARCHAR(100)",
];

echo "<h3>Migrations:</h3>";
foreach ($migrations as $sql) {
    try {
        $db->exec($sql);
        echo "<p style='color:green'>✅ " . htmlspecialchars(substr($sql,0,80)) . "…</p>";
    } catch (Exception $e) {
        if (strpos($e->getMessage(),'Duplicate column') !== false)
            echo "<p style='color:blue'>ℹ️ Already exists — skipped</p>";
        else
            echo "<p style='color:orange'>⚠️ " . $e->getMessage() . "</p>";
    }
}

// Default channels
$defaultChannels = [
    ['Announcements','Important updates and announcements','announcement',null],
    ['General Chat','General discussions','general',null],
    ['Frontend Team','Frontend development discussions','team','Frontend'],
    ['Backend Team','Backend development discussions','team','Backend'],
    ['R&D Team','Research and development discussions','team','R&D'],
    ['Technical Discussions','General coding and technical topics','technical',null],
    ['Error Resolutions','Debugging help and error solving','technical',null],
];

echo "<h3>Default Channels:</h3>";
$chk = $db->prepare("SELECT COUNT(*) FROM channels WHERE name=?");
$ins = $db->prepare("INSERT INTO channels (name,description,type,team_name) VALUES (?,?,?,?)");
foreach ($defaultChannels as [$n,$d,$t,$tn]) {
    $chk->execute([$n]);
    if ($chk->fetchColumn() == 0) {
        $ins->execute([$n,$d,$t,$tn]);
        echo "<p style='color:green'>✅ Created: <strong>$n</strong></p>";
    } else {
        echo "<p style='color:blue'>ℹ️ Exists: <strong>$n</strong></p>";
    }
}

echo "<hr><h3>✅ Setup Complete!</h3><ol>
<li><a href='create-admin.php'>Create Admin User</a></li>
<li><a href='test-connection.php'>Test Connection</a></li>
<li><a href='../login.php'>Go to Login</a></li></ol>";
