<?php
/**
 * api/group_chats.php — Informal private group chats
 *
 * GET  ?list=1              → my groups
 * GET  ?messages=groupId   → messages in a group
 * POST action=create        → create a group
 * POST action=send          → send a message to a group
 * POST action=add_member    → add a member (creator only)
 * POST action=leave         → leave a group
 * DELETE                    → delete a message (sender only)
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
$myId     = (int)$_SESSION['user_id'];
$method   = $_SERVER['REQUEST_METHOD'];

function fixAvatar($a) {
    return (empty($a) || $a === 'default-avatar.png') ? 'assets/images/default-avatar.png' : $a;
}

switch ($method) {

    case 'GET':

        // List my groups
        if (isset($_GET['list'])) {
            $stmt = $db->prepare(
                "SELECT g.id, g.name, g.created_by, g.created_at,
                        (SELECT content FROM group_messages gm WHERE gm.group_id = g.id
                         AND gm.is_deleted = 0 ORDER BY gm.created_at DESC LIMIT 1) AS last_message,
                        (SELECT created_at FROM group_messages gm WHERE gm.group_id = g.id
                         AND gm.is_deleted = 0 ORDER BY gm.created_at DESC LIMIT 1) AS last_at,
                        (SELECT COUNT(*) FROM group_messages gm WHERE gm.group_id = g.id
                         AND gm.is_read_by NOT LIKE CONCAT('%|',:me,'|%')
                         AND gm.sender_id != :me2 AND gm.is_deleted = 0) AS unread_count
                 FROM group_chats g
                 JOIN group_chat_members gcm ON gcm.group_id = g.id AND gcm.user_id = :me3
                 ORDER BY last_at DESC, g.created_at DESC"
            );
            $stmt->execute([':me' => $myId, ':me2' => $myId, ':me3' => $myId]);
            $groups = $stmt->fetchAll(PDO::FETCH_ASSOC);
            echo json_encode(['success' => true, 'groups' => $groups]);
            exit;
        }

        // Messages in a group
        if (isset($_GET['messages'])) {
            $groupId = (int)$_GET['messages'];

            // Verify membership
            $chk = $db->prepare("SELECT id FROM group_chat_members WHERE group_id=:g AND user_id=:u");
            $chk->execute([':g' => $groupId, ':u' => $myId]);
            if (!$chk->fetch()) {
                http_response_code(403);
                echo json_encode(['success' => false, 'message' => 'Not a member of this group']);
                exit;
            }

            // Mark as read
            $db->prepare(
                "UPDATE group_messages SET is_read_by = CONCAT(is_read_by, :me, '|')
                 WHERE group_id = :g AND sender_id != :me2
                   AND is_read_by NOT LIKE CONCAT('%|',:me3,'|%')"
            )->execute([':me' => $myId.'|', ':g' => $groupId, ':me2' => $myId, ':me3' => $myId]);

            $stmt = $db->prepare(
                "SELECT gm.*, u.username AS sender_username, u.avatar AS sender_avatar, u.role AS sender_role
                 FROM group_messages gm
                 JOIN users u ON gm.sender_id = u.id
                 WHERE gm.group_id = :g AND gm.is_deleted = 0
                 ORDER BY gm.created_at ASC LIMIT 100"
            );
            $stmt->execute([':g' => $groupId]);
            $messages = $stmt->fetchAll(PDO::FETCH_ASSOC);
            foreach ($messages as &$m) { $m['sender_avatar'] = fixAvatar($m['sender_avatar']); }

            // Group info + members
            $gInfo = $db->prepare("SELECT * FROM group_chats WHERE id = :g");
            $gInfo->execute([':g' => $groupId]);
            $group = $gInfo->fetch(PDO::FETCH_ASSOC);

            $mStmt = $db->prepare(
                "SELECT u.id, u.username, u.avatar, u.role
                 FROM group_chat_members gcm JOIN users u ON gcm.user_id = u.id
                 WHERE gcm.group_id = :g ORDER BY u.username"
            );
            $mStmt->execute([':g' => $groupId]);
            $members = $mStmt->fetchAll(PDO::FETCH_ASSOC);
            foreach ($members as &$m) { $m['avatar'] = fixAvatar($m['avatar']); }

            echo json_encode(['success' => true, 'messages' => $messages, 'group' => $group, 'members' => $members]);
            exit;
        }

        echo json_encode(['success' => false, 'message' => 'Missing parameter']);
        break;

    case 'POST':
        $data   = json_decode(file_get_contents('php://input'), true);
        $action = $data['action'] ?? '';

        // Create a group
        if ($action === 'create') {
            $name    = trim($data['name'] ?? '');
            $members = array_map('intval', $data['members'] ?? []);

            if (empty($name)) {
                echo json_encode(['success' => false, 'message' => 'Group name required']);
                exit;
            }
            if (count($members) < 1) {
                echo json_encode(['success' => false, 'message' => 'Add at least 1 other member']);
                exit;
            }

            // Verify all members are connections
            foreach ($members as $uid) {
                $connChk = $db->prepare(
                    "SELECT id FROM connections
                     WHERE ((requester_id=:me AND addressee_id=:u) OR (requester_id=:u2 AND addressee_id=:me2))
                       AND status='accepted'"
                );
                $connChk->execute([':me' => $myId, ':u' => $uid, ':u2' => $uid, ':me2' => $myId]);
                if (!$connChk->fetch()) {
                    echo json_encode(['success' => false, 'message' => 'You can only add connections to a group']);
                    exit;
                }
            }

            $db->prepare("INSERT INTO group_chats (name, created_by) VALUES (?, ?)")
               ->execute([$name, $myId]);
            $groupId = $db->lastInsertId();

            // Add creator + members
            $allMembers = array_unique(array_merge([$myId], $members));
            $ins = $db->prepare("INSERT IGNORE INTO group_chat_members (group_id, user_id) VALUES (?, ?)");
            foreach ($allMembers as $uid) { $ins->execute([$groupId, $uid]); }

            echo json_encode(['success' => true, 'group_id' => $groupId, 'message' => 'Group created']);
            exit;
        }

        // Send message
        if ($action === 'send') {
            $groupId = (int)($data['group_id'] ?? 0);
            $content = trim($data['content'] ?? '');

            if (!$groupId || empty($content)) {
                echo json_encode(['success' => false, 'message' => 'group_id and content required']);
                exit;
            }

            // Verify membership
            $chk = $db->prepare("SELECT id FROM group_chat_members WHERE group_id=:g AND user_id=:u");
            $chk->execute([':g' => $groupId, ':u' => $myId]);
            if (!$chk->fetch()) {
                http_response_code(403);
                echo json_encode(['success' => false, 'message' => 'Not a member']);
                exit;
            }

            // is_read_by starts with sender marked as read
            $isReadBy = '|' . $myId . '|';
            $db->prepare(
                "INSERT INTO group_messages (group_id, sender_id, content, is_read_by)
                 VALUES (?, ?, ?, ?)"
            )->execute([$groupId, $myId, $content, $isReadBy]);

            $newId = $db->lastInsertId();
            $sel   = $db->prepare(
                "SELECT gm.*, u.username AS sender_username, u.avatar AS sender_avatar, u.role AS sender_role
                 FROM group_messages gm JOIN users u ON gm.sender_id = u.id WHERE gm.id = ?"
            );
            $sel->execute([$newId]);
            $msg = $sel->fetch(PDO::FETCH_ASSOC);
            $msg['sender_avatar'] = fixAvatar($msg['sender_avatar']);
            echo json_encode(['success' => true, 'message' => $msg]);
            exit;
        }

        // Add member (creator only)
        if ($action === 'add_member') {
            $groupId  = (int)($data['group_id'] ?? 0);
            $targetId = (int)($data['user_id']  ?? 0);

            $grp = $db->prepare("SELECT created_by FROM group_chats WHERE id = ?");
            $grp->execute([$groupId]);
            $g = $grp->fetch(PDO::FETCH_ASSOC);
            if (!$g || $g['created_by'] != $myId) {
                http_response_code(403);
                echo json_encode(['success' => false, 'message' => 'Only the group creator can add members']);
                exit;
            }

            // Must be a connection
            $connChk = $db->prepare(
                "SELECT id FROM connections
                 WHERE ((requester_id=:me AND addressee_id=:u) OR (requester_id=:u2 AND addressee_id=:me2))
                   AND status='accepted'"
            );
            $connChk->execute([':me' => $myId, ':u' => $targetId, ':u2' => $targetId, ':me2' => $myId]);
            if (!$connChk->fetch()) {
                echo json_encode(['success' => false, 'message' => 'You can only add connections']);
                exit;
            }

            $db->prepare("INSERT IGNORE INTO group_chat_members (group_id, user_id) VALUES (?, ?)")
               ->execute([$groupId, $targetId]);
            echo json_encode(['success' => true, 'message' => 'Member added']);
            exit;
        }

        // Leave group
        if ($action === 'leave') {
            $groupId = (int)($data['group_id'] ?? 0);
            $db->prepare("DELETE FROM group_chat_members WHERE group_id=? AND user_id=?")
               ->execute([$groupId, $myId]);
            echo json_encode(['success' => true, 'message' => 'Left group']);
            exit;
        }

        echo json_encode(['success' => false, 'message' => 'Invalid action']);
        break;

    case 'DELETE':
        $data  = json_decode(file_get_contents('php://input'), true);
        $msgId = (int)($data['message_id'] ?? 0);

        $chk = $db->prepare("SELECT sender_id FROM group_messages WHERE id=? AND is_deleted=0");
        $chk->execute([$msgId]);
        $msg = $chk->fetch(PDO::FETCH_ASSOC);
        if (!$msg || $msg['sender_id'] != $myId) {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Permission denied']);
            exit;
        }
        $db->prepare("UPDATE group_messages SET is_deleted=1, content='[Message deleted]' WHERE id=?")
           ->execute([$msgId]);
        echo json_encode(['success' => true]);
        break;

    default:
        http_response_code(405);
        echo json_encode(['success' => false, 'message' => 'Method not allowed']);
}
?>
