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
        // Single user profile view
        if (isset($_GET['profile'])) {
            $profileId = (int)$_GET['profile'];
            $stmt = $db->prepare(
                "SELECT id, username, role, avatar, bio, avatar_visibility, created_at
                 FROM users WHERE id = :id AND status != 'banned'"
            );
            $stmt->execute([':id' => $profileId]);
            $u = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$u) { echo json_encode(['success'=>false,'message'=>'User not found']); exit; }
            if (empty($u['avatar']) || $u['avatar'] === 'default-avatar.png')
                $u['avatar'] = 'assets/images/default-avatar.png';
            echo json_encode(['success' => true, 'user' => $u]);
            exit;
        }

        if (isset($_GET['online'])) {
            $query = "SELECT id, username, role, avatar, status
                      FROM users
                      WHERE last_active > DATE_SUB(NOW(), INTERVAL 5 MINUTE)
                        AND status != 'banned'
                      ORDER BY role, username";
        } else {
            // Only admins/mods see email and phone
            $isPrivileged = in_array($_SESSION['role'], ['admin', 'moderator']);
            if ($isPrivileged) {
                $query = "SELECT id, username, email, phone, role, status, avatar, created_at, last_active
                          FROM users ORDER BY role, username";
            } else {
                $query = "SELECT id, username, role, status, avatar, created_at, last_active
                          FROM users WHERE status != 'banned' ORDER BY role, username";
            }
        }

        $stmt = $db->prepare($query);
        $stmt->execute();
        $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($users as &$u) {
            if (empty($u['avatar']) || $u['avatar'] === 'default-avatar.png')
                $u['avatar'] = 'assets/images/default-avatar.png';
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

        $data   = json_decode(file_get_contents('php://input'), true);
        $userId = (int)($data['user_id'] ?? 0);
        $action = $data['action'] ?? '';

        // Role change — admin only
        if ($action === 'change_role') {
            if ($_SESSION['role'] !== 'admin') {
                http_response_code(403);
                echo json_encode(['success' => false, 'message' => 'Only admins can change roles']);
                exit;
            }
            if ($userId === (int)$_SESSION['user_id']) {
                echo json_encode(['success' => false, 'message' => 'You cannot change your own role']);
                exit;
            }
            $newRole = $data['role'] ?? '';
            if (!in_array($newRole, ['admin', 'moderator', 'member'])) {
                echo json_encode(['success' => false, 'message' => 'Invalid role']);
                exit;
            }
            $db->prepare("UPDATE users SET role=:role WHERE id=:id")
               ->execute([':role'=>$newRole, ':id'=>$userId]);
            echo json_encode(['success' => true, 'message' => 'Role updated to ' . $newRole]);
            exit;
        }

        // Anti-escalation — moderators cannot act on admins or other moderators
        if (!$auth->canModerate($userId)) {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'You cannot moderate this user.']);
            exit;
        }

        $allowed = ['ban','unban','mute','unmute','restrict','unrestrict'];
        if (!in_array($action, $allowed)) {
            echo json_encode(['success' => false, 'message' => 'Invalid action']);
            exit;
        }

        $statusMap = [
            'ban'=>'banned','unban'=>'active','mute'=>'muted',
            'unmute'=>'active','restrict'=>'restricted','unrestrict'=>'active'
        ];
        $newStatus = $statusMap[$action];
        $db->prepare("UPDATE users SET status=:status WHERE id=:id")
           ->execute([':status'=>$newStatus, ':id'=>$userId]);
        echo json_encode(['success'=>true, 'message'=>ucfirst($action).' successful']);
        break;


    case 'PATCH':
        if (!isset($_FILES['avatar']) || $_FILES['avatar']['error'] !== 0) {
            echo json_encode(['success' => false, 'message' => 'No avatar file received']);
            exit;
        }
        // Validate by actual file content
        $finfo    = new finfo(FILEINFO_MIME_TYPE);
        $realMime = $finfo->file($_FILES['avatar']['tmp_name']);
        $allowed  = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
        if (!in_array($realMime, $allowed)) {
            echo json_encode(['success' => false, 'message' => 'Only JPG, PNG, GIF, WEBP allowed']);
            exit;
        }
        if ($_FILES['avatar']['size'] > 2 * 1024 * 1024) {
            echo json_encode(['success' => false, 'message' => 'Avatar must be under 2MB']);
            exit;
        }
        // Map real MIME to extension — never trust original filename
        $extMap   = ['image/jpeg'=>'jpg','image/png'=>'png','image/gif'=>'gif','image/webp'=>'webp'];
        $ext      = $extMap[$realMime];
        $fileName = 'avatar_' . $_SESSION['user_id'] . '_' . bin2hex(random_bytes(8)) . '.' . $ext;
        $uploadDir = __DIR__ . '/../uploads/avatars/';
        if (!file_exists($uploadDir)) mkdir($uploadDir, 0755, true);
        if (move_uploaded_file($_FILES['avatar']['tmp_name'], $uploadDir . $fileName)) {
            $avatarPath = 'uploads/avatars/' . $fileName;
            $db->prepare('UPDATE users SET avatar=:avatar WHERE id=:id')
               ->execute([':avatar'=>$avatarPath, ':id'=>$_SESSION['user_id']]);
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
                // Check unique
                $chk = $db->prepare("SELECT id FROM users WHERE username = :u AND id != :id");
                $chk->execute([':u' => $data['username'], ':id' => $_SESSION['user_id']]);
                if ($chk->rowCount() > 0) {
                    echo json_encode(['success' => false, 'message' => 'Username already taken']);
                    exit;
                }
                $fields[] = 'username = :username';
                $params[':username'] = $data['username'];
            }
            if (!empty($data['email'])) {
                $chk = $db->prepare("SELECT id FROM users WHERE email = :e AND id != :id");
                $chk->execute([':e' => $data['email'], ':id' => $_SESSION['user_id']]);
                if ($chk->rowCount() > 0) {
                    echo json_encode(['success' => false, 'message' => 'Email already in use']);
                    exit;
                }
                $fields[] = 'email = :email';
                $params[':email'] = $data['email'];
            }
            if (!empty($data['phone'])) {
                $fields[] = 'phone = :phone';
                $params[':phone'] = $data['phone'];
            }
            // Avatar visibility
            $validVisibility = ['everyone', 'connections', 'nobody'];
            if (!empty($data['avatar_visibility']) && in_array($data['avatar_visibility'], $validVisibility)) {
                $fields[] = 'avatar_visibility = :avatar_visibility';
                $params[':avatar_visibility'] = $data['avatar_visibility'];
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