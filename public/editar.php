<?php
declare(strict_types=1);
require __DIR__ . '/../config/db.php';
require __DIR__ . '/../config/helpers.php';
require __DIR__ . '/../config/auth_check.php';

$uid = (int)$_SESSION['user_id'];

// Sin id → redirige a consulta para que el usuario escoja desde la lista
if (!isset($_GET['id']) && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /consulta.php');
    exit;
}

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
<div class="max-w-lg mx-auto py-12">
    <header class="mb-8">
        <a href="/consulta.php" class="text-sm text-slate-500 hover:text-slate-900 inline-flex items-center gap-1 mb-3">
            ← Volver a Gastos
        </a>
        <h1 class="text-3xl font-bold tracking-tight">Editar gasto</h1>
        <p class="text-sm text-slate-500 mt-1">Actualiza los datos y guarda los cambios.</p>
    </header>

    <form method="POST" class="space-y-5">
        <?= csrf_field() ?>
        <input type="hidden" name="id" value="<?= (int)$gasto['id'] ?>">

        <div>
            <label class="block text-sm font-medium mb-1.5 text-slate-700">Monto</label>
            <div class="relative">
                <span class="absolute left-3 top-1/2 -translate-y-1/2 text-slate-400">$</span>
                <input type="number" step="0.01" min="0.01" max="99999999.99" name="monto" required
                       value="<?= e_attr((string)$gasto['monto']) ?>"
                       class="input-clean pl-7 hero-number text-lg">
            </div>
        </div>

        <div>
            <label class="block text-sm font-medium mb-1.5 text-slate-700">Categoría</label>
            <select name="categoria_id" required class="input-clean">
                <?php foreach ($categorias as $c): ?>
                    <option value="<?= (int)$c['id'] ?>" <?= (int)$c['id'] === (int)$gasto['categoria_id'] ? 'selected' : '' ?>>
                        <?= e($c['nombre']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div>
            <label class="block text-sm font-medium mb-1.5 text-slate-700">Descripción</label>
            <input type="text" name="descripcion" maxlength="255"
                   value="<?= e_attr((string)($gasto['descripcion'] ?? '')) ?>"
                   class="input-clean">
        </div>

        <div class="grid grid-cols-2 gap-4">
            <div>
                <label class="block text-sm font-medium mb-1.5 text-slate-700">Fecha</label>
                <input type="date" name="fecha" required value="<?= e_attr((string)$gasto['fecha']) ?>"
                       min="2020-01-01" max="2100-12-31" class="input-clean">
            </div>
            <div>
                <label class="block text-sm font-medium mb-1.5 text-slate-700">Método</label>
                <select name="metodo_pago" class="input-clean">
                    <?php foreach (['efectivo', 'tarjeta', 'transferencia'] as $m): ?>
                        <option value="<?= e_attr($m) ?>" <?= $m === $gasto['metodo_pago'] ? 'selected' : '' ?>>
                            <?= e(ucfirst($m)) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>

        <div class="flex gap-3 pt-2">
            <button type="submit" class="btn-primary flex-1">Guardar cambios</button>
            <a href="/consulta.php" class="btn-secondary">Cancelar</a>
        </div>
    </form>
</div>
<?php require __DIR__ . '/../templates/footer.php'; ?>
