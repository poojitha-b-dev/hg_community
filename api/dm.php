<?php
/**
 * api/dm.php — Direct Messaging endpoint
 *
 * GET  ?conversation=userId  → fetch messages between current user and target
 * GET  ?conversations=1      → list all DM conversation partners
 * GET  ?unread=1             → total unread DM count (for sidebar badge)
 * POST                       → send a DM (JSON or multipart for file upload)
 * DELETE                     → soft-delete a DM (sender or admin/mod)
 */

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
$myId     = (int)$_SESSION['user_id'];

// ─── Helper: check sender account status ──────────────────────────────────────
function dmCheckSender($db, $userId) {
    $stmt = $db->prepare("SELECT status FROM users WHERE id = :id");
    $stmt->execute([':id' => $userId]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

switch ($method) {

    // ══════════════════════════════════════════════════════════════════════════
    // GET
    // ══════════════════════════════════════════════════════════════════════════
    case 'GET':

        // ── Conversation with a specific user ─────────────────────────────────
        if (isset($_GET['conversation'])) {
            $otherId = (int)$_GET['conversation'];
            if (!$otherId) {
                echo json_encode(['success' => false, 'message' => 'User ID required']);
                exit;
            }

            // Mark incoming messages from other user as read
            $db->prepare(
                "UPDATE direct_messages
                 SET is_read = 1
                 WHERE sender_id = :s AND recipient_id = :me AND is_read = 0"
            )->execute([':s' => $otherId, ':me' => $myId]);

            // Fetch the conversation
            $stmt = $db->prepare(
                "SELECT dm.*,
                        s.username AS sender_username,
                        s.avatar   AS sender_avatar,
                        s.role     AS sender_role
                 FROM direct_messages dm
                 JOIN users s ON dm.sender_id = s.id
                 WHERE dm.is_deleted = 0
                   AND ((dm.sender_id = :me1 AND dm.recipient_id = :o1)
                     OR (dm.sender_id = :o2  AND dm.recipient_id = :me2))
                 ORDER BY dm.created_at ASC
                 LIMIT 100"
            );
            $stmt->execute([
                ':me1' => $myId, ':o1' => $otherId,
                ':o2'  => $otherId, ':me2' => $myId,
            ]);
            echo json_encode(['success' => true, 'messages' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
            exit;
        }

        // ── List all DM conversation partners ─────────────────────────────────
        if (isset($_GET['conversations'])) {
            // Distinct users who have exchanged DMs with current user
            $stmt = $db->prepare(
                "SELECT DISTINCT
                    u.id AS user_id, u.username, u.avatar, u.role
                 FROM direct_messages dm
                 JOIN users u ON u.id = IF(dm.sender_id = :me1, dm.recipient_id, dm.sender_id)
                 WHERE (dm.sender_id = :me2 OR dm.recipient_id = :me3)
                   AND dm.is_deleted = 0"
            );
            $stmt->execute([':me1' => $myId, ':me2' => $myId, ':me3' => $myId]);
            $partners = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Enrich each partner: last message + unread count
            foreach ($partners as &$p) {
                $uid = (int)$p['user_id'];

                $lastStmt = $db->prepare(
                    "SELECT content, created_at
                     FROM direct_messages
                     WHERE is_deleted = 0
                       AND ((sender_id = :me  AND recipient_id = :u)
                         OR (sender_id = :u2  AND recipient_id = :me2))
                     ORDER BY created_at DESC
                     LIMIT 1"
                );
                $lastStmt->execute([':me' => $myId, ':u' => $uid, ':u2' => $uid, ':me2' => $myId]);
                $last             = $lastStmt->fetch(PDO::FETCH_ASSOC);
                $p['last_message'] = $last['content']    ?? '';
                $p['last_at']      = $last['created_at'] ?? '';

                $unreadStmt = $db->prepare(
                    "SELECT COUNT(*) FROM direct_messages
                     WHERE sender_id = :u AND recipient_id = :me
                       AND is_read = 0 AND is_deleted = 0"
                );
                $unreadStmt->execute([':u' => $uid, ':me' => $myId]);
                $p['unread_count'] = (int)$unreadStmt->fetchColumn();
            }
            unset($p);

            // Sort by most recent conversation
            usort($partners, fn($a, $b) => strcmp($b['last_at'] ?? '', $a['last_at'] ?? ''));
            echo json_encode(['success' => true, 'conversations' => $partners]);
            exit;
        }

        // ── Total unread DM count (for sidebar badge) ─────────────────────────
        if (isset($_GET['unread'])) {
            $stmt = $db->prepare(
                "SELECT COUNT(*) FROM direct_messages
                 WHERE recipient_id = :me AND is_read = 0 AND is_deleted = 0"
            );
            $stmt->execute([':me' => $myId]);
            echo json_encode(['success' => true, 'unread' => (int)$stmt->fetchColumn()]);
            exit;
        }

        echo json_encode(['success' => false, 'message' => 'Missing query parameter']);
        break;

    // ══════════════════════════════════════════════════════════════════════════
    // POST — send a DM (supports JSON and multipart for file uploads)
    // ══════════════════════════════════════════════════════════════════════════
    case 'POST':
        $recipientId = 0;
        $content     = '';
        $filePath    = null;
        $fileType    = null;

        if (!empty($_POST['recipient_id'])) {
            // Multipart form (file upload)
            $recipientId = (int)$_POST['recipient_id'];
            $content     = trim($_POST['content'] ?? '');
        } else {
            // JSON body
            $data        = json_decode(file_get_contents('php://input'), true);
            $recipientId = (int)($data['recipient_id'] ?? 0);
            $content     = trim($data['content'] ?? '');
        }

        if (!$recipientId) {
            echo json_encode(['success' => false, 'message' => 'Recipient ID required']);
            exit;
        }

        if ($recipientId === $myId) {
            echo json_encode(['success' => false, 'message' => 'You cannot DM yourself']);
            exit;
        }

        // Verify recipient exists and is not banned
        $rec = $db->prepare("SELECT id FROM users WHERE id = :id AND status != 'banned'");
        $rec->execute([':id' => $recipientId]);
        if (!$rec->fetch()) {
            echo json_encode(['success' => false, 'message' => 'Recipient not found or unavailable']);
            exit;
        }

        // Require connection — admin/mod can DM anyone
        $isMod = $auth->hasPermission('moderate_users');
        if (!$isMod) {
            $connCheck = $db->prepare(
                "SELECT id FROM connections
                 WHERE ((requester_id = :me AND addressee_id = :r)
                     OR (requester_id = :r2 AND addressee_id = :me2))
                   AND status = 'accepted'"
            );
            $connCheck->execute([':me' => $myId, ':r' => $recipientId, ':r2' => $recipientId, ':me2' => $myId]);
            if (!$connCheck->fetch()) {
                echo json_encode(['success' => false, 'message' => 'You must be connected to message this person.']);
                exit;
            }
        }

        // Check sender status
        $me      = dmCheckSender($db, $myId);
        $blocked = [
            'muted'      => 'You are muted and cannot send messages.',
            'banned'     => 'Your account has been banned.',
            'restricted' => 'Your account is restricted.',
        ];
        if (isset($blocked[$me['status']])) {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => $blocked[$me['status']]]);
            exit;
        }

        // Handle optional file upload
        if (isset($_FILES['file']) && $_FILES['file']['error'] === 0) {
            $uploadDir = __DIR__ . '/../uploads/dm/';
            if (!file_exists($uploadDir)) mkdir($uploadDir, 0777, true);
            $fileName = time() . '_' . basename($_FILES['file']['name']);
            $fileType = $_FILES['file']['type'];
            if (move_uploaded_file($_FILES['file']['tmp_name'], $uploadDir . $fileName)) {
                $filePath = 'uploads/dm/' . $fileName;
            }
        }

        if (empty($content) && !$filePath) {
            echo json_encode(['success' => false, 'message' => 'Message content or file required']);
            exit;
        }

        $ins = $db->prepare(
            "INSERT INTO direct_messages (sender_id, recipient_id, content, file_path, file_type)
             VALUES (:s, :r, :c, :fp, :ft)"
        );
        $ins->execute([
            ':s'  => $myId,
            ':r'  => $recipientId,
            ':c'  => $content,
            ':fp' => $filePath,
            ':ft' => $fileType,
        ]);

        $newId = $db->lastInsertId();

        // Return the full message row (with sender info)
        $sel = $db->prepare(
            "SELECT dm.*,
                    s.username AS sender_username,
                    s.avatar   AS sender_avatar,
                    s.role     AS sender_role
             FROM direct_messages dm
             JOIN users s ON dm.sender_id = s.id
             WHERE dm.id = :id"
        );
        $sel->execute([':id' => $newId]);
        echo json_encode(['success' => true, 'message' => $sel->fetch(PDO::FETCH_ASSOC)]);
        break;

    // ══════════════════════════════════════════════════════════════════════════
    // DELETE — soft-delete a DM
    // ══════════════════════════════════════════════════════════════════════════
    case 'DELETE':
        $data = json_decode(file_get_contents('php://input'), true);
        $dmId = (int)($data['dm_id'] ?? 0);

        if (!$dmId) {
            echo json_encode(['success' => false, 'message' => 'DM ID required']);
            exit;
        }

        $check = $db->prepare(
            "SELECT sender_id FROM direct_messages WHERE id = :id AND is_deleted = 0"
        );
        $check->execute([':id' => $dmId]);
        $dm = $check->fetch(PDO::FETCH_ASSOC);

        if (!$dm) {
            echo json_encode(['success' => false, 'message' => 'Message not found']);
            exit;
        }

        // Only sender or admin/mod can delete
        $isMod = $auth->hasPermission('moderate_users');
        if ($dm['sender_id'] != $myId && !$isMod) {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Permission denied']);
            exit;
        }

        $db->prepare(
            "UPDATE direct_messages
             SET is_deleted = 1, content = '[Message deleted]'
             WHERE id = :id"
        )->execute([':id' => $dmId]);

        echo json_encode(['success' => true]);
        break;

    default:
        http_response_code(405);
        echo json_encode(['success' => false, 'message' => 'Method not allowed']);
}
?>