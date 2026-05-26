<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../config/database.php';

header('Content-Type: application/json');

$auth = new Auth();
if (!$auth->isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$database = new Database();
$db       = $database->getConnection();
$method   = $_SERVER['REQUEST_METHOD'];

// ─── Helper: fetch current user status ───────────────────────────────────────
function getUserStatus($db, $userId) {
    $stmt = $db->prepare("SELECT status FROM users WHERE id = :id");
    $stmt->bindParam(':id', $userId);
    $stmt->execute();
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

// ─── Helper: mark unread count for all OTHER channel members ─────────────────
function incrementUnread($db, $channelId, $senderUserId) {
    // Get all users who have ever loaded this channel (have a row in unread_counts)
    // and also insert/increment for users who haven't (upsert pattern).
    // Practical approach: we only track users who have a row; new messages will
    // be picked up by the badge query on their next poll.
    $sql = "INSERT INTO unread_counts (user_id, channel_id, count)
            SELECT u.id, :cid, 1
            FROM users u
            WHERE u.id != :uid
              AND u.status = 'active'
            ON DUPLICATE KEY UPDATE count = count + 1";
    $stmt = $db->prepare($sql);
    $stmt->execute([':cid' => $channelId, ':uid' => $senderUserId]);
}

// ─── Helper: clear unread for a user opening a channel ───────────────────────
function clearUnread($db, $channelId, $userId) {
    $sql = "INSERT INTO unread_counts (user_id, channel_id, count, last_read)
            VALUES (:uid, :cid, 0, NOW())
            ON DUPLICATE KEY UPDATE count = 0, last_read = NOW()";
    $stmt = $db->prepare($sql);
    $stmt->execute([':uid' => $userId, ':cid' => $channelId]);
}

switch ($method) {

    // ══════════════════════════════════════════════════════════════════════════
    // GET — fetch messages, pinned messages, search, or unread counts
    // ══════════════════════════════════════════════════════════════════════════
    case 'GET':

        // ── Unread badge counts for all channels ──────────────────────────────
        if (isset($_GET['unread'])) {
            $stmt = $db->prepare(
                "SELECT channel_id, count FROM unread_counts
                 WHERE user_id = :uid AND count > 0"
            );
            $stmt->execute([':uid' => $_SESSION['user_id']]);
            $rows   = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $counts = [];
            foreach ($rows as $r) {
                $counts[$r['channel_id']] = (int)$r['count'];
            }
            echo json_encode(['success' => true, 'unread' => $counts]);
            exit;
        }

        // ── Message search ────────────────────────────────────────────────────
        if (isset($_GET['search'])) {
            $keyword   = '%' . trim($_GET['search']) . '%';
            $channelId = $_GET['channel_id'] ?? null;

            $where = "WHERE (m.content LIKE :kw OR u.username LIKE :kw2)
                        AND m.is_deleted = 0";
            $params = [':kw' => $keyword, ':kw2' => $keyword];

            if ($channelId) {
                $where .= " AND m.channel_id = :cid";
                $params[':cid'] = $channelId;
            }

            $sql = "SELECT m.*, u.username, u.role, u.avatar,
                           c.name AS channel_name
                    FROM messages m
                    JOIN users    u ON m.user_id    = u.id
                    JOIN channels c ON m.channel_id = c.id
                    $where
                    ORDER BY m.created_at DESC
                    LIMIT 100";

            $stmt = $db->prepare($sql);
            $stmt->execute($params);
            $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
            echo json_encode(['success' => true, 'messages' => $results]);
            exit;
        }

        // ── Pinned messages for a channel ─────────────────────────────────────
        if (isset($_GET['pinned'])) {
            $channelId = $_GET['channel_id'] ?? null;
            if (!$channelId) {
                echo json_encode(['success' => false, 'message' => 'Channel ID required']);
                exit;
            }
            $stmt = $db->prepare(
                "SELECT m.*, u.username, u.role, u.avatar
                 FROM messages m
                 JOIN users u ON m.user_id = u.id
                 WHERE m.channel_id = :cid AND m.is_pinned = 1 AND m.is_deleted = 0
                 ORDER BY m.created_at DESC
                 LIMIT 20"
            );
            $stmt->execute([':cid' => $channelId]);
            echo json_encode(['success' => true, 'messages' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
            exit;
        }

        // ── Normal message load ───────────────────────────────────────────────
        $channelId = $_GET['channel_id'] ?? null;
        if (!$channelId) {
            echo json_encode(['success' => false, 'message' => 'Channel ID required']);
            exit;
        }

        // Clear unread counter when user opens the channel
        clearUnread($db, $channelId, $_SESSION['user_id']);

        $stmt = $db->prepare(
            "SELECT m.*, u.username, u.role, u.avatar
             FROM messages m
             JOIN users u ON m.user_id = u.id
             WHERE m.channel_id = :cid AND m.is_deleted = 0
             ORDER BY m.created_at DESC
             LIMIT 50"
        );
        $stmt->execute([':cid' => $channelId]);
        $messages = array_reverse($stmt->fetchAll(PDO::FETCH_ASSOC));

        echo json_encode(['success' => true, 'messages' => $messages]);
        break;

    // ══════════════════════════════════════════════════════════════════════════
    // POST — send a new message
    // ══════════════════════════════════════════════════════════════════════════
    case 'POST':
        if (!$auth->hasPermission('send_message')) {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Permission denied']);
            exit;
        }

        // Status checks
        $currentUser = getUserStatus($db, $_SESSION['user_id']);
        $blocked     = ['muted' => 'You have been muted and cannot send messages.',
                        'banned' => 'Your account has been banned.',
                        'restricted' => 'Your account is restricted.'];
        if (isset($blocked[$currentUser['status']])) {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => $blocked[$currentUser['status']]]);
            exit;
        }

        $channelId = $_POST['channel_id'];

        // Announcement channel guard
        $chanStmt = $db->prepare("SELECT type FROM channels WHERE id = :id");
        $chanStmt->execute([':id' => $channelId]);
        $channel  = $chanStmt->fetch(PDO::FETCH_ASSOC);
        if ($channel && $channel['type'] === 'announcement'
            && !in_array($_SESSION['role'], ['admin', 'moderator'])) {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Only admins and moderators can post in announcement channels.']);
            exit;
        }

        $content  = $_POST['content'] ?? '';
        $filePath = null;
        $fileType = null;

        if (isset($_FILES['file']) && $_FILES['file']['error'] == 0) {
            $uploadDir = '../uploads/';
            if (!file_exists($uploadDir)) mkdir($uploadDir, 0777, true);

            $fileName = time() . '_' . basename($_FILES['file']['name']);
            $dest     = $uploadDir . $fileName;
            $fileType = $_FILES['file']['type'];

            if (move_uploaded_file($_FILES['file']['tmp_name'], $dest)) {
                $filePath = 'uploads/' . $fileName;
            }
        }

        $stmt = $db->prepare(
            "INSERT INTO messages (channel_id, user_id, content, file_path, file_type)
             VALUES (:cid, :uid, :content, :fp, :ft)"
        );
        $stmt->execute([
            ':cid'     => $channelId,
            ':uid'     => $_SESSION['user_id'],
            ':content' => $content,
            ':fp'      => $filePath,
            ':ft'      => $fileType,
        ]);

        $messageId = $db->lastInsertId();

        // Bump unread for other channel members
        incrementUnread($db, $channelId, $_SESSION['user_id']);

        $sel = $db->prepare(
            "SELECT m.*, u.username, u.role, u.avatar
             FROM messages m JOIN users u ON m.user_id = u.id
             WHERE m.id = :id"
        );
        $sel->execute([':id' => $messageId]);
        $message = $sel->fetch(PDO::FETCH_ASSOC);

        echo json_encode(['success' => true, 'message' => $message]);
        break;

    // ══════════════════════════════════════════════════════════════════════════
    // PUT — edit a message OR pin/unpin
    // ══════════════════════════════════════════════════════════════════════════
    case 'PUT':
        $data      = json_decode(file_get_contents('php://input'), true);
        $messageId = $data['message_id'] ?? null;

        if (!$messageId) {
            echo json_encode(['success' => false, 'message' => 'Message ID required']);
            exit;
        }

        // Fetch the message
        $check = $db->prepare("SELECT * FROM messages WHERE id = :id AND is_deleted = 0");
        $check->execute([':id' => $messageId]);
        $msg = $check->fetch(PDO::FETCH_ASSOC);

        if (!$msg) {
            echo json_encode(['success' => false, 'message' => 'Message not found']);
            exit;
        }

        $isOwner = $msg['user_id'] == $_SESSION['user_id'];
        $isMod   = $auth->hasPermission('moderate_users');

        // ── Pin / Unpin ───────────────────────────────────────────────────────
        if (isset($data['action']) && in_array($data['action'], ['pin', 'unpin'])) {
            if (!$isMod) {
                http_response_code(403);
                echo json_encode(['success' => false, 'message' => 'Only admins/moderators can pin messages']);
                exit;
            }
            $pinVal = $data['action'] === 'pin' ? 1 : 0;
            $upd    = $db->prepare("UPDATE messages SET is_pinned = :p WHERE id = :id");
            $upd->execute([':p' => $pinVal, ':id' => $messageId]);
            echo json_encode(['success' => true, 'pinned' => (bool)$pinVal]);
            exit;
        }

        // ── Edit content ──────────────────────────────────────────────────────
        if (!$isOwner) {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'You can only edit your own messages']);
            exit;
        }

        $newContent = trim($data['content'] ?? '');
        if ($newContent === '') {
            echo json_encode(['success' => false, 'message' => 'Content cannot be empty']);
            exit;
        }

        $upd = $db->prepare(
            "UPDATE messages SET content = :content, edited_at = NOW() WHERE id = :id"
        );
        $upd->execute([':content' => $newContent, ':id' => $messageId]);

        // Return the updated message
        $sel = $db->prepare(
            "SELECT m.*, u.username, u.role, u.avatar
             FROM messages m JOIN users u ON m.user_id = u.id
             WHERE m.id = :id"
        );
        $sel->execute([':id' => $messageId]);
        $updated = $sel->fetch(PDO::FETCH_ASSOC);

        echo json_encode(['success' => true, 'message' => $updated]);
        break;

    // ══════════════════════════════════════════════════════════════════════════
    // DELETE — soft-delete a message
    // ══════════════════════════════════════════════════════════════════════════
    case 'DELETE':
        $data      = json_decode(file_get_contents('php://input'), true);
        $messageId = $data['message_id'] ?? null;

        if (!$messageId) {
            echo json_encode(['success' => false, 'message' => 'Message ID required']);
            exit;
        }

        $check = $db->prepare("SELECT user_id FROM messages WHERE id = :id");
        $check->execute([':id' => $messageId]);
        $msg = $check->fetch(PDO::FETCH_ASSOC);

        if (!$msg) {
            echo json_encode(['success' => false, 'message' => 'Message not found']);
            exit;
        }

        $isOwner = $msg['user_id'] == $_SESSION['user_id'];
        $isMod   = $auth->hasPermission('moderate_users');

        if (!$isOwner && !$isMod) {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Permission denied']);
            exit;
        }

        // Soft delete — keeps the row but marks it deleted so thread context is preserved
        $del = $db->prepare("UPDATE messages SET is_deleted = 1, content = '[Message deleted]' WHERE id = :id");
        $del->execute([':id' => $messageId]);

        echo json_encode(['success' => true, 'message' => 'Message deleted']);
        break;

    default:
        http_response_code(405);
        echo json_encode(['success' => false, 'message' => 'Method not allowed']);
}
?>
