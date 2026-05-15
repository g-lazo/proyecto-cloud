<?php
declare(strict_types=1);
require __DIR__ . '/../config/helpers.php';
session_start();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Use POST');
}
csrf_verify_or_die();

$_SESSION = [];
if (ini_get('session.use_cookies')) {
    setcookie(session_name(), '', time() - 42000, '/');
}
session_destroy();
header('Location: /login.php');
exit;
