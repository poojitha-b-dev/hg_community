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
        $data = json_decode(file_get_contents('php://input'), true);
        $email = $data['email'] ?? null;
        $phone = $data['phone'] ?? null;
        $role = $data['role'] ?? 'member';
        $expiryHours = $data['expiry_hours'] ?? 24;
        
        $inviteCode = bin2hex(random_bytes(16));
        $expiresAt = date('Y-m-d H:i:s', strtotime("+{$expiryHours} hours"));
        
        $insertQuery = "INSERT INTO invites (invite_code, created_by, email, phone, role, expires_at) 
                        VALUES (:code, :created_by, :email, :phone, :role, :expires_at)";
        $insertStmt = $db->prepare($insertQuery);
        $insertStmt->bindParam(':code', $inviteCode);
        $insertStmt->bindParam(':created_by', $_SESSION['user_id']);
        $insertStmt->bindParam(':email', $email);
        $insertStmt->bindParam(':phone', $phone);
        $insertStmt->bindParam(':role', $role);
        $insertStmt->bindParam(':expires_at', $expiresAt);
        
        if ($insertStmt->execute()) {
            // Build dynamic URL instead of hardcoding
            $host = $_SERVER['HTTP_HOST']; // localhost, 127.0.0.1, or domain
            $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http";
            $inviteUrl = $protocol . "://" . $host . "/hg_community/register.php?invite=" . $inviteCode;


            echo json_encode([
                'success' => true, 
                'invite_code' => $inviteCode,
                'invite_url' => $inviteUrl,
                'expires_at' => $expiresAt
            ]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Failed to create invite']);
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
