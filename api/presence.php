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

// ── GET: SSE presence stream ───────────────────────────────────────────────────
if ($method === 'GET') {
    header('Content-Type: text/event-stream');
    header('Cache-Control: no-cache');
    header('X-Accel-Buffering: no');
    @ob_end_flush();

    $end = time() + 55;

    while (time() < $end) {
        // Touch current user's last_active on every poll
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

        // Clean up avatars (ensure path is usable on frontend)
        foreach ($online as &$u) {
            $u['avatar'] = $u['avatar'] ?: 'assets/images/default-avatar.png';
        }
        unset($u);

        $payload = json_encode(['online' => $online, 'count' => count($online)]);
        echo "data: {$payload}\n\n";

        if (ob_get_level() > 0) ob_flush();
        flush();

        if (connection_aborted()) break;
        sleep(15);
    }

    echo "event: done\ndata: {}\n\n";
    if (ob_get_level() > 0) ob_flush();
    flush();
    exit;
}

http_response_code(405);
header('Content-Type: application/json');
echo json_encode(['success' => false, 'message' => 'Method not allowed']);
?>
