<?php
declare(strict_types=1);
require __DIR__ . '/../config/db.php';
require __DIR__ . '/../config/helpers.php';
require __DIR__ . '/../config/auth_check.php';

$uid = (int)$_SESSION['user_id'];

// Modo selección: lista de gastos para escoger cuál editar
if (!isset($_GET['id']) && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    $stmt = $pdo->prepare('
        SELECT g.id, g.monto, g.descripcion, g.fecha, c.nombre AS categoria
        FROM gastos g
        JOIN categorias c ON c.id = g.categoria_id
        WHERE g.usuario_id = ?
        ORDER BY g.fecha DESC, g.id DESC
        LIMIT 50
    ');
    $stmt->execute([$uid]);
    $gastos = $stmt->fetchAll();

    $pageTitle = 'Editar gasto';
    require __DIR__ . '/../templates/header.php';
    ?>
    <div class="bg-white rounded-xl shadow border border-slate-200 p-6">
        <h1 class="text-xl font-bold mb-4">Selecciona un gasto para editar</h1>
        <?php if (!$gastos): ?>
            <p class="text-slate-500">No tienes gastos registrados.</p>
        <?php else: ?>
            <ul class="divide-y divide-slate-100">
            <?php foreach ($gastos as $g): ?>
                <li class="py-2 flex items-center justify-between">
                    <div>
                        <span class="text-slate-500 text-sm"><?= e((string)$g['fecha']) ?></span>
                        ·
                        <strong><?= e((string)$g['categoria']) ?></strong>
                        ·
                        <?= e((string)($g['descripcion'] ?? '')) ?>
                    </div>
                    <div class="flex items-center gap-3">
                        <span class="font-medium"><?= e(format_currency((float)$g['monto'])) ?></span>
                        <a href="/editar.php?id=<?= (int)$g['id'] ?>" class="text-indigo-600 hover:underline text-sm">Editar →</a>
                    </div>
                </li>
            <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </div>
    <?php
    require __DIR__ . '/../templates/footer.php';
    exit;
}

// Modo edición: id puede venir por GET (cargar) o POST (guardar)
$gastoId = (int)($_POST['id'] ?? $_GET['id'] ?? 0);
if ($gastoId < 1) die_400('ID inválido');

// SELECT con usuario_id en WHERE — si no es del usuario, 404
$stmt = $pdo->prepare('SELECT * FROM gastos WHERE id = ? AND usuario_id = ?');
$stmt->execute([$gastoId, $uid]);
$gasto = $stmt->fetch();
if (!$gasto) die_404();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify_or_die();

    $monto        = require_post_decimal('monto');
    $categoria_id = require_post_int('categoria_id', 1);
    $descripcion  = require_post_string('descripcion', 255, required: false);
    $fecha        = require_post_date('fecha');
    $metodo       = require_post_enum('metodo_pago', ['efectivo', 'tarjeta', 'transferencia']);

    if (!categoria_pertenece_al_usuario($pdo, $categoria_id, $uid)) {
        die_400('Categoría inválida');
    }

    // Whitelist explícita: usuario_id NUNCA se incluye. WHERE con usuario_id por defensa en profundidad.
    $upd = $pdo->prepare('
        UPDATE gastos
        SET monto = ?, categoria_id = ?, descripcion = ?, metodo_pago = ?, fecha = ?
        WHERE id = ? AND usuario_id = ?
    ');
    $upd->execute([$monto, $categoria_id, $descripcion, $metodo, $fecha, $gastoId, $uid]);

    $_SESSION['_flash']['success'][] = 'Gasto actualizado.';
    header('Location: /consulta.php');
    exit;
}

$cats = $pdo->prepare('
    SELECT id, nombre FROM categorias
    WHERE usuario_id IS NULL OR usuario_id = ?
    ORDER BY usuario_id IS NULL DESC, nombre
');
$cats->execute([$uid]);
$categorias = $cats->fetchAll();

$pageTitle = 'Editar gasto';
require __DIR__ . '/../templates/header.php';
?>
<div class="max-w-xl mx-auto bg-white rounded-xl shadow border border-slate-200 p-6">
    <h1 class="text-xl font-bold mb-4">Editar gasto #<?= (int)$gasto['id'] ?></h1>

    <form method="POST" class="space-y-4">
        <?= csrf_field() ?>
        <input type="hidden" name="id" value="<?= (int)$gasto['id'] ?>">

        <div>
            <label class="block text-sm font-medium mb-1">Monto</label>
            <input type="number" step="0.01" min="0.01" max="99999999.99" name="monto" required
                   value="<?= e_attr((string)$gasto['monto']) ?>"
                   class="w-full border border-slate-300 rounded px-3 py-2">
        </div>

        <div>
            <label class="block text-sm font-medium mb-1">Categoría</label>
            <select name="categoria_id" required class="w-full border border-slate-300 rounded px-3 py-2">
                <?php foreach ($categorias as $c): ?>
                    <option value="<?= (int)$c['id'] ?>" <?= (int)$c['id'] === (int)$gasto['categoria_id'] ? 'selected' : '' ?>>
                        <?= e($c['nombre']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div>
            <label class="block text-sm font-medium mb-1">Descripción</label>
            <input type="text" name="descripcion" maxlength="255"
                   value="<?= e_attr((string)($gasto['descripcion'] ?? '')) ?>"
                   class="w-full border border-slate-300 rounded px-3 py-2">
        </div>

        <div class="grid grid-cols-2 gap-3">
            <div>
                <label class="block text-sm font-medium mb-1">Fecha</label>
                <input type="date" name="fecha" required value="<?= e_attr((string)$gasto['fecha']) ?>"
                       min="2020-01-01" max="2100-12-31"
                       class="w-full border border-slate-300 rounded px-3 py-2">
            </div>
            <div>
                <label class="block text-sm font-medium mb-1">Método</label>
                <select name="metodo_pago" class="w-full border border-slate-300 rounded px-3 py-2">
                    <?php foreach (['efectivo', 'tarjeta', 'transferencia'] as $m): ?>
                        <option value="<?= e_attr($m) ?>" <?= $m === $gasto['metodo_pago'] ? 'selected' : '' ?>>
                            <?= e(ucfirst($m)) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>

        <div class="flex gap-2">
            <button type="submit" class="flex-1 bg-indigo-600 text-white py-2 rounded hover:bg-indigo-700">
                Guardar cambios
            </button>
            <a href="/consulta.php" class="px-4 py-2 border border-slate-300 rounded hover:bg-slate-50">Cancelar</a>
        </div>
    </form>
</div>
<?php require __DIR__ . '/../templates/footer.php'; ?>
