<?php
declare(strict_types=1);
require __DIR__ . '/../config/db.php';
require __DIR__ . '/../config/helpers.php';
require __DIR__ . '/../config/auth_check.php';

$uid = (int)$_SESSION['user_id'];

// POST = ejecutar borrado
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify_or_die();

    $gastoId = require_post_int('id', 1);

    // DELETE con usuario_id obligatorio. Si no es suyo, rowCount=0, sin error.
    $del = $pdo->prepare('DELETE FROM gastos WHERE id = ? AND usuario_id = ?');
    $del->execute([$gastoId, $uid]);

    if ($del->rowCount() > 0) {
        $_SESSION['_flash']['success'][] = 'Gasto eliminado.';
    } else {
        $_SESSION['_flash']['error'][] = 'No se encontró el gasto.';
    }
    header('Location: /eliminar.php');
    exit;
}

// GET = mostrar lista con botones de borrado
$stmt = $pdo->prepare('
    SELECT g.id, g.monto, g.descripcion, g.fecha, c.nombre AS categoria
    FROM gastos g
    JOIN categorias c ON c.id = g.categoria_id
    WHERE g.usuario_id = ?
    ORDER BY g.fecha DESC, g.id DESC
    LIMIT 100
');
$stmt->execute([$uid]);
$gastos = $stmt->fetchAll();

$pageTitle = 'Eliminar gasto';
require __DIR__ . '/../templates/header.php';
?>
<div class="bg-white rounded-xl shadow border border-slate-200 p-6">
    <h1 class="text-xl font-bold mb-4">Eliminar gasto</h1>
    <p class="text-sm text-slate-500 mb-4">Selecciona el gasto que quieres eliminar. <strong>Esta acción no se puede deshacer.</strong></p>

    <?php if (!$gastos): ?>
        <p class="text-slate-500">No tienes gastos registrados.</p>
    <?php else: ?>
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-slate-100 text-left">
                    <tr>
                        <th class="px-3 py-2">Fecha</th>
                        <th class="px-3 py-2">Categoría</th>
                        <th class="px-3 py-2">Descripción</th>
                        <th class="px-3 py-2 text-right">Monto</th>
                        <th class="px-3 py-2 text-right">Acción</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($gastos as $g): ?>
                    <tr class="border-b border-slate-100">
                        <td class="px-3 py-2"><?= e((string)$g['fecha']) ?></td>
                        <td class="px-3 py-2"><?= e((string)$g['categoria']) ?></td>
                        <td class="px-3 py-2"><?= e((string)($g['descripcion'] ?? '')) ?></td>
                        <td class="px-3 py-2 text-right"><?= e(format_currency((float)$g['monto'])) ?></td>
                        <td class="px-3 py-2 text-right">
                            <form method="POST" class="inline"
                                  onsubmit="return confirm('¿Eliminar este gasto de <?= e(format_currency((float)$g['monto'])) ?>?');">
                                <?= csrf_field() ?>
                                <input type="hidden" name="id" value="<?= (int)$g['id'] ?>">
                                <button type="submit" class="bg-rose-100 text-rose-700 hover:bg-rose-200 px-3 py-1 rounded">
                                    Eliminar
                                </button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>
<?php require __DIR__ . '/../templates/footer.php'; ?>
