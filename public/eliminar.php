<?php
declare(strict_types=1);
require __DIR__ . '/../config/db.php';
require __DIR__ . '/../config/helpers.php';
require __DIR__ . '/../config/auth_check.php';

$uid = (int)$_SESSION['user_id'];

// GET → redirige a consulta. Eliminar es una acción, no una vista.
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /consulta.php');
    exit;
}

csrf_verify_or_die();
$gastoId = require_post_int('id', 1);

// DELETE con usuario_id obligatorio. Si no es suyo, rowCount=0, misma respuesta.
$del = $pdo->prepare('DELETE FROM gastos WHERE id = ? AND usuario_id = ?');
$del->execute([$gastoId, $uid]);

if ($del->rowCount() > 0) {
    $_SESSION['_flash']['success'][] = 'Gasto eliminado.';
} else {
    $_SESSION['_flash']['error'][] = 'No se encontró el gasto.';
}
header('Location: /consulta.php');
exit;
