<?php
declare(strict_types=1);
require __DIR__ . '/../config/db.php';
require __DIR__ . '/../config/helpers.php';
require __DIR__ . '/../config/auth_check.php';

$uid = (int)$_SESSION['user_id'];

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

    $stmt = $pdo->prepare('
        INSERT INTO gastos (usuario_id, categoria_id, monto, descripcion, metodo_pago, fecha)
        VALUES (?, ?, ?, ?, ?, ?)
    ');
    // usuario_id viene de SESSION, NUNCA del form
    $stmt->execute([$uid, $categoria_id, $monto, $descripcion, $metodo, $fecha]);

    $_SESSION['_flash']['success'][] = 'Gasto registrado.';
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

$pageTitle = 'Registrar gasto';
require __DIR__ . '/../templates/header.php';
?>
<div class="max-w-xl mx-auto bg-white rounded-xl shadow border border-slate-200 p-6">
    <h1 class="text-xl font-bold mb-4">Registrar gasto</h1>

    <form method="POST" class="space-y-4">
        <?= csrf_field() ?>

        <div>
            <label class="block text-sm font-medium mb-1">Monto (MXN)</label>
            <input type="number" step="0.01" min="0.01" max="99999999.99" name="monto" required
                   class="w-full border border-slate-300 rounded px-3 py-2 focus:ring-2 focus:ring-indigo-400">
        </div>

        <div>
            <label class="block text-sm font-medium mb-1">Categoría</label>
            <select name="categoria_id" required class="w-full border border-slate-300 rounded px-3 py-2">
                <?php foreach ($categorias as $c): ?>
                    <option value="<?= (int)$c['id'] ?>"><?= e($c['nombre']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <div>
            <label class="block text-sm font-medium mb-1">Descripción <span class="text-slate-400 text-xs">(opcional)</span></label>
            <input type="text" name="descripcion" maxlength="255"
                   class="w-full border border-slate-300 rounded px-3 py-2">
        </div>

        <div class="grid grid-cols-2 gap-3">
            <div>
                <label class="block text-sm font-medium mb-1">Fecha</label>
                <input type="date" name="fecha" required value="<?= e(date('Y-m-d')) ?>"
                       min="2020-01-01" max="2100-12-31"
                       class="w-full border border-slate-300 rounded px-3 py-2">
            </div>
            <div>
                <label class="block text-sm font-medium mb-1">Método de pago</label>
                <select name="metodo_pago" class="w-full border border-slate-300 rounded px-3 py-2">
                    <option value="efectivo">Efectivo</option>
                    <option value="tarjeta">Tarjeta</option>
                    <option value="transferencia">Transferencia</option>
                </select>
            </div>
        </div>

        <button type="submit" class="w-full bg-indigo-600 text-white py-2 rounded hover:bg-indigo-700">
            Guardar gasto
        </button>
    </form>
</div>
<?php require __DIR__ . '/../templates/footer.php'; ?>
