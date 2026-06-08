<?php
/**
 * api/community.php
 * GET  — fetch community settings (public to all logged-in users)
 * POST — update community settings (admin only)
 * PATCH — upload community logo or banner (admin only)
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

// Helper — ensure settings row exists
function ensureSettings($db): array {
    $row = $db->query("SELECT * FROM community_settings WHERE id = 1")->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        $db->exec("INSERT INTO community_settings (id, name) VALUES (1, 'HG Community')");
        $row = $db->query("SELECT * FROM community_settings WHERE id = 1")->fetch(PDO::FETCH_ASSOC);
    }
    return $row;
}

switch ($method) {

    case 'GET':
        // Stats — dynamic from DB
        $stats = [];
        $stats['member_count']  = (int)$db->query("SELECT COUNT(*) FROM users WHERE status != 'banned'")->fetchColumn();
        $stats['channel_count'] = (int)$db->query("SELECT COUNT(*) FROM channels")->fetchColumn();
        $stats['team_count']    = (int)$db->query("SELECT COUNT(*) FROM channels WHERE type='team'")->fetchColumn();
        $stats['message_count'] = (int)$db->query("SELECT COUNT(*) FROM messages WHERE is_deleted=0")->fetchColumn();
        $settings = ensureSettings($db);
        echo json_encode(['success' => true, 'settings' => $settings, 'stats' => $stats]);
        break;

    case 'POST':
        if ($_SESSION['role'] !== 'admin') {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Admin only']);
            exit;
        }
        $data = json_decode(file_get_contents('php://input'), true);
        $name        = trim($data['name']        ?? '');
        $description = trim($data['description'] ?? '');
        $tagline     = trim($data['tagline']     ?? '');

        if (empty($name)) {
            echo json_encode(['success' => false, 'message' => 'Community name required']);
            exit;
        }

        ensureSettings($db);
        $db->prepare(
            "UPDATE community_settings SET name=:n, description=:d, tagline=:t WHERE id=1"
        )->execute([':n'=>$name, ':d'=>$description, ':t'=>$tagline]);

        echo json_encode(['success' => true, 'message' => 'Community settings updated']);
        break;

    case 'PATCH':
        if ($_SESSION['role'] !== 'admin') {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Admin only']);
            exit;
        }

        $type = $_GET['type'] ?? ''; // 'logo' or 'banner'
        if (!in_array($type, ['logo', 'banner'])) {
            echo json_encode(['success' => false, 'message' => 'type must be logo or banner']);
            exit;
        }

        $fileKey = $type === 'logo' ? 'logo' : 'banner';
        if (!isset($_FILES[$fileKey]) || $_FILES[$fileKey]['error'] !== 0) {
            echo json_encode(['success' => false, 'message' => 'No file received']);
            exit;
        }

        $finfo    = new finfo(FILEINFO_MIME_TYPE);
        $realMime = $finfo->file($_FILES[$fileKey]['tmp_name']);
        if (!in_array($realMime, ['image/jpeg','image/png','image/gif','image/webp'])) {
            echo json_encode(['success' => false, 'message' => 'Only JPG, PNG, GIF, WEBP allowed']);
            exit;
        }
        if ($_FILES[$fileKey]['size'] > 3 * 1024 * 1024) {
            echo json_encode(['success' => false, 'message' => 'File must be under 3MB']);
            exit;
        }

        $extMap = ['image/jpeg'=>'jpg','image/png'=>'png','image/gif'=>'gif','image/webp'=>'webp'];
        $ext    = $extMap[$realMime];
        $dir    = __DIR__ . '/../uploads/community/';
        if (!file_exists($dir)) mkdir($dir, 0755, true);

        $fileName = $type . '_' . bin2hex(random_bytes(8)) . '.' . $ext;
        if (move_uploaded_file($_FILES[$fileKey]['tmp_name'], $dir . $fileName)) {
            $path = 'uploads/community/' . $fileName;
            ensureSettings($db);
            $col = $type === 'logo' ? 'logo' : 'banner';
            $db->prepare("UPDATE community_settings SET {$col}=:p WHERE id=1")
               ->execute([':p' => $path]);
            echo json_encode(['success' => true, $type => $path]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Failed to save file']);
        }
        break;

    default:
        http_response_code(405);
        echo json_encode(['success' => false, 'message' => 'Method not allowed']);
}
?>
