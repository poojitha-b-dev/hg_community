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

        // ── GET: fetch all channels ───────────────────────────────────────────
        case 'GET':
            $stmt = $db->prepare(
                "SELECT c.*, u.username AS created_by_name
                 FROM channels c
                 LEFT JOIN users u ON c.created_by = u.id
                 ORDER BY c.type, c.name"
            );
            $stmt->execute();
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
                echo json_encode([
                    'success'    => true,
                    'message'    => 'Channel created successfully',
                    'channel_id' => $db->lastInsertId(),
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

        default:
            http_response_code(405);
            echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    }

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Server error: ' . $e->getMessage()]);
}
?>