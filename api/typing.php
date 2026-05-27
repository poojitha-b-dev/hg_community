<?php
/**
 * api/typing.php
 *
 * Two modes:
 *  POST  — user is typing (heartbeat, call every ~2 seconds while typing)
 *  GET   — SSE stream: pushes who is typing in a channel every 2 seconds
 *
 * Rows in typing_indicators expire after 5 s without a heartbeat.
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

$method = $_SERVER['REQUEST_METHOD'];

// ── POST: register / refresh typing heartbeat ─────────────────────────────────
if ($method === 'POST') {
    header('Content-Type: application/json');

    $data      = json_decode(file_get_contents('php://input'), true);
    $channelId = (int)($data['channel_id'] ?? 0);
    $userId    = (int)$_SESSION['user_id'];
    $isTyping  = (bool)($data['is_typing'] ?? true);

    if (!$channelId) {
        echo json_encode(['success' => false, 'message' => 'channel_id required']);
        exit;
    }

    if ($isTyping) {
        // Upsert: update timestamp so the "expiry" check sees a fresh row
        $stmt = $db->prepare(
            "INSERT INTO typing_indicators (user_id, channel_id, updated_at)
             VALUES (:uid, :cid, NOW())
             ON DUPLICATE KEY UPDATE updated_at = NOW()"
        );
        $stmt->execute([':uid' => $userId, ':cid' => $channelId]);
    } else {
        // Explicit stop typing
        $stmt = $db->prepare(
            "DELETE FROM typing_indicators WHERE user_id = :uid AND channel_id = :cid"
        );
        $stmt->execute([':uid' => $userId, ':cid' => $channelId]);
    }

    echo json_encode(['success' => true]);
    exit;
}

// ── GET: plain JSON poll (replaces SSE — works on Railway/proxies) ────────────
if ($method === 'GET') {
    header('Content-Type: application/json');

    $channelId = (int)($_GET['channel_id'] ?? 0);
    if (!$channelId) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'channel_id required']);
        exit;
    }

    $myId = (int)$_SESSION['user_id'];

    // Purge stale rows (> 5 seconds old)
    $db->exec("DELETE FROM typing_indicators WHERE updated_at < DATE_SUB(NOW(), INTERVAL 5 SECOND)");

    // Fetch who is currently typing in this channel (excluding self)
    $stmt = $db->prepare(
        "SELECT u.username
         FROM typing_indicators ti
         JOIN users u ON ti.user_id = u.id
         WHERE ti.channel_id = :cid AND ti.user_id != :uid"
    );
    $stmt->execute([':cid' => $channelId, ':uid' => $myId]);
    $typingUsers = $stmt->fetchAll(PDO::FETCH_COLUMN);

    // Build human-readable label
    $label = '';
    $count = count($typingUsers);
    if ($count === 1) {
        $label = $typingUsers[0] . ' is typing…';
    } elseif ($count === 2) {
        $label = $typingUsers[0] . ' and ' . $typingUsers[1] . ' are typing…';
    } elseif ($count > 2) {
        $label = 'Several people are typing…';
    }

    echo json_encode(['success' => true, 'typing' => $typingUsers, 'label' => $label]);
    exit;
}

// Fallback
http_response_code(405);
header('Content-Type: application/json');
echo json_encode(['success' => false, 'message' => 'Method not allowed']);
?>
