<?php
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start([
        'cookie_httponly' => true,
        'cookie_secure'   => (($_SERVER['HTTPS'] ?? '') === 'on'),
        'cookie_samesite' => 'Lax',
        'use_strict_mode' => true,
        'gc_maxlifetime'  => 1800,
    ]);
}

if (empty($_SESSION['user_id']) || !is_int($_SESSION['user_id'])) {
    header('Location: /login.php');
    exit;
}
