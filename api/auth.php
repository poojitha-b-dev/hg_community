<?php
require_once __DIR__ . '/../includes/auth.php';

if (isset($_GET['action']) && $_GET['action'] === 'logout') {
    // Verify CSRF logout token — prevents silent cross-site logout
    $token = $_GET['token'] ?? '';
    if (!Auth::verifyLogoutToken($token)) {
        http_response_code(403);
        echo 'Invalid logout request.';
        exit;
    }
    $auth = new Auth();
    $auth->logout();
    header('Location: ../login.php');
    exit;
}
