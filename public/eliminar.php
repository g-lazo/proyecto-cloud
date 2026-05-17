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

$modo = $_POST['modo'] ?? 'single';

if ($modo === 'bulk') {
    // -------- Bulk: eliminar todos los gastos del mes/año (y categoría opcional) --------
    $mes  = require_post_int('mes',  1, 12);
    $anio = require_post_int('anio', 2020, 2100);
    $cat  = filter_input(INPUT_POST, 'categoria', FILTER_VALIDATE_INT,
                ['options' => ['min_range' => 1]]) ?: 0;

    $where  = 'usuario_id = ? AND MONTH(fecha) = ? AND YEAR(fecha) = ?';
    $params = [$uid, $mes, $anio];
    if ($cat > 0) {
        // Verifica que la categoría sea del sistema o del propio usuario
        if (!categoria_pertenece_al_usuario($pdo, $cat, $uid)) {
            die_400('Categoría inválida');
        }
        $where .= ' AND categoria_id = ?';
        $params[] = $cat;
    }

    $del = $pdo->prepare("DELETE FROM gastos WHERE $where");
    $del->execute($params);
    $count = $del->rowCount();

    if ($count > 0) {
        $_SESSION['_flash']['success'][] = "Se eliminaron {$count} gasto(s) de " . nombre_mes($mes) . " {$anio}.";
    } else {
        $_SESSION['_flash']['error'][] = 'No había gastos para eliminar en ese periodo.';
    }

    // Mantener filtros activos al regresar
    $back = "/consulta.php?mes={$mes}&anio={$anio}";
    if ($cat > 0) $back .= "&categoria={$cat}";
    header("Location: {$back}");
    exit;
}

// -------- Single: borrar un gasto específico (modo default) --------
$gastoId = require_post_int('id', 1);

$del = $pdo->prepare('DELETE FROM gastos WHERE id = ? AND usuario_id = ?');
$del->execute([$gastoId, $uid]);

if ($del->rowCount() > 0) {
    $_SESSION['_flash']['success'][] = 'Gasto eliminado.';
} else {
    $_SESSION['_flash']['error'][] = 'No se encontró el gasto.';
}
header('Location: /consulta.php');
exit;
