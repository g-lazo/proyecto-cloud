<?php
declare(strict_types=1);

$host = getenv('DB_HOST') ?: 'localhost';
$db   = getenv('DB_NAME') ?: 'studentwallet';
$user = getenv('DB_USER') ?: 'sw_app';
$pass = getenv('DB_PASS') ?: '';

try {
    $pdo = new PDO(
        "mysql:host=$host;dbname=$db;charset=utf8mb4",
        $user,
        $pass,
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
            PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4",
        ]
    );
} catch (Throwable $e) {
    error_log('DB connection: ' . $e->getMessage());
    http_response_code(500);
    exit('Error de conexión');
}
