<?php
// ── Secure session cookie settings — must be set BEFORE session_start() ───────
if (session_status() === PHP_SESSION_NONE) {
    $isHttps = (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https')
               || (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');

    session_set_cookie_params([
        'lifetime' => 0,           // expires when browser closes
        'path'     => '/',
        'domain'   => '',
        'secure'   => $isHttps,    // only send over HTTPS when on Railway
        'httponly' => true,        // JS cannot read the cookie — blocks XSS session theft
        'samesite' => 'Lax',       // blocks CSRF on cross-site requests
    ]);
    session_start();
}

require_once __DIR__ . '/../config/database.php';

class Auth {
    private $db;

    const MAX_ATTEMPTS    = 5;
    const LOCKOUT_SECONDS = 900; // 15 minutes

    public function __construct() {
        $database = new Database();
        $this->db = $database->getConnection();
    }

    // ── CSRF ──────────────────────────────────────────────────────────────────
    public static function generateCsrfToken(): string {
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf_token'];
    }

    public static function verifyCsrfToken(string $token): bool {
        return !empty($_SESSION['csrf_token'])
            && hash_equals($_SESSION['csrf_token'], $token);
    }

    // ── Rate limiting ─────────────────────────────────────────────────────────
    private function getRateLimitKey(string $identifier): string {
        return 'login_' . md5($identifier . ($_SERVER['REMOTE_ADDR'] ?? ''));
    }

    private function checkRateLimit(string $identifier): array {
        $key  = $this->getRateLimitKey($identifier);
        $stmt = $this->db->prepare(
            "SELECT attempts, locked_until FROM login_attempts WHERE attempt_key = :key"
        );
        $stmt->execute([':key' => $key]);
        $row = $stmt->fetch();

        if (!$row) return ['blocked' => false, 'attempts' => 0];

        // Auto-expire old lockouts
        if ($row['locked_until'] && strtotime($row['locked_until']) <= time()) {
            $this->db->prepare("DELETE FROM login_attempts WHERE attempt_key = :key")
                     ->execute([':key' => $key]);
            return ['blocked' => false, 'attempts' => 0];
        }

        if ($row['locked_until'] && strtotime($row['locked_until']) > time()) {
            $remaining = ceil((strtotime($row['locked_until']) - time()) / 60);
            return ['blocked' => true, 'attempts' => (int)$row['attempts'], 'remaining' => $remaining];
        }

        return ['blocked' => false, 'attempts' => (int)$row['attempts']];
    }

    private function recordFailedAttempt(string $identifier): void {
        $key      = $this->getRateLimitKey($identifier);
        $current  = $this->checkRateLimit($identifier);
        $attempts = $current['attempts'] + 1;
        $lock     = $attempts >= self::MAX_ATTEMPTS
            ? date('Y-m-d H:i:s', time() + self::LOCKOUT_SECONDS) : null;

        $this->db->prepare(
            "INSERT INTO login_attempts (attempt_key, attempts, locked_until, last_attempt)
             VALUES (:key, :att, :lock, NOW())
             ON DUPLICATE KEY UPDATE attempts=:att2, locked_until=:lock2, last_attempt=NOW()"
        )->execute([':key'=>$key, ':att'=>$attempts, ':att2'=>$attempts, ':lock'=>$lock, ':lock2'=>$lock]);
    }

    private function clearAttempts(string $identifier): void {
        $this->db->prepare("DELETE FROM login_attempts WHERE attempt_key = :key")
                 ->execute([':key' => $this->getRateLimitKey($identifier)]);
    }

    // ── Login ─────────────────────────────────────────────────────────────────
    public function login(string $username, string $password): array {
        $limit = $this->checkRateLimit($username);
        if ($limit['blocked']) {
            return ['success' => false,
                    'message' => "Too many failed attempts. Try again in {$limit['remaining']} minute(s)."];
        }

        $stmt = $this->db->prepare(
            "SELECT id, username, email, password, role, status FROM users
             WHERE (username = :u OR email = :u) AND status != 'banned'"
        );
        $stmt->execute([':u' => $username]);

        if ($stmt->rowCount() === 1) {
            $row = $stmt->fetch();
            if (password_verify($password, $row['password'])) {
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

        $this->recordFailedAttempt($username);
        $limitAfter = $this->checkRateLimit($username);
        $left = self::MAX_ATTEMPTS - $limitAfter['attempts'];
        $warn = $left > 0 ? " ({$left} attempt(s) remaining)" : ' (Account locked for 15 minutes)';
        return ['success' => false, 'message' => 'Invalid username or password.' . $warn];
    }

    // ── Register ──────────────────────────────────────────────────────────────
    public function register(string $username, string $email, ?string $phone,
                             string $password, ?string $inviteCode): array {
        if (empty(trim((string)$inviteCode))) {
            return ['success' => false, 'message' => 'An invite code is required to register.'];
        }
        if (!preg_match('/^[a-z0-9._]{3,30}$/', $username)) {
            return ['success' => false, 'message' => 'Username must be 3–30 characters: a–z, 0–9, . or _ only.'];
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return ['success' => false, 'message' => 'Invalid email address.'];
        }
        if (strlen($password) < 8) {
            return ['success' => false, 'message' => 'Password must be at least 8 characters.'];
        }

        // Validate invite — support both single and group
        $inv = $this->db->prepare(
            "SELECT id, role, invite_type FROM invites
             WHERE invite_code = :code AND expires_at > NOW()
               AND (used_at IS NULL OR invite_type = 'group')"
        );
        $inv->execute([':code' => $inviteCode]);
        if ($inv->rowCount() === 0) {
            return ['success' => false, 'message' => 'Invalid or expired invite code.'];
        }
        $invite = $inv->fetch();

        // Uniqueness check
        $chk = $this->db->prepare(
            "SELECT id FROM users WHERE username = :u OR email = :e"
        );
        $chk->execute([':u' => $username, ':e' => $email]);
        if ($chk->rowCount() > 0) {
            return ['success' => false, 'message' => 'Username or email already exists.'];
        }

        $hash = password_hash($password, PASSWORD_DEFAULT);
        $this->db->prepare(
            "INSERT INTO users (username, email, phone, password, role)
             VALUES (:u, :e, :p, :h, :r)"
        )->execute([':u'=>$username, ':e'=>$email, ':p'=>$phone?:null, ':h'=>$hash, ':r'=>$invite['role']]);

        $userId = $this->db->lastInsertId();

        if (($invite['invite_type'] ?? 'single') === 'single') {
            $this->db->prepare(
                "UPDATE invites SET used_at=NOW(), used_by=:uid WHERE invite_code=:code"
            )->execute([':uid'=>$userId, ':code'=>$inviteCode]);
        }

        return ['success' => true, 'message' => 'Account created successfully.'];
    }

    // ── Session helpers ───────────────────────────────────────────────────────
    public function isLoggedIn(): bool {
        return isset($_SESSION['user_id']);
    }

    public function logout(): void {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000,
                $p['path'], $p['domain'], $p['secure'], $p['httponly']);
        }
        session_destroy();
    }

    // ── Re-validate role from DB every request (prevents stale session escalation) ──
    public function getCurrentUser(): ?array {
        if (!$this->isLoggedIn()) return null;
        $stmt = $this->db->prepare(
            "SELECT id, username, email, phone, role, status, avatar, bio,
                    avatar_visibility, created_at, last_active
             FROM users WHERE id = :id AND status != 'banned'"
        );
        $stmt->execute([':id' => $_SESSION['user_id']]);
        $user = $stmt->fetch();
        if (!$user) {
            // User was banned or deleted — kill session
            $this->logout();
            return null;
        }
        // Keep session role in sync with DB
        $_SESSION['role'] = $user['role'];
        return $user;
    }

    // ── Permissions ───────────────────────────────────────────────────────────
    public function hasPermission(string $permission, ?int $channelId = null): bool {
        if (!$this->isLoggedIn()) return false;
        $role = $_SESSION['role'];
        if ($role === 'admin') return true;

        switch ($permission) {
            case 'moderate_users':  return $role === 'moderator';
            case 'manage_channels': return $role === 'moderator'; // moderators CAN edit channels
            case 'send_message':    return in_array($role, ['moderator', 'member']);
            default:                return false;
        }
    }

    // ── Anti-escalation: prevent moderators acting on admins/other mods ───────
    public function canModerate(int $targetUserId): bool {
        if (!$this->isLoggedIn()) return false;
        if ($_SESSION['role'] === 'admin') return true;
        if ($_SESSION['role'] !== 'moderator') return false;

        // Moderators cannot moderate admins or other moderators
        $stmt = $this->db->prepare("SELECT role FROM users WHERE id = :id");
        $stmt->execute([':id' => $targetUserId]);
        $target = $stmt->fetch();
        return $target && $target['role'] === 'member';
    }

    // ── CSRF-protected logout ─────────────────────────────────────────────────
    public static function verifyLogoutToken(string $token): bool {
        return !empty($_SESSION['logout_token'])
            && hash_equals($_SESSION['logout_token'], $token);
    }

    public static function generateLogoutToken(): string {
        if (empty($_SESSION['logout_token'])) {
            $_SESSION['logout_token'] = bin2hex(random_bytes(16));
        }
        return $_SESSION['logout_token'];
    }
}
