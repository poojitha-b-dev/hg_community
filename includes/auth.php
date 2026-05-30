<?php
session_start();
require_once __DIR__ . '/../config/database.php';

class Auth {
    private $db;

    // ── Rate limiting constants ───────────────────────────────────────────────
    const MAX_ATTEMPTS    = 5;    // max failed logins before lockout
    const LOCKOUT_SECONDS = 900;  // 15 minutes

    public function __construct() {
        $database = new Database();
        $this->db = $database->getConnection();
    }

    // ── CSRF token helpers ────────────────────────────────────────────────────
    public static function generateCsrfToken(): string {
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf_token'];
    }

    public static function verifyCsrfToken(string $token): bool {
        return isset($_SESSION['csrf_token'])
            && hash_equals($_SESSION['csrf_token'], $token);
    }

    // ── Rate limiting helpers ─────────────────────────────────────────────────
    private function getRateLimitKey(string $identifier): string {
        // Key per IP + username/email combo stored in session-adjacent PHP array
        // We store attempt data in the DB so it survives across sessions/servers
        return 'login_' . md5($identifier . $_SERVER['REMOTE_ADDR']);
    }

    private function checkRateLimit(string $identifier): array {
        $key  = $this->getRateLimitKey($identifier);
        $stmt = $this->db->prepare(
            "SELECT attempts, locked_until FROM login_attempts
             WHERE attempt_key = :key"
        );
        $stmt->execute([':key' => $key]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) return ['blocked' => false, 'attempts' => 0];

        // Check if still locked
        if ($row['locked_until'] && strtotime($row['locked_until']) > time()) {
            $remaining = ceil((strtotime($row['locked_until']) - time()) / 60);
            return [
                'blocked'   => true,
                'attempts'  => (int)$row['attempts'],
                'remaining' => $remaining,
            ];
        }

        return ['blocked' => false, 'attempts' => (int)$row['attempts']];
    }

    private function recordFailedAttempt(string $identifier): void {
        $key      = $this->getRateLimitKey($identifier);
        $attempts = $this->checkRateLimit($identifier)['attempts'] + 1;
        $lockUntil = $attempts >= self::MAX_ATTEMPTS
            ? date('Y-m-d H:i:s', time() + self::LOCKOUT_SECONDS)
            : null;

        $this->db->prepare(
            "INSERT INTO login_attempts (attempt_key, attempts, locked_until, last_attempt)
             VALUES (:key, :att, :lock, NOW())
             ON DUPLICATE KEY UPDATE
                attempts     = :att2,
                locked_until = :lock2,
                last_attempt = NOW()"
        )->execute([
            ':key'   => $key,
            ':att'   => $attempts, ':att2'  => $attempts,
            ':lock'  => $lockUntil, ':lock2' => $lockUntil,
        ]);
    }

    private function clearAttempts(string $identifier): void {
        $key = $this->getRateLimitKey($identifier);
        $this->db->prepare(
            "DELETE FROM login_attempts WHERE attempt_key = :key"
        )->execute([':key' => $key]);
    }

    // ── Login ─────────────────────────────────────────────────────────────────
    public function login(string $username, string $password): array {

        // 1. Rate limit check
        $limit = $this->checkRateLimit($username);
        if ($limit['blocked']) {
            return [
                'success' => false,
                'message' => "Too many failed attempts. Try again in {$limit['remaining']} minute(s).",
            ];
        }

        // 2. Fetch user
        $stmt = $this->db->prepare(
            "SELECT id, username, email, password, role, status
             FROM users WHERE username = :u OR email = :u"
        );
        $stmt->execute([':u' => $username]);

        if ($stmt->rowCount() === 1) {
            $row = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($row['status'] === 'banned') {
                $this->recordFailedAttempt($username);
                return ['success' => false, 'message' => 'Your account has been banned.'];
            }

            if (password_verify($password, $row['password'])) {
                // Success — clear attempts, regenerate session
                $this->clearAttempts($username);
                session_regenerate_id(true);

                $_SESSION['user_id']  = $row['id'];
                $_SESSION['username'] = $row['username'];
                $_SESSION['email']    = $row['email'];
                $_SESSION['role']     = $row['role'];

                $this->db->prepare("UPDATE users SET last_active = NOW() WHERE id = :id")
                         ->execute([':id' => $row['id']]);

                return ['success' => true, 'user' => $row];
            }
        }

        // Failed — record attempt
        $this->recordFailedAttempt($username);
        $remaining = self::MAX_ATTEMPTS - ($this->checkRateLimit($username)['attempts']);
        $warn = $remaining > 0
            ? " ({$remaining} attempt(s) remaining before lockout)"
            : '';
        return ['success' => false, 'message' => 'Invalid credentials.' . $warn];
    }

