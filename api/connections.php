<?php
/**
 * api/connections.php
 *
 * GET  ?list=1          → my connections (accepted)
 * GET  ?requests=1      → incoming pending requests
 * GET  ?status=userId   → connection status with a specific user
 * GET  ?people=1        → all users I'm not yet connected with (discover)
 * POST  action=request  → send connection request
 * POST  action=accept   → accept a request
 * POST  action=decline  → decline a request
 * DELETE                → remove a connection
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

// ── Helper: fix avatar path ───────────────────────────────────────────────────
function fixAvatar($avatar) {
    if (empty($avatar) || $avatar === 'default-avatar.png') return 'assets/images/default-avatar.png';
    return $avatar;
}

switch ($method) {

    case 'GET':

        // My accepted connections
        if (isset($_GET['list'])) {
            $stmt = $db->prepare(
                "SELECT u.id, u.username, u.role, u.avatar, u.bio, u.last_active
                 FROM connections c
                 JOIN users u ON u.id = IF(c.requester_id = :me, c.addressee_id, c.requester_id)
                 WHERE (c.requester_id = :me2 OR c.addressee_id = :me3)
                   AND c.status = 'accepted'
                   AND u.status != 'banned'
                 ORDER BY u.username ASC"
            );
            $stmt->execute([':me' => $myId, ':me2' => $myId, ':me3' => $myId]);
            $conns = $stmt->fetchAll(PDO::FETCH_ASSOC);
            foreach ($conns as &$c) { $c['avatar'] = fixAvatar($c['avatar']); }
            echo json_encode(['success' => true, 'connections' => $conns]);
            exit;
        }

        // Incoming pending requests
        if (isset($_GET['requests'])) {
            $stmt = $db->prepare(
                "SELECT c.id AS request_id, u.id, u.username, u.role, u.avatar, c.created_at
                 FROM connections c
                 JOIN users u ON u.id = c.requester_id
                 WHERE c.addressee_id = :me AND c.status = 'pending'
                 ORDER BY c.created_at DESC"
            );
            $stmt->execute([':me' => $myId]);
            $reqs = $stmt->fetchAll(PDO::FETCH_ASSOC);
            foreach ($reqs as &$r) { $r['avatar'] = fixAvatar($r['avatar']); }
            echo json_encode(['success' => true, 'requests' => $reqs]);
            exit;
        }

        // Status with a specific user
        if (isset($_GET['status'])) {
            $otherId = (int)$_GET['status'];
            $stmt = $db->prepare(
                "SELECT status, requester_id FROM connections
                 WHERE (requester_id = :me AND addressee_id = :o)
                    OR (requester_id = :o2 AND addressee_id = :me2)"
            );
            $stmt->execute([':me' => $myId, ':o' => $otherId, ':o2' => $otherId, ':me2' => $myId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row) {
                echo json_encode(['success' => true, 'status' => 'none']);
            } else {
                $label = $row['status'];
                if ($row['status'] === 'pending') {
                    $label = ($row['requester_id'] == $myId) ? 'pending_sent' : 'pending_received';
                }
                echo json_encode(['success' => true, 'status' => $label]);
            }
            exit;
        }

        // Discover — all users not yet connected / pending with me
        if (isset($_GET['people'])) {
            $stmt = $db->prepare(
                "SELECT u.id, u.username, u.role, u.avatar, u.bio
                 FROM users u
                 WHERE u.id != :me
                   AND u.status != 'banned'
                   AND u.id NOT IN (
                       SELECT IF(requester_id = :me2, addressee_id, requester_id)
                       FROM connections
                       WHERE requester_id = :me3 OR addressee_id = :me4
                   )
                 ORDER BY u.username ASC"
            );
            $stmt->execute([':me' => $myId, ':me2' => $myId, ':me3' => $myId, ':me4' => $myId]);
            $people = $stmt->fetchAll(PDO::FETCH_ASSOC);
            foreach ($people as &$p) { $p['avatar'] = fixAvatar($p['avatar']); }
            echo json_encode(['success' => true, 'people' => $people]);
            exit;
        }

        echo json_encode(['success' => false, 'message' => 'Missing parameter']);
        break;

    case 'POST':
        $data   = json_decode(file_get_contents('php://input'), true);
        $action = $data['action'] ?? '';

        if ($action === 'request') {
            $targetId = (int)($data['user_id'] ?? 0);
            if (!$targetId || $targetId === $myId) {
                echo json_encode(['success' => false, 'message' => 'Invalid user']);
                exit;
            }
            // Check no existing connection/request
            $chk = $db->prepare(
                "SELECT id FROM connections
                 WHERE (requester_id = :me AND addressee_id = :t)
                    OR (requester_id = :t2 AND addressee_id = :me2)"
            );
            $chk->execute([':me' => $myId, ':t' => $targetId, ':t2' => $targetId, ':me2' => $myId]);
            if ($chk->fetch()) {
                echo json_encode(['success' => false, 'message' => 'Connection already exists or pending']);
                exit;
            }
            $db->prepare(
                "INSERT INTO connections (requester_id, addressee_id, status) VALUES (:me, :t, 'pending')"
            )->execute([':me' => $myId, ':t' => $targetId]);
            echo json_encode(['success' => true, 'message' => 'Connection request sent']);
            exit;
        }

        if ($action === 'accept') {
            $requestId = (int)($data['request_id'] ?? 0);
            $db->prepare(
                "UPDATE connections SET status = 'accepted', accepted_at = NOW()
                 WHERE id = :id AND addressee_id = :me AND status = 'pending'"
            )->execute([':id' => $requestId, ':me' => $myId]);
            echo json_encode(['success' => true, 'message' => 'Connection accepted']);
            exit;
        }

        if ($action === 'decline') {
            $requestId = (int)($data['request_id'] ?? 0);
            $db->prepare(
                "DELETE FROM connections WHERE id = :id AND addressee_id = :me AND status = 'pending'"
            )->execute([':id' => $requestId, ':me' => $myId]);
            echo json_encode(['success' => true, 'message' => 'Request declined']);
            exit;
        }

        echo json_encode(['success' => false, 'message' => 'Invalid action']);
        break;

    case 'DELETE':
        $data     = json_decode(file_get_contents('php://input'), true);
        $targetId = (int)($data['user_id'] ?? 0);
        $db->prepare(
            "DELETE FROM connections
             WHERE ((requester_id = :me AND addressee_id = :t)
                 OR (requester_id = :t2 AND addressee_id = :me2))
               AND status = 'accepted'"
        )->execute([':me' => $myId, ':t' => $targetId, ':t2' => $targetId, ':me2' => $myId]);
        echo json_encode(['success' => true, 'message' => 'Connection removed']);
        break;

    default:
        http_response_code(405);
        echo json_encode(['success' => false, 'message' => 'Method not allowed']);
}
?>
