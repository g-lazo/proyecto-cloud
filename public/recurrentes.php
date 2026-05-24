<?php
declare(strict_types=1);
require __DIR__ . '/../config/db.php';
require __DIR__ . '/../config/helpers.php';
require __DIR__ . '/../config/auth_check.php';

$uid = (int)$_SESSION['user_id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify_or_die();
    $accion = $_POST['accion'] ?? '';

    if ($accion === 'crear' || $accion === 'actualizar') {
        $categoria_id = require_post_int('categoria_id', 1);
        $monto         = require_post_decimal('monto');
        $descripcion   = require_post_string('descripcion', 255);
        $dia_del_mes   = require_post_int('dia_del_mes', 1, 31);
        $fecha_inicio  = require_post_date('fecha_inicio');
        $fechaFinRaw   = trim((string)($_POST['fecha_fin'] ?? ''));
        $fecha_fin     = $fechaFinRaw === '' ? null : require_post_date('fecha_fin');
        $activo        = isset($_POST['activo']) ? 1 : 0;

        if (!categoria_pertenece_al_usuario($pdo, $categoria_id, $uid)) {
            die_400('Categoría inválida');
        }
        if ($fecha_fin !== null && $fecha_fin < $fecha_inicio) {
            die_400('La fecha fin debe ser posterior a la fecha inicio');
        }

        if ($accion === 'crear') {
            $stmt = $pdo->prepare('
                INSERT INTO gastos_recurrentes
                    (usuario_id, categoria_id, monto, descripcion, dia_del_mes, fecha_inicio, fecha_fin, activo)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ');
            $stmt->execute([$uid, $categoria_id, $monto, $descripcion, $dia_del_mes, $fecha_inicio, $fecha_fin, $activo]);
            $_SESSION['_flash']['success'][] = 'Pago recurrente creado.';
        } else {
            $id = require_post_int('id', 1);
            $upd = $pdo->prepare('
                UPDATE gastos_recurrentes
                SET categoria_id = ?, monto = ?, descripcion = ?, dia_del_mes = ?,
                    fecha_inicio = ?, fecha_fin = ?, activo = ?
                WHERE id = ? AND usuario_id = ?
            ');
            $upd->execute([$categoria_id, $monto, $descripcion, $dia_del_mes, $fecha_inicio, $fecha_fin, $activo, $id, $uid]);
            $_SESSION['_flash']['success'][] = 'Pago recurrente actualizado.';
        }
    }
    elseif ($accion === 'eliminar') {
        $id = require_post_int('id', 1);
        $del = $pdo->prepare('DELETE FROM gastos_recurrentes WHERE id = ? AND usuario_id = ?');
        $del->execute([$id, $uid]);
        $_SESSION['_flash']['success'][] = 'Pago recurrente eliminado.';
    }
    else {
        die_400('Acción inválida');
    }

    header('Location: /recurrentes.php');
    exit;
}

$catStmt = $pdo->prepare('
    SELECT id, nombre FROM categorias
    WHERE usuario_id IS NULL OR usuario_id = ?
    ORDER BY usuario_id IS NULL DESC, nombre
');
$catStmt->execute([$uid]);
$categorias = $catStmt->fetchAll();

$stmt = $pdo->prepare('
    SELECT gr.id, gr.categoria_id, gr.monto, gr.descripcion, gr.dia_del_mes,
           gr.fecha_inicio, gr.fecha_fin, gr.activo, c.nombre AS categoria
    FROM gastos_recurrentes gr
    JOIN categorias c ON c.id = gr.categoria_id
    WHERE gr.usuario_id = ?
    ORDER BY gr.activo DESC, gr.dia_del_mes ASC
');
$stmt->execute([$uid]);
$recurrentes = $stmt->fetchAll();

$editId = filter_input(INPUT_GET, 'editar', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 0;
$recurrenteEdit = null;
if ($editId > 0) {
    foreach ($recurrentes as $r) {
        if ((int)$r['id'] === $editId) { $recurrenteEdit = $r; break; }
    }
}

$pageTitle = 'Pagos recurrentes';
require __DIR__ . '/../templates/header.php';
?>
<div class="py-12">
    <header class="mb-8">
        <a href="/index.php" class="text-sm text-slate-500 hover:text-slate-900 inline-flex items-center gap-1 mb-3">← Volver al inicio</a>
        <h1 class="text-3xl font-bold tracking-tight">Pagos recurrentes</h1>
        <p class="text-sm text-slate-500 mt-1">Suscripciones y pagos mensuales fijos (renta, Spotify, etc.).</p>
    </header>

    <section class="bg-slate-50 rounded-xl p-6 mb-8">
        <h2 class="font-semibold mb-4">
            <?= $recurrenteEdit ? 'Editar pago recurrente' : 'Nuevo pago recurrente' ?>
        </h2>
        <form method="POST" class="space-y-4">
            <?= csrf_field() ?>
            <input type="hidden" name="accion" value="<?= $recurrenteEdit ? 'actualizar' : 'crear' ?>">
            <?php if ($recurrenteEdit): ?>
                <input type="hidden" name="id" value="<?= (int)$recurrenteEdit['id'] ?>">
            <?php endif; ?>

            <div>
                <label class="block text-sm font-medium mb-1.5 text-slate-700">Descripción</label>
                <input type="text" name="descripcion" required maxlength="255"
                       placeholder="Ej. Spotify, Renta, Netflix"
                       value="<?= $recurrenteEdit ? e_attr((string)$recurrenteEdit['descripcion']) : '' ?>"
                       class="input-clean">
            </div>

            <div class="grid grid-cols-3 gap-4">
                <div>
                    <label class="block text-sm font-medium mb-1.5 text-slate-700">Categoría</label>
                    <select name="categoria_id" required class="input-clean">
                        <?php foreach ($categorias as $c): ?>
                            <option value="<?= (int)$c['id'] ?>"
                                <?= $recurrenteEdit && (int)$c['id'] === (int)$recurrenteEdit['categoria_id'] ? 'selected' : '' ?>>
                                <?= e($c['nombre']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="block text-sm font-medium mb-1.5 text-slate-700">Monto</label>
                    <div class="relative">
                        <span class="absolute left-3 top-1/2 -translate-y-1/2 text-slate-400">$</span>
                        <input type="number" step="0.01" min="0.01" max="99999999.99" name="monto" required
                               value="<?= $recurrenteEdit ? e_attr((string)$recurrenteEdit['monto']) : '' ?>"
                               class="input-clean pl-7">
                    </div>
                </div>
                <div>
                    <label class="block text-sm font-medium mb-1.5 text-slate-700">Día del mes</label>
                    <input type="number" min="1" max="31" name="dia_del_mes" required
                           value="<?= $recurrenteEdit ? (int)$recurrenteEdit['dia_del_mes'] : 1 ?>"
                           class="input-clean">
                </div>
            </div>

            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-medium mb-1.5 text-slate-700">Fecha inicio</label>
                    <input type="date" name="fecha_inicio" required
                           min="2020-01-01" max="2100-12-31"
                           value="<?= $recurrenteEdit ? e_attr((string)$recurrenteEdit['fecha_inicio']) : e_attr(date('Y-m-d')) ?>"
                           class="input-clean">
                </div>
                <div>
                    <label class="block text-sm font-medium mb-1.5 text-slate-700">Fecha fin <span class="text-slate-400 font-normal">— opcional</span></label>
                    <input type="date" name="fecha_fin"
                           min="2020-01-01" max="2100-12-31"
                           value="<?= $recurrenteEdit && $recurrenteEdit['fecha_fin'] ? e_attr((string)$recurrenteEdit['fecha_fin']) : '' ?>"
                           class="input-clean">
                </div>
            </div>

            <label class="inline-flex items-center gap-2 text-sm">
                <input type="checkbox" name="activo" value="1"
                       <?= !$recurrenteEdit || $recurrenteEdit['activo'] ? 'checked' : '' ?>
                       class="w-4 h-4 rounded border-slate-300">
                Activo
            </label>

            <div class="flex gap-3 pt-2">
                <button type="submit" class="btn-primary"><?= $recurrenteEdit ? 'Guardar cambios' : 'Crear pago recurrente' ?></button>
                <?php if ($recurrenteEdit): ?>
                    <a href="/recurrentes.php" class="btn-secondary">Cancelar</a>
                <?php endif; ?>
            </div>
        </form>
    </section>

    <?php if (!$recurrentes): ?>
        <p class="text-center text-slate-400 py-12">No tienes pagos recurrentes definidos.</p>
    <?php else: ?>
        <ul class="divide-y divide-slate-100">
            <?php foreach ($recurrentes as $r): ?>
                <li class="py-4 flex items-center justify-between gap-4 group <?= !$r['activo'] ? 'opacity-50' : '' ?>">
                    <div class="flex items-center gap-4 min-w-0 flex-1">
                        <div class="w-10 h-10 rounded-lg bg-slate-100 flex items-center justify-center text-sm font-semibold text-slate-600 shrink-0">
                            <?= (int)$r['dia_del_mes'] ?>
                        </div>
                        <div class="min-w-0 flex-1">
                            <div class="font-medium flex items-center gap-2">
                                <?= e((string)$r['descripcion']) ?>
                                <?php if (!$r['activo']): ?>
                                    <span class="badge text-rose-700 bg-rose-50">Pausado</span>
                                <?php endif; ?>
                            </div>
                            <div class="text-xs text-slate-400 mt-0.5 flex items-center gap-2">
                                <span class="badge"><?= e((string)$r['categoria']) ?></span>
                                <span>desde <?= e((string)$r['fecha_inicio']) ?></span>
                                <?php if ($r['fecha_fin']): ?>
                                    <span>· hasta <?= e((string)$r['fecha_fin']) ?></span>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    <div class="flex items-center gap-3 shrink-0">
                        <span class="font-semibold tabular-nums"><?= e(format_currency((float)$r['monto'])) ?></span>
                        <div class="flex items-center gap-1 opacity-60 group-hover:opacity-100 transition-opacity">
                            <a href="/recurrentes.php?editar=<?= (int)$r['id'] ?>"
                               class="text-xs px-2.5 py-1 rounded text-slate-500 hover:bg-slate-100 hover:text-slate-900">
                                Editar
                            </a>
                            <form method="POST" class="inline"
                                  onsubmit="return confirm('¿Eliminar este pago recurrente?');">
                                <?= csrf_field() ?>
                                <input type="hidden" name="accion" value="eliminar">
                                <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                                <button type="submit" class="text-xs px-2.5 py-1 rounded text-rose-600 hover:bg-rose-50">
                                    Eliminar
                                </button>
                            </form>
                        </div>
                    </div>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</div>
<?php require __DIR__ . '/../templates/footer.php'; ?>
