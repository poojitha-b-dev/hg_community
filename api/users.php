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
$db = $database->getConnection();

$method = $_SERVER['REQUEST_METHOD'];

switch ($method) {
    case 'GET':
        if (isset($_GET['online'])) {
            // Get online users (active in last 5 minutes)
            $query = "SELECT id, username, role, avatar, status 
                     FROM users 
                     WHERE last_active > DATE_SUB(NOW(), INTERVAL 5 MINUTE) 
                     AND status != 'banned' 
                     ORDER BY role, username";
        } else {
            // Get all users
            $query = "SELECT id, username, email, phone, role, status, avatar, created_at, last_active 
                     FROM users 
                     ORDER BY role, username";
        }
        
        $stmt = $db->prepare($query);
        $stmt->execute();
        $users = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Fix avatar paths — DB stores bare 'default-avatar.png' for old rows
        foreach ($users as &$u) {
            if (empty($u['avatar']) || $u['avatar'] === 'default-avatar.png') {
                $u['avatar'] = 'assets/images/default-avatar.png';
            }
        }
        unset($u);

        echo json_encode(['success' => true, 'users' => $users]);
        break;
        
    case 'PUT':
        if (!$auth->hasPermission('moderate_users')) {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Permission denied']);
            exit;
        }
        
        $data = json_decode(file_get_contents('php://input'), true);
        $userId = $data['user_id'];
        $action = $data['action'];
        
        $allowedActions = ['ban', 'unban', 'mute', 'unmute', 'restrict', 'unrestrict'];
        if (!in_array($action, $allowedActions)) {
            echo json_encode(['success' => false, 'message' => 'Invalid action']);
            exit;
        }
        
        $statusMap = [
            'ban' => 'banned',
            'unban' => 'active',
            'mute' => 'muted',
            'unmute' => 'active',
            'restrict' => 'restricted',
            'unrestrict' => 'active'
        ];
        
        $newStatus = $statusMap[$action];
        
        $updateQuery = "UPDATE users SET status = :status WHERE id = :id";
        $updateStmt = $db->prepare($updateQuery);
        $updateStmt->bindParam(':status', $newStatus);
        $updateStmt->bindParam(':id', $userId);
        
        if ($updateStmt->execute()) {
            echo json_encode(['success' => true, 'message' => ucfirst($action) . ' successful']);
        } else {
            echo json_encode(['success' => false, 'message' => 'Failed to ' . $action . ' user']);
        }
        break;


    case 'PATCH':
        // Avatar upload — sent as multipart form
        if (!isset($_FILES['avatar']) || $_FILES['avatar']['error'] !== 0) {
            echo json_encode(['success' => false, 'message' => 'No avatar file received']);
            exit;
        }
        $allowed = ['image/jpeg','image/png','image/gif','image/webp'];
        if (!in_array($_FILES['avatar']['type'], $allowed)) {
            echo json_encode(['success' => false, 'message' => 'Only JPG, PNG, GIF, WEBP allowed']);
            exit;
        }
        if ($_FILES['avatar']['size'] > 2 * 1024 * 1024) {
            echo json_encode(['success' => false, 'message' => 'Avatar must be under 2MB']);
            exit;
        }
        $ext      = pathinfo($_FILES['avatar']['name'], PATHINFO_EXTENSION);
        $fileName = 'avatar_' . $_SESSION['user_id'] . '_' . time() . '.' . $ext;
        $uploadDir = __DIR__ . '/../uploads/avatars/';
        if (!file_exists($uploadDir)) mkdir($uploadDir, 0777, true);
        if (move_uploaded_file($_FILES['avatar']['tmp_name'], $uploadDir . $fileName)) {
            $avatarPath = 'uploads/avatars/' . $fileName;
            $upd = $db->prepare('UPDATE users SET avatar = :avatar WHERE id = :id');
            $upd->execute([':avatar' => $avatarPath, ':id' => $_SESSION['user_id']]);
            echo json_encode(['success' => true, 'avatar' => $avatarPath]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Failed to save avatar']);
        }
        break;

    case 'POST':
        // Update own profile (username, email, phone, password, avatar)
        $data = json_decode(file_get_contents('php://input'), true);
        $action = $data['action'] ?? 'update_profile';

        if ($action === 'update_profile') {
            $fields = [];
            $params = [':id' => $_SESSION['user_id']];

            if (!empty($data['username'])) {
                $newUsername = trim($data['username']);
                // Enforce format: a-z, 0-9, dot, underscore, 3-30 chars
                if (!preg_match('/^[a-z0-9._]{3,30}$/', $newUsername)) {
                    echo json_encode(['success' => false, 'message' => 'Username must be 3–30 characters and contain only a-z, 0-9, . or _']);
                    exit;
                }
                // Check uniqueness
                $chk = $db->prepare("SELECT id FROM users WHERE username = :u AND id != :id");
                $chk->execute([':u' => $newUsername, ':id' => $_SESSION['user_id']]);
                if ($chk->rowCount() > 0) {
                    echo json_encode(['success' => false, 'message' => 'Username already taken']);
                    exit;
                }
                $fields[] = 'username = :username';
                $params[':username'] = $newUsername;
            }

            // Email is locked — cannot be changed after account creation
            if (!empty($data['email'])) {
                echo json_encode(['success' => false, 'message' => 'Email cannot be changed after registration.']);
                exit;
            }
            if (!empty($data['phone'])) {
                $fields[] = 'phone = :phone';
                $params[':phone'] = $data['phone'];
            }
            // Bio may be intentionally emptied — use isset rather than !empty
            if (isset($data['bio'])) {
                $fields[] = 'bio = :bio';
                $params[':bio'] = trim($data['bio']);
            }
            if (!empty($data['new_password'])) {
                if (empty($data['current_password'])) {
                    echo json_encode(['success' => false, 'message' => 'Current password required']);
                    exit;
                }
                $userRow = $db->prepare("SELECT password FROM users WHERE id = :id");
                $userRow->execute([':id' => $_SESSION['user_id']]);
                $row = $userRow->fetch(PDO::FETCH_ASSOC);
                if (!password_verify($data['current_password'], $row['password'])) {
                    echo json_encode(['success' => false, 'message' => 'Current password is incorrect']);
                    exit;
                }
                $fields[] = 'password = :password';
                $params[':password'] = password_hash($data['new_password'], PASSWORD_DEFAULT);
            }

            if (empty($fields)) {
                echo json_encode(['success' => false, 'message' => 'Nothing to update']);
                exit;
            }

            $updateQuery = 'UPDATE users SET ' . implode(', ', $fields) . ' WHERE id = :id';
            $updateStmt  = $db->prepare($updateQuery);
            if ($updateStmt->execute($params)) {
                // Update session username if changed
                if (!empty($data['username'])) $_SESSION['username'] = $data['username'];
                echo json_encode(['success' => true, 'message' => 'Profile updated successfully']);
            } else {
                echo json_encode(['success' => false, 'message' => 'Failed to update profile']);
            }
        }
        break;
}
?>