<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../config/database.php';

header('Content-Type: application/json');

$auth = new Auth();
if (!$auth->isLoggedIn() || !$auth->hasPermission('moderate_users')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Permission denied']);
    exit;
}

$database = new Database();
$db = $database->getConnection();

$method = $_SERVER['REQUEST_METHOD'];

switch ($method) {
    case 'POST':
        $data        = json_decode(file_get_contents('php://input'), true);
        $inviteType  = in_array($data['invite_type'] ?? '', ['single','group']) ? $data['invite_type'] : 'single';
        $email       = $data['email'] ?? null;
        $role        = in_array($data['role'] ?? '', ['member','moderator']) ? $data['role'] : 'member';
        $expiryHours = max(1, min(168, (int)($data['expiry_hours'] ?? 24)));

        // Single invites require an email
        if ($inviteType === 'single' && empty($email)) {
            echo json_encode(['success' => false, 'message' => 'Email is required for single-person invites.']);
            exit;
        }

        $inviteCode = bin2hex(random_bytes(16));
        // Hard expiry — calculated exactly, never extended
        $expiresAt  = date('Y-m-d H:i:s', time() + ($expiryHours * 3600));

        $ins = $db->prepare(
            "INSERT INTO invites (invite_code, created_by, email, role, invite_type, expires_at)
             VALUES (:code, :created_by, :email, :role, :type, :expires_at)"
        );
        $ins->execute([
            ':code'       => $inviteCode,
            ':created_by' => $_SESSION['user_id'],
            ':email'      => $email,
            ':role'       => $role,
            ':type'       => $inviteType,
            ':expires_at' => $expiresAt,
        ]);

        // Build URL — works on Railway (https) and localhost (subfolder)
        $protocol  = (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https')
                     || (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
                     ? 'https' : 'http';
        $host      = $_SERVER['HTTP_HOST'];
        $scriptDir = rtrim(dirname(dirname($_SERVER['SCRIPT_NAME'])), '/');
        $inviteUrl = $protocol . '://' . $host . $scriptDir . '/register.php?invite=' . $inviteCode;

        echo json_encode([
            'success'     => true,
            'invite_code' => $inviteCode,
            'invite_url'  => $inviteUrl,
            'invite_type' => $inviteType,
            'expires_at'  => $expiresAt,
        ]);
        break;
        
    case 'DELETE':
        $data = json_decode(file_get_contents('php://input'), true);
        $code = $data['invite_code'] ?? '';
        if (!$code) {
            echo json_encode(['success' => false, 'message' => 'invite_code required']);
            exit;
        }
        // Only allow revoking unused, non-expired invites
        $del = $db->prepare("DELETE FROM invites WHERE invite_code = :code AND used_at IS NULL");
        $del->execute([':code' => $code]);
        if ($del->rowCount() > 0) {
            echo json_encode(['success' => true, 'message' => 'Invite revoked']);
        } else {
            echo json_encode(['success' => false, 'message' => 'Invite not found or already used']);
        }
        break;

    case 'GET':
        $query = "SELECT i.*, u.username as created_by_name, u2.username as used_by_name 
                 FROM invites i 
                 LEFT JOIN users u ON i.created_by = u.id 
                 LEFT JOIN users u2 ON i.used_by = u2.id 
                 ORDER BY i.created_at DESC";
        $stmt = $db->prepare($query);
        $stmt->execute();
        $invites = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo json_encode(['success' => true, 'invites' => $invites]);
        break;
}
?>
