<?php
require_once 'includes/auth.php';

$auth = new Auth();

if ($auth->isLoggedIn()) {
    header('Location: index.php');
    exit;
}

$inviteCode = $_GET['invite'] ?? '';
$error      = '';
$success    = '';
$formData   = []; // preserve valid fields on error

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // ── CSRF check ────────────────────────────────────────────────────────────
    if (!Auth::verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $error = 'Invalid request. Please try again.';
    } else {
        $username        = trim($_POST['username']         ?? '');
        $email           = trim($_POST['email']            ?? '');
        $phone           = trim($_POST['phone']            ?? '');
        $password        = $_POST['password']              ?? '';
        $confirmPassword = $_POST['confirm_password']      ?? '';
        $inviteCode      = trim($_POST['invite_code']      ?? '');

        $formData = compact('username', 'email', 'phone', 'inviteCode');

        // ── Client-side-style validations (belt-and-suspenders) ───────────────
        if (!preg_match('/^[a-z0-9._]{3,30}$/', $username)) {
            $error = 'Username must be 3–30 characters and contain only a–z, 0–9, . or _';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'Please enter a valid email address.';
        } elseif (strlen($password) < 8) {
            $error = 'Password must be at least 8 characters.';
        } elseif ($password !== $confirmPassword) {
            $error = 'Passwords do not match.';
        } else {
            $result = $auth->register($username, $email, $phone, $password, $inviteCode);
            if ($result['success']) {
                $success = $result['message'];
                $formData = []; // clear form on success
            } else {
                $error = $result['message'];
            }
        }
    }
}

