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
    $stmt->execute([$uid, $categoria_id, $monto, $descripcion, $metodo, $fecha]);

    $_SESSION['_flash']['success'][] = 'Gasto registrado correctamente.';
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
<div class="max-w-lg mx-auto py-12">
    <header class="mb-8">
        <h1 class="text-3xl font-bold tracking-tight">Nuevo gasto</h1>
        <p class="text-sm text-slate-500 mt-1">Registra un movimiento y mantén tus finanzas al día.</p>
    </header>

    <form method="POST" class="space-y-5">
        <?= csrf_field() ?>

        <div>
            <label class="block text-sm font-medium mb-1.5 text-slate-700">Monto</label>
            <div class="relative">
                <span class="absolute left-3 top-1/2 -translate-y-1/2 text-slate-400">$</span>
                <input type="number" step="0.01" min="0.01" max="99999999.99" name="monto" required
                       placeholder="0.00"
                       class="input-clean pl-7 hero-number text-lg">
            </div>
        </div>

        <div>
            <label class="block text-sm font-medium mb-1.5 text-slate-700">Categoría</label>
            <select name="categoria_id" required class="input-clean">
                <?php foreach ($categorias as $c): ?>
                    <option value="<?= (int)$c['id'] ?>"><?= e($c['nombre']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <div>
            <label class="block text-sm font-medium mb-1.5 text-slate-700">
                Descripción <span class="text-slate-400 font-normal">— opcional</span>
            </label>
            <input type="text" name="descripcion" maxlength="255"
                   placeholder="Ej. Café con Diana"
                   class="input-clean">
        </div>

        <div class="grid grid-cols-2 gap-4">
            <div>
                <label class="block text-sm font-medium mb-1.5 text-slate-700">Fecha</label>
                <input type="date" name="fecha" required value="<?= e(date('Y-m-d')) ?>"
                       min="2020-01-01" max="2100-12-31"
                       class="input-clean">
            </div>
            <div>
                <label class="block text-sm font-medium mb-1.5 text-slate-700">Método</label>
                <select name="metodo_pago" class="input-clean">
                    <option value="efectivo">Efectivo</option>
                    <option value="tarjeta">Tarjeta</option>
                    <option value="transferencia">Transferencia</option>
                </select>
            </div>
        </div>

        <div class="flex gap-3 pt-2">
            <button type="submit" class="btn-primary flex-1">Guardar gasto</button>
            <a href="/index.php" class="btn-secondary">Cancelar</a>
        </div>
    </form>
</div>
<?php require __DIR__ . '/../templates/footer.php'; ?>
