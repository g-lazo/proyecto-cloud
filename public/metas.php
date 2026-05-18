<?php
declare(strict_types=1);
require __DIR__ . '/../config/db.php';
require __DIR__ . '/../config/helpers.php';
require __DIR__ . '/../config/auth_check.php';

$uid = (int)$_SESSION['user_id'];

// Manejo de acciones POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify_or_die();
    $accion = $_POST['accion'] ?? '';

    if ($accion === 'crear') {
        $nombre        = require_post_string('nombre', 100);
        $monto_objetivo = require_post_decimal('monto_objetivo');
        $monto_actual   = require_post_decimal('monto_actual', 0.0);
        $fechaRaw       = trim((string)($_POST['fecha_objetivo'] ?? ''));
        $fecha_objetivo = $fechaRaw === '' ? null : require_post_date('fecha_objetivo');

        if ($monto_actual > $monto_objetivo) die_400('El monto actual no puede ser mayor al objetivo');

        $stmt = $pdo->prepare('
            INSERT INTO metas_ahorro (usuario_id, nombre, monto_objetivo, monto_actual, fecha_objetivo)
            VALUES (?, ?, ?, ?, ?)
        ');
        $stmt->execute([$uid, $nombre, $monto_objetivo, $monto_actual, $fecha_objetivo]);
        $_SESSION['_flash']['success'][] = 'Meta creada.';
    }
    elseif ($accion === 'actualizar') {
        $id            = require_post_int('id', 1);
        $nombre         = require_post_string('nombre', 100);
        $monto_objetivo = require_post_decimal('monto_objetivo');
        $monto_actual   = require_post_decimal('monto_actual', 0.0);
        $fechaRaw       = trim((string)($_POST['fecha_objetivo'] ?? ''));
        $fecha_objetivo = $fechaRaw === '' ? null : require_post_date('fecha_objetivo');
        $completada     = isset($_POST['completada']) ? 1 : 0;

        $upd = $pdo->prepare('
            UPDATE metas_ahorro
            SET nombre = ?, monto_objetivo = ?, monto_actual = ?, fecha_objetivo = ?, completada = ?
            WHERE id = ? AND usuario_id = ?
        ');
        $upd->execute([$nombre, $monto_objetivo, $monto_actual, $fecha_objetivo, $completada, $id, $uid]);
        $_SESSION['_flash']['success'][] = 'Meta actualizada.';
    }
    elseif ($accion === 'abonar') {
        $id     = require_post_int('id', 1);
        $abono  = require_post_decimal('abono');

        // Lock + SELECT con scope, luego UPDATE en una transacción simple
        $sel = $pdo->prepare('SELECT monto_actual, monto_objetivo FROM metas_ahorro WHERE id = ? AND usuario_id = ?');
        $sel->execute([$id, $uid]);
        $meta = $sel->fetch();
        if (!$meta) die_404();

        $nuevoMonto = (float)$meta['monto_actual'] + $abono;
        if ($nuevoMonto > (float)$meta['monto_objetivo']) {
            $nuevoMonto = (float)$meta['monto_objetivo'];
        }
        $completada = $nuevoMonto >= (float)$meta['monto_objetivo'] ? 1 : 0;

        $upd = $pdo->prepare('UPDATE metas_ahorro SET monto_actual = ?, completada = ? WHERE id = ? AND usuario_id = ?');
        $upd->execute([$nuevoMonto, $completada, $id, $uid]);

        $_SESSION['_flash']['success'][] = $completada
            ? 'Felicidades, completaste tu meta!'
            : 'Se abonaron ' . format_currency($abono) . ' a tu meta.';
    }
    elseif ($accion === 'retirar') {
        $id      = require_post_int('id', 1);
        $retiro  = require_post_decimal('retiro');

        $sel = $pdo->prepare('SELECT monto_actual FROM metas_ahorro WHERE id = ? AND usuario_id = ?');
        $sel->execute([$id, $uid]);
        $meta = $sel->fetch();
        if (!$meta) die_404();

        $nuevoMonto = max(0.0, (float)$meta['monto_actual'] - $retiro);

        $upd = $pdo->prepare('UPDATE metas_ahorro SET monto_actual = ?, completada = FALSE WHERE id = ? AND usuario_id = ?');
        $upd->execute([$nuevoMonto, $id, $uid]);

        $_SESSION['_flash']['success'][] = 'Se retiraron ' . format_currency($retiro) . ' de tu meta.';
    }
    elseif ($accion === 'eliminar') {
        $id = require_post_int('id', 1);
        $del = $pdo->prepare('DELETE FROM metas_ahorro WHERE id = ? AND usuario_id = ?');
        $del->execute([$id, $uid]);
        $_SESSION['_flash']['success'][] = 'Meta eliminada.';
    }
    else {
        die_400('Acción inválida');
    }

    // Redirige a destino whitelist-ed. Previene open redirect.
    $destinos = ['metas' => '/metas.php', 'inicio' => '/index.php'];
    $volverA  = $destinos[$_POST['volver_a'] ?? ''] ?? '/metas.php';
    header("Location: {$volverA}");
    exit;
}

// Lista de metas (activas primero, luego completadas)
$stmt = $pdo->prepare('
    SELECT id, nombre, monto_objetivo, monto_actual, fecha_objetivo, completada
    FROM metas_ahorro
    WHERE usuario_id = ?
    ORDER BY completada ASC, fecha_objetivo IS NULL, fecha_objetivo ASC
');
$stmt->execute([$uid]);
$metas = $stmt->fetchAll();

// Si viene ?editar=X, prefill el form con esa meta
$editId   = filter_input(INPUT_GET, 'editar', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 0;
$metaEdit = null;
if ($editId > 0) {
    foreach ($metas as $m) {
        if ((int)$m['id'] === $editId) { $metaEdit = $m; break; }
    }
}

$pageTitle = 'Metas de ahorro';
require __DIR__ . '/../templates/header.php';
?>
<div class="py-12">
    <header class="mb-8">
        <a href="/index.php" class="text-sm text-slate-500 hover:text-slate-900 inline-flex items-center gap-1 mb-3">← Volver al inicio</a>
        <h1 class="text-3xl font-bold tracking-tight">Metas de ahorro</h1>
        <p class="text-sm text-slate-500 mt-1">Define objetivos financieros y sigue tu progreso.</p>
    </header>

    <!-- Form crear / editar -->
    <section class="bg-slate-50 rounded-xl p-6 mb-8">
        <h2 class="font-semibold mb-4">
            <?= $metaEdit ? 'Editar meta' : 'Nueva meta' ?>
        </h2>
        <form method="POST" class="space-y-4">
            <?= csrf_field() ?>
            <input type="hidden" name="accion" value="<?= $metaEdit ? 'actualizar' : 'crear' ?>">
            <?php if ($metaEdit): ?>
                <input type="hidden" name="id" value="<?= (int)$metaEdit['id'] ?>">
            <?php endif; ?>

            <div>
                <label class="block text-sm font-medium mb-1.5 text-slate-700">Nombre</label>
                <input type="text" name="nombre" required maxlength="100"
                       placeholder="Ej. Viaje graduación"
                       value="<?= $metaEdit ? e_attr((string)$metaEdit['nombre']) : '' ?>"
                       class="input-clean">
            </div>

            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-medium mb-1.5 text-slate-700">Monto objetivo</label>
                    <div class="relative">
                        <span class="absolute left-3 top-1/2 -translate-y-1/2 text-slate-400">$</span>
                        <input type="number" step="0.01" min="0.01" max="99999999.99" name="monto_objetivo" required
                               value="<?= $metaEdit ? e_attr((string)$metaEdit['monto_objetivo']) : '' ?>"
                               class="input-clean pl-7">
                    </div>
                </div>
                <div>
                    <label class="block text-sm font-medium mb-1.5 text-slate-700">Monto actual</label>
                    <div class="relative">
                        <span class="absolute left-3 top-1/2 -translate-y-1/2 text-slate-400">$</span>
                        <input type="number" step="0.01" min="0" max="99999999.99" name="monto_actual"
                               value="<?= $metaEdit ? e_attr((string)$metaEdit['monto_actual']) : '0' ?>"
                               class="input-clean pl-7">
                    </div>
                </div>
            </div>

            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-medium mb-1.5 text-slate-700">Fecha objetivo <span class="text-slate-400 font-normal">— opcional</span></label>
                    <input type="date" name="fecha_objetivo"
                           min="2020-01-01" max="2100-12-31"
                           value="<?= $metaEdit && $metaEdit['fecha_objetivo'] ? e_attr((string)$metaEdit['fecha_objetivo']) : '' ?>"
                           class="input-clean">
                </div>
                <?php if ($metaEdit): ?>
                    <div class="flex items-end">
                        <label class="inline-flex items-center gap-2 text-sm">
                            <input type="checkbox" name="completada" value="1"
                                   <?= $metaEdit['completada'] ? 'checked' : '' ?>
                                   class="w-4 h-4 rounded border-slate-300">
                            Marcar como completada
                        </label>
                    </div>
                <?php endif; ?>
            </div>

            <div class="flex gap-3 pt-2">
                <button type="submit" class="btn-primary"><?= $metaEdit ? 'Guardar cambios' : 'Crear meta' ?></button>
                <?php if ($metaEdit): ?>
                    <a href="/metas.php" class="btn-secondary">Cancelar</a>
                <?php endif; ?>
            </div>
        </form>
    </section>

    <!-- Lista -->
    <?php if (!$metas): ?>
        <p class="text-center text-slate-400 py-12">No tienes metas registradas.</p>
    <?php else: ?>
        <ul class="divide-y divide-slate-100">
            <?php foreach ($metas as $m):
                $objetivo = (float)$m['monto_objetivo'];
                $actual   = (float)$m['monto_actual'];
                $pct      = $objetivo > 0 ? min(100, ($actual / $objetivo) * 100) : 0;
                $isDone   = (bool)$m['completada'];
            ?>
                <?php $faltante = max(0.0, $objetivo - $actual); ?>
                <li class="py-5 group <?= $isDone ? 'opacity-60' : '' ?>">
                    <div class="flex items-baseline justify-between mb-2 flex-wrap gap-2">
                        <div class="flex items-center gap-2">
                            <span class="font-medium"><?= e((string)$m['nombre']) ?></span>
                            <?php if ($isDone): ?>
                                <span class="badge text-emerald-700 bg-emerald-50">Completada</span>
                            <?php endif; ?>
                        </div>
                        <div class="flex items-center gap-3">
                            <!-- Acciones rápidas (a la izquierda del monto) -->
                            <?php if (!$isDone): ?>
                                <?php if ($faltante > 0): ?>
                                    <form method="POST"
                                          data-max="<?= e_attr((string)$faltante) ?>"
                                          data-meta="<?= e_attr((string)$m['nombre']) ?>"
                                          onsubmit="return promptMonto(this, 'abonar');">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="accion" value="abonar">
                                        <input type="hidden" name="id" value="<?= (int)$m['id'] ?>">
                                        <input type="hidden" name="abono" value="">
                                        <button type="submit"
                                                class="text-xs px-2.5 py-1 rounded bg-emerald-600 text-white hover:bg-emerald-700">
                                            + Abonar
                                        </button>
                                    </form>
                                <?php endif; ?>
                                <?php if ($actual > 0): ?>
                                    <form method="POST"
                                          data-max="<?= e_attr((string)$actual) ?>"
                                          data-meta="<?= e_attr((string)$m['nombre']) ?>"
                                          onsubmit="return promptMonto(this, 'retirar');">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="accion" value="retirar">
                                        <input type="hidden" name="id" value="<?= (int)$m['id'] ?>">
                                        <input type="hidden" name="retiro" value="">
                                        <button type="submit"
                                                class="text-xs px-2.5 py-1 rounded text-rose-600 hover:bg-rose-50">
                                            Retirar
                                        </button>
                                    </form>
                                <?php endif; ?>
                            <?php endif; ?>

                            <span class="text-sm text-slate-500">
                                <span class="text-slate-900 font-semibold"><?= e(format_currency($actual)) ?></span>
                                de <?= e(format_currency($objetivo)) ?>
                            </span>

                            <div class="flex items-center gap-1 opacity-60 group-hover:opacity-100 transition-opacity">
                                <a href="/metas.php?editar=<?= (int)$m['id'] ?>#editar"
                                   class="text-xs px-2.5 py-1 rounded text-slate-500 hover:bg-slate-100 hover:text-slate-900">
                                    Editar
                                </a>
                                <form method="POST" class="inline"
                                      onsubmit="return confirm('¿Eliminar la meta <?= e_attr((string)$m['nombre']) ?>?');">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="accion" value="eliminar">
                                    <input type="hidden" name="id" value="<?= (int)$m['id'] ?>">
                                    <button type="submit" class="text-xs px-2.5 py-1 rounded text-rose-600 hover:bg-rose-50">
                                        Eliminar
                                    </button>
                                </form>
                            </div>
                        </div>
                    </div>
                    <div class="bar"><div class="fill" style="width: <?= number_format($pct, 1) ?>%"></div></div>
                    <div class="flex justify-between text-xs text-slate-400 mt-1.5">
                        <span><?= number_format($pct, 1) ?>% completado</span>
                        <?php if ($m['fecha_objetivo']): ?>
                            <span>Meta: <?= e((string)$m['fecha_objetivo']) ?></span>
                        <?php endif; ?>
                    </div>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</div>
<?php require __DIR__ . '/../templates/footer.php'; ?>
