<?php
ob_start();

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../config/database.php';

ob_clean();

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

try {
    $auth = new Auth();
    if (!$auth->isLoggedIn()) {
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Unauthorized']);
        exit;
    }

    $database = new Database();
    $db       = $database->getConnection();
    if (!$db) throw new Exception("Database connection failed");

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'DB error: ' . $e->getMessage()]);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'];

try {
    switch ($method) {

        // ── GET: fetch channels ───────────────────────────────────────────────
        case 'GET':
            $userId = (int)$_SESSION['user_id'];
            $role   = $_SESSION['role'];

            // Admins and moderators see all channels
            // Members only see: non-team channels + team channels they belong to
            if ($role === 'admin' || $role === 'moderator') {
                $stmt = $db->prepare(
                    "SELECT c.*, u.username AS created_by_name
                     FROM channels c
                     LEFT JOIN users u ON c.created_by = u.id
                     ORDER BY c.type, c.name"
                );
                $stmt->execute();
            } else {
                $stmt = $db->prepare(
                    "SELECT c.*, u.username AS created_by_name
                     FROM channels c
                     LEFT JOIN users u ON c.created_by = u.id
                     WHERE c.type != 'team'
                        OR c.id IN (
                            SELECT channel_id FROM team_members
                            WHERE user_id = :uid
                        )
                     ORDER BY c.type, c.name"
                );
                $stmt->execute([':uid' => $userId]);
            }
            echo json_encode(['success' => true, 'channels' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
            break;

        // ── POST: create channel ──────────────────────────────────────────────
        case 'POST':
            if (!$auth->hasPermission('manage_channels')) {
                http_response_code(403);
                echo json_encode(['success' => false, 'message' => 'Permission denied']);
                exit;
            }

            $input = file_get_contents('php://input');
            if (empty($input)) {
                echo json_encode(['success' => false, 'message' => 'No data received']);
                exit;
            }

            $data = json_decode($input, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                echo json_encode(['success' => false, 'message' => 'Invalid JSON: ' . json_last_error_msg()]);
                exit;
            }

            $name        = trim($data['name']        ?? '');
            $description = trim($data['description'] ?? '');
            $type        = trim($data['type']        ?? '');
            $teamName    = trim($data['team_name']   ?? '') ?: null;

            if (empty($name)) { echo json_encode(['success' => false, 'message' => 'Channel name is required']); exit; }
            if (empty($type)) { echo json_encode(['success' => false, 'message' => 'Channel type is required']); exit; }

            $validTypes = ['announcement', 'general', 'team', 'technical'];
            if (!in_array($type, $validTypes)) {
                echo json_encode(['success' => false, 'message' => 'Invalid channel type']);
                exit;
            }

            $check = $db->prepare("SELECT COUNT(*) FROM channels WHERE name = ?");
            $check->execute([$name]);
            if ($check->fetchColumn() > 0) {
                echo json_encode(['success' => false, 'message' => 'Channel name already exists']);
                exit;
            }

            $ins = $db->prepare(
                "INSERT INTO channels (name, description, type, team_name, created_by)
                 VALUES (?, ?, ?, ?, ?)"
            );
            if ($ins->execute([$name, $description, $type, $teamName, $_SESSION['user_id']])) {
                $newChannelId = $db->lastInsertId();
                // Auto-add creator to team membership
                if ($type === 'team') {
                    $db->prepare(
                        "INSERT IGNORE INTO team_members (channel_id, user_id, added_by)
                         VALUES (?, ?, ?)"
                    )->execute([$newChannelId, $_SESSION['user_id'], $_SESSION['user_id']]);
                }
                echo json_encode([
                    'success'    => true,
                    'message'    => 'Channel created successfully',
                    'channel_id' => $newChannelId,
                ]);
            } else {
                $err = $ins->errorInfo();
                echo json_encode(['success' => false, 'message' => 'DB error: ' . ($err[2] ?? 'unknown')]);
            }
            break;

        // ── PUT: rename or update channel description ─────────────────────────
        case 'PUT':
            if (!$auth->hasPermission('manage_channels')) {
                http_response_code(403);
                echo json_encode(['success' => false, 'message' => 'Permission denied']);
                exit;
            }

            $data      = json_decode(file_get_contents('php://input'), true);
            $channelId = (int)($data['channel_id'] ?? 0);

            if (!$channelId) {
                echo json_encode(['success' => false, 'message' => 'channel_id required']);
                exit;
            }

            // Fetch existing channel
            $existing = $db->prepare("SELECT * FROM channels WHERE id = ?");
            $existing->execute([$channelId]);
            $channel = $existing->fetch(PDO::FETCH_ASSOC);

            if (!$channel) {
                echo json_encode(['success' => false, 'message' => 'Channel not found']);
                exit;
            }

            $newName = trim($data['name'] ?? $channel['name']);
            $newDesc = trim($data['description'] ?? $channel['description']);
            $newType = trim($data['type'] ?? $channel['type']);
            $newTeam = trim($data['team_name'] ?? $channel['team_name'] ?? '') ?: null;

            if (empty($newName)) {
                echo json_encode(['success' => false, 'message' => 'Channel name cannot be empty']);
                exit;
            }

            // Check name uniqueness (allow keeping same name)
            if ($newName !== $channel['name']) {
                $nameCheck = $db->prepare("SELECT COUNT(*) FROM channels WHERE name = ? AND id != ?");
                $nameCheck->execute([$newName, $channelId]);
                if ($nameCheck->fetchColumn() > 0) {
                    echo json_encode(['success' => false, 'message' => 'Channel name already exists']);
                    exit;
                }
            }

            $upd = $db->prepare(
                "UPDATE channels SET name = ?, description = ?, type = ?, team_name = ? WHERE id = ?"
            );
            if ($upd->execute([$newName, $newDesc, $newType, $newTeam, $channelId])) {
                // Return updated channel
                $sel = $db->prepare(
                    "SELECT c.*, u.username AS created_by_name
                     FROM channels c LEFT JOIN users u ON c.created_by = u.id
                     WHERE c.id = ?"
                );
                $sel->execute([$channelId]);
                echo json_encode([
                    'success' => true,
                    'message' => 'Channel updated',
                    'channel' => $sel->fetch(PDO::FETCH_ASSOC),
                ]);
            } else {
                echo json_encode(['success' => false, 'message' => 'Failed to update channel']);
            }
            break;

        // ── DELETE: remove a channel ──────────────────────────────────────────
        case 'DELETE':
            if (!$auth->hasPermission('manage_channels')) {
                http_response_code(403);
                echo json_encode(['success' => false, 'message' => 'Permission denied']);
                exit;
            }

            $data      = json_decode(file_get_contents('php://input'), true);
            $channelId = (int)($data['channel_id'] ?? 0);

            if (!$channelId) {
                echo json_encode(['success' => false, 'message' => 'channel_id required']);
                exit;
            }

            $check = $db->prepare("SELECT id FROM channels WHERE id = ?");
            $check->execute([$channelId]);
            if (!$check->fetch()) {
                echo json_encode(['success' => false, 'message' => 'Channel not found']);
                exit;
            }

            // Messages are CASCADE deleted via FK; typing_indicators and unread_counts too.
            $del = $db->prepare("DELETE FROM channels WHERE id = ?");
            if ($del->execute([$channelId])) {
                echo json_encode(['success' => true, 'message' => 'Channel deleted']);
            } else {
                echo json_encode(['success' => false, 'message' => 'Failed to delete channel']);
            }
            break;

        // ── PATCH: manage team members ────────────────────────────────────────
        case 'PATCH':
            if (!$auth->hasPermission('manage_channels')) {
                http_response_code(403);
                echo json_encode(['success' => false, 'message' => 'Permission denied']);
                exit;
            }

            $data      = json_decode(file_get_contents('php://input'), true);
            $action    = $data['action']     ?? '';
            $channelId = (int)($data['channel_id'] ?? 0);
            $targetId  = (int)($data['user_id']    ?? 0);

            if (!$channelId || !$targetId) {
                echo json_encode(['success' => false, 'message' => 'channel_id and user_id required']);
                exit;
            }

            // Verify it's a team channel
            $ch = $db->prepare("SELECT type FROM channels WHERE id = ?");
            $ch->execute([$channelId]);
            $chRow = $ch->fetch(PDO::FETCH_ASSOC);
            if (!$chRow || $chRow['type'] !== 'team') {
                echo json_encode(['success' => false, 'message' => 'Not a team channel']);
                exit;
            }

            if ($action === 'add_member') {
                $db->prepare(
                    "INSERT IGNORE INTO team_members (channel_id, user_id, added_by)
                     VALUES (?, ?, ?)"
                )->execute([$channelId, $targetId, $_SESSION['user_id']]);
                echo json_encode(['success' => true, 'message' => 'Member added to team']);
            } elseif ($action === 'remove_member') {
                $db->prepare(
                    "DELETE FROM team_members WHERE channel_id = ? AND user_id = ?"
                )->execute([$channelId, $targetId]);
                echo json_encode(['success' => true, 'message' => 'Member removed from team']);
            } elseif ($action === 'list_members') {
                $stmt = $db->prepare(
                    "SELECT u.id, u.username, u.role, u.avatar
                     FROM team_members tm
                     JOIN users u ON tm.user_id = u.id
                     WHERE tm.channel_id = ?
                     ORDER BY u.username"
                );
                $stmt->execute([$channelId]);
                $members = $stmt->fetchAll(PDO::FETCH_ASSOC);
                foreach ($members as &$m) {
                    if (empty($m['avatar']) || $m['avatar'] === 'default-avatar.png')
                        $m['avatar'] = 'assets/images/default-avatar.png';
                }
                echo json_encode(['success' => true, 'members' => $members]);
            } else {
                echo json_encode(['success' => false, 'message' => 'Invalid action']);
            }
            break;

        default:
            http_response_code(405);
            echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    }

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Server error: ' . $e->getMessage()]);
}
?>