$csrfToken = Auth::generateCsrfToken();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Register - HG Community</title>
    <link rel="stylesheet" href="assets/css/auth.css">
    <style>
        .field-hint  { font-size:.75rem; color:#949ba4; margin-top:4px; display:block; }
        .input-error { border-color:#ed4245 !important; }
        .pw-strength { height:4px; border-radius:2px; margin-top:6px; transition:width .3s,background .3s; width:0; }
    </style>
</head>
<body>
    <div class="auth-container">
        <div class="auth-card">
            <div class="auth-header">
                <h1>Join HG Community</h1>
                <p>Create your account to join Hackers Gurukul Community</p>
            </div>

            <?php if ($error): ?>
                <div class="error-message"><?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>
            <?php if ($success): ?>
                <div class="success-message">
                    <?php echo htmlspecialchars($success); ?>
                    <a href="login.php">Sign in now</a>
                </div>
            <?php endif; ?>

            <?php if (!$success): ?>
            <form method="POST" class="auth-form" id="register-form" autocomplete="off" novalidate>
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">

                <div class="form-group">
                    <label for="username">Username</label>
                    <input type="text" id="username" name="username" required
                           pattern="[a-z0-9._]{3,30}"
                           value="<?php echo htmlspecialchars($formData['username'] ?? ''); ?>"
                           autocomplete="username">
                    <span class="field-hint">3–30 characters, only a–z, 0–9, dot or underscore</span>
                    <div id="username-feedback" style="font-size:.78rem;margin-top:3px"></div>
                </div>

                <div class="form-group">
                    <label for="email">Email</label>
                    <input type="email" id="email" name="email" required
                           value="<?php echo htmlspecialchars($formData['email'] ?? ''); ?>"
                           autocomplete="email">
                </div>

                <div class="form-group">
                    <label for="phone">Phone <span style="color:#949ba4;font-size:.8rem">(optional)</span></label>
                    <input type="tel" id="phone" name="phone"
                           value="<?php echo htmlspecialchars($formData['phone'] ?? ''); ?>"
                           autocomplete="tel">
                </div>

                <div class="form-group">
                    <label for="password">Password</label>
                    <input type="password" id="password" name="password" required
                           minlength="8" autocomplete="new-password">
                    <div class="pw-strength" id="pw-strength"></div>
                    <span class="field-hint" id="pw-hint">At least 8 characters</span>
                </div>

                <div class="form-group">
                    <label for="confirm_password">Confirm Password</label>
                    <input type="password" id="confirm_password" name="confirm_password" required
                           autocomplete="new-password">
                    <div id="pw-match-feedback" style="font-size:.78rem;margin-top:3px"></div>
                </div>

                <div class="form-group">
                    <label for="invite_code">Invite Code</label>
                    <input type="text" id="invite_code" name="invite_code" required
                           value="<?php echo htmlspecialchars($formData['inviteCode'] ?? $inviteCode); ?>"
                           autocomplete="off">
                </div>

                <button type="submit" class="auth-button" id="submit-btn">Create Account</button>
            </form>
            <?php endif; ?>

            <div class="auth-footer">
                <p>Already have an account? <a href="login.php">Sign in</a></p>
            </div>
        </div>
    </div>

<script>
// ── Live username validation ───────────────────────────────────────────────────
const usernameInput  = document.getElementById('username');
const usernameFb     = document.getElementById('username-feedback');
const usernameRegex  = /^[a-z0-9._]{3,30}$/;

usernameInput?.addEventListener('input', () => {
    const val = usernameInput.value;
    if (!val) { usernameFb.textContent = ''; return; }
    if (usernameRegex.test(val)) {
        usernameFb.textContent = '✓ Looks good';
        usernameFb.style.color = '#23a559';
        usernameInput.classList.remove('input-error');
    } else {
        usernameFb.textContent = 'Only a–z, 0–9, dot, underscore — 3 to 30 chars';
        usernameFb.style.color = '#ed4245';
        usernameInput.classList.add('input-error');
    }
});

// ── Password strength meter ────────────────────────────────────────────────────
const pwInput    = document.getElementById('password');
const pwStrength = document.getElementById('pw-strength');
const pwHint     = document.getElementById('pw-hint');

pwInput?.addEventListener('input', () => {
    const v = pwInput.value;
    let score = 0;
    if (v.length >= 8)                    score++;
    if (v.length >= 12)                   score++;
    if (/[A-Z]/.test(v))                  score++;
    if (/[0-9]/.test(v))                  score++;
    if (/[^A-Za-z0-9]/.test(v))           score++;

    const levels = [
        { w: '0%',   bg: 'transparent', label: 'At least 8 characters' },
        { w: '25%',  bg: '#ed4245',      label: 'Weak' },
        { w: '50%',  bg: '#f0b232',      label: 'Fair' },
        { w: '75%',  bg: '#5865f2',      label: 'Good' },
        { w: '100%', bg: '#23a559',      label: 'Strong' },
    ];
    const l = levels[Math.min(score, 4)];
    pwStrength.style.width      = l.w;
    pwStrength.style.background = l.bg;
    pwHint.textContent          = l.label;
    pwHint.style.color          = l.bg === 'transparent' ? '#949ba4' : l.bg;
});

// ── Password match feedback ────────────────────────────────────────────────────
const confirmInput = document.getElementById('confirm_password');
const matchFb      = document.getElementById('pw-match-feedback');

confirmInput?.addEventListener('input', () => {
    if (!confirmInput.value) { matchFb.textContent = ''; return; }
    if (confirmInput.value === pwInput.value) {
        matchFb.textContent = '✓ Passwords match';
        matchFb.style.color = '#23a559';
    } else {
        matchFb.textContent = '✗ Passwords do not match';
        matchFb.style.color = '#ed4245';
    }
});

// ── Form submit guard ─────────────────────────────────────────────────────────
document.getElementById('register-form')?.addEventListener('submit', (e) => {
    const username = usernameInput.value;
    const pw       = pwInput.value;
    const confirm  = confirmInput.value;

    if (!usernameRegex.test(username)) {
        e.preventDefault();
        usernameFb.textContent = 'Fix your username before continuing.';
        usernameFb.style.color = '#ed4245';
        usernameInput.focus();
        return;
    }
    if (pw.length < 8) {
        e.preventDefault();
        pwHint.textContent = 'Password must be at least 8 characters.';
        pwHint.style.color = '#ed4245';
        pwInput.focus();
        return;
    }
    if (pw !== confirm) {
        e.preventDefault();
        matchFb.textContent = '✗ Passwords do not match';
        matchFb.style.color = '#ed4245';
        confirmInput.focus();
        return;
    }
});
</script>
</body>
</html>
