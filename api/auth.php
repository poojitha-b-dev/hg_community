<?php
require_once '../includes/auth.php';

if (isset($_GET['action']) && $_GET['action'] == 'logout') {
    $auth = new Auth();
    $auth->logout();
    header('Location: ../login.php');
    exit;
}
?>