<?php
require_once 'includes/auth.php';

$auth = new Auth();

if ($auth->isLoggedIn()) {
    header('Location: index.php');
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $username = $_POST['username'];
    $password = $_POST['password'];
    
    $result = $auth->login($username, $password);
    
    if ($result['success']) {
        header('Location: index.php');
        exit;
    } else {
        $error = $result['message'];
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - HG Community</title>
    <link rel="stylesheet" href="assets/css/auth.css">
</head>
<body>
    <div class="auth-container">
        <div class="auth-card">
            <div class="auth-header">
                <h1>HG Community</h1>
                <p>Welcome back to Hackers Gurukul Community</p>
            </div>
            
            <?php if ($error): ?>
                <div class="error-message"><?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>
            
            <form method="POST" class="auth-form">
                <div class="form-group">
                    <label for="username">Username or Email</label>
                    <input type="text" id="username" name="username" required>
                </div>
                
                <div class="form-group">
                    <label for="password">Password</label>
                    <input type="password" id="password" name="password" required>
                </div>
                
                <button type="submit" class="auth-button">Sign In</button>
            </form>
            
            <div class="auth-footer">
                <p>Don't have an account? Contact an admin for an invite.</p>
            </div>
        </div>
    </div>
</body>
</html>