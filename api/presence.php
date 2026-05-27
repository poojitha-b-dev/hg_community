<?php
/**
 * api/presence.php
 *
 * POST — heartbeat (call every 20 s to stay "online")
 * GET  — SSE: push full online-user list every 15 s
 *
 * Presence is derived from users.last_active.
 * A user is "online" when last_active > NOW() - 2 minutes.
 */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../config/database.php';

$auth = new Auth();
if (!$auth->isLoggedIn()) {
    http_response_code(401);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$database = new Database();
$db       = $database->getConnection();
$method   = $_SERVER['REQUEST_METHOD'];

// ── POST: touch last_active ────────────────────────────────────────────────────
if ($method === 'POST') {
    header('Content-Type: application/json');

    $stmt = $db->prepare("UPDATE users SET last_active = NOW() WHERE id = :id");
    $stmt->execute([':id' => $_SESSION['user_id']]);

    echo json_encode(['success' => true]);
    exit;
}

// ── GET: plain JSON poll (replaces SSE — works on Railway/proxies) ────────────
if ($method === 'GET') {
    header('Content-Type: application/json');

    // Touch current user's last_active
    $touch = $db->prepare("UPDATE users SET last_active = NOW() WHERE id = :id");
    $touch->execute([':id' => $_SESSION['user_id']]);

    // Fetch online users (active in last 2 minutes)
    $stmt = $db->prepare(
        "SELECT id, username, role, avatar, last_active
         FROM users
         WHERE last_active > DATE_SUB(NOW(), INTERVAL 2 MINUTE)
           AND status != 'banned'
         ORDER BY username ASC"
    );
    $stmt->execute();
    $online = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Fix avatar paths
    foreach ($online as &$u) {
        if (empty($u['avatar']) || $u['avatar'] === 'default-avatar.png') {
            $u['avatar'] = 'assets/images/default-avatar.png';
        }
    }
    unset($u);

    echo json_encode(['success' => true, 'online' => $online, 'count' => count($online)]);
    exit;
}

http_response_code(405);
header('Content-Type: application/json');
echo json_encode(['success' => false, 'message' => 'Method not allowed']);
?>