    // ── Register ──────────────────────────────────────────────────────────────
    public function register($username, $email, $phone, $password, $inviteCode = null): array {

        if (empty(trim((string)$inviteCode))) {
            return ['success' => false, 'message' => 'An invite code is required to register.'];
        }

        // Validate username format: a-z, 0-9, dot, underscore only
        if (!preg_match('/^[a-z0-9._]{3,30}$/', $username)) {
            return ['success' => false, 'message' => 'Username must be 3–30 characters and contain only a-z, 0-9, . or _'];
        }

        // Validate invite
        $inviteStmt = $this->db->prepare(
            "SELECT id, role, invite_type FROM invites
             WHERE invite_code = :code
               AND expires_at > NOW()
               AND (used_at IS NULL OR invite_type = 'group')"
        );
        $inviteStmt->execute([':code' => $inviteCode]);

        if ($inviteStmt->rowCount() === 0) {
            return ['success' => false, 'message' => 'Invalid or expired invite code.'];
        }

        $invite = $inviteStmt->fetch(PDO::FETCH_ASSOC);
        $role   = $invite['role'];

        // Check uniqueness
        $chk = $this->db->prepare(
            "SELECT id FROM users WHERE username = :username OR email = :email"
        );
        $chk->execute([':username' => $username, ':email' => $email]);
        if ($chk->rowCount() > 0) {
            return ['success' => false, 'message' => 'Username or email already exists.'];
        }

        // Create user
        $hash = password_hash($password, PASSWORD_DEFAULT);
        $ins  = $this->db->prepare(
            "INSERT INTO users (username, email, phone, password, role)
             VALUES (:username, :email, :phone, :password, :role)"
        );
        $ins->execute([
            ':username' => $username,
            ':email'    => $email,
            ':phone'    => $phone ?: null,
            ':password' => $hash,
            ':role'     => $role,
        ]);
        $userId = $this->db->lastInsertId();

        // Mark single-use invites as used; leave group invites open
        if ($invite['invite_type'] === 'single') {
            $this->db->prepare(
                "UPDATE invites SET used_at = NOW(), used_by = :uid WHERE invite_code = :code"
            )->execute([':uid' => $userId, ':code' => $inviteCode]);
        }

        return ['success' => true, 'message' => 'Account created successfully.'];
    }

    public function isLoggedIn(): bool {
        return isset($_SESSION['user_id']);
    }

    public function logout(): bool {
        session_destroy();
        return true;
    }

    public function getCurrentUser(): ?array {
        if (!$this->isLoggedIn()) return null;
        $stmt = $this->db->prepare(
            "SELECT id, username, email, phone, role, status, avatar, bio, created_at, last_active
             FROM users WHERE id = :id"
        );
        $stmt->execute([':id' => $_SESSION['user_id']]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public function hasPermission(string $permission, $channelId = null): bool {
        if (!$this->isLoggedIn()) return false;
        $role = $_SESSION['role'];
        if ($role === 'admin') return true;
        switch ($permission) {
            case 'create_announcement': return false;
            case 'moderate_users':      return $role === 'moderator';
            case 'manage_channels':     return false;
            case 'send_message':        return in_array($role, ['moderator', 'member']);
            default:                    return false;
        }
    }
}
