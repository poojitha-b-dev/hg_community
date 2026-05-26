<?php
session_start();
require_once __DIR__ . '/../config/database.php';

class Auth {
    private $db;

    public function __construct() {
        $database  = new Database();
        $this->db  = $database->getConnection();
    }

    public function login($username, $password) {
        $query = "SELECT id, username, email, password, role, status
                  FROM users
                  WHERE username = :username OR email = :username";
        $stmt  = $this->db->prepare($query);
        $stmt->bindParam(':username', $username);
        $stmt->execute();

        if ($stmt->rowCount() == 1) {
            $row = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($row['status'] == 'banned') {
                return ['success' => false, 'message' => 'Your account has been banned.'];
            }

            if (password_verify($password, $row['password'])) {
                $_SESSION['user_id']  = $row['id'];
                $_SESSION['username'] = $row['username'];
                $_SESSION['email']    = $row['email'];
                $_SESSION['role']     = $row['role'];

                $upd = $this->db->prepare("UPDATE users SET last_active = NOW() WHERE id = :id");
                $upd->bindParam(':id', $row['id']);
                $upd->execute();

                return ['success' => true, 'user' => $row];
            }
        }

        return ['success' => false, 'message' => 'Invalid credentials.'];
    }

    /**
     * Register a new user.
     * FIX: invite code is now MANDATORY. Registrations without a valid
     * invite code are rejected instead of silently falling back to 'member'.
     */
    public function register($username, $email, $phone, $password, $inviteCode = null) {

        // ── Invite code is required ───────────────────────────────────────────
        if (empty(trim((string)$inviteCode))) {
            return ['success' => false, 'message' => 'An invite code is required to register.'];
        }

        // ── Validate invite ───────────────────────────────────────────────────
        $inviteQuery = "SELECT id, role FROM invites
                        WHERE invite_code = :code
                          AND expires_at > NOW()
                          AND used_at IS NULL";
        $inviteStmt  = $this->db->prepare($inviteQuery);
        $inviteStmt->bindParam(':code', $inviteCode);
        $inviteStmt->execute();

        if ($inviteStmt->rowCount() == 0) {
            return ['success' => false, 'message' => 'Invalid or expired invite code.'];
        }

        $invite = $inviteStmt->fetch(PDO::FETCH_ASSOC);
        $role   = $invite['role'];

        // ── Check username / email uniqueness ─────────────────────────────────
        $checkStmt = $this->db->prepare(
            "SELECT id FROM users WHERE username = :username OR email = :email"
        );
        $checkStmt->bindParam(':username', $username);
        $checkStmt->bindParam(':email',    $email);
        $checkStmt->execute();

        if ($checkStmt->rowCount() > 0) {
            return ['success' => false, 'message' => 'Username or email already exists.'];
        }

        // ── Create user ───────────────────────────────────────────────────────
        $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
        $insertStmt     = $this->db->prepare(
            "INSERT INTO users (username, email, phone, password, role)
             VALUES (:username, :email, :phone, :password, :role)"
        );
        $insertStmt->bindParam(':username', $username);
        $insertStmt->bindParam(':email',    $email);
        $insertStmt->bindParam(':phone',    $phone);
        $insertStmt->bindParam(':password', $hashedPassword);
        $insertStmt->bindParam(':role',     $role);

        if ($insertStmt->execute()) {
            $userId = $this->db->lastInsertId();

            // Mark invite as used
            $useInvite = $this->db->prepare(
                "UPDATE invites SET used_at = NOW(), used_by = :uid WHERE invite_code = :code"
            );
            $useInvite->bindParam(':uid',  $userId);
            $useInvite->bindParam(':code', $inviteCode);
            $useInvite->execute();

            return ['success' => true, 'message' => 'Account created successfully.'];
        }

        return ['success' => false, 'message' => 'Registration failed. Please try again.'];
    }

    public function isLoggedIn() {
        return isset($_SESSION['user_id']);
    }

    public function logout() {
        session_destroy();
        return true;
    }

    public function getCurrentUser() {
        if (!$this->isLoggedIn()) return null;

        $stmt = $this->db->prepare(
            "SELECT id, username, email, phone, role, status, avatar, bio, created_at, last_active
             FROM users WHERE id = :id"
        );
        $stmt->bindParam(':id', $_SESSION['user_id']);
        $stmt->execute();
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    public function hasPermission($permission, $channelId = null) {
        if (!$this->isLoggedIn()) return false;

        $role = $_SESSION['role'];

        if ($role == 'admin') return true;

        switch ($permission) {
            case 'create_announcement': return false;
            case 'moderate_users':      return $role === 'moderator';
            case 'manage_channels':     return false;
            case 'send_message':        return in_array($role, ['moderator', 'member']);
            default:                    return false;
        }
    }
}
?>