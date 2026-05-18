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
        $fuente        = require_post_string('fuente', 100);
        $monto         = require_post_decimal('monto');
        $fecha         = require_post_date('fecha');
        $es_recurrente = isset($_POST['es_recurrente']) ? 1 : 0;
        $notas         = require_post_string('notas', 255, required: false);

        if ($accion === 'crear') {
            $stmt = $pdo->prepare('
                INSERT INTO ingresos (usuario_id, fuente, monto, fecha, es_recurrente, notas)
                VALUES (?, ?, ?, ?, ?, ?)
            ');
            $stmt->execute([$uid, $fuente, $monto, $fecha, $es_recurrente, $notas]);
            $_SESSION['_flash']['success'][] = 'Ingreso registrado.';
        } else {
            $id = require_post_int('id', 1);
            $upd = $pdo->prepare('
                UPDATE ingresos
                SET fuente = ?, monto = ?, fecha = ?, es_recurrente = ?, notas = ?
                WHERE id = ? AND usuario_id = ?
            ');
            $upd->execute([$fuente, $monto, $fecha, $es_recurrente, $notas, $id, $uid]);
            $_SESSION['_flash']['success'][] = 'Ingreso actualizado.';
        }
    }
    elseif ($accion === 'eliminar') {
        $id = require_post_int('id', 1);
        $del = $pdo->prepare('DELETE FROM ingresos WHERE id = ? AND usuario_id = ?');
        $del->execute([$id, $uid]);
        $_SESSION['_flash']['success'][] = 'Ingreso eliminado.';
    }
    else {
        die_400('Acción inválida');
    }

    header('Location: /ingresos.php');
    exit;
}

$mes  = filter_input(INPUT_GET, 'mes', FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1, 'max_range' => 12]]) ?: (int)date('n');
$anio = filter_input(INPUT_GET, 'anio', FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 2020, 'max_range' => 2100]]) ?: (int)date('Y');

$stmt = $pdo->prepare('
    SELECT id, fuente, monto, fecha, es_recurrente, notas
    FROM ingresos
    WHERE usuario_id = ? AND MONTH(fecha) = ? AND YEAR(fecha) = ?
    ORDER BY fecha DESC, id DESC
');
$stmt->execute([$uid, $mes, $anio]);
$ingresos = $stmt->fetchAll();

$total = array_sum(array_map(fn($i) => (float)$i['monto'], $ingresos));

$editId = filter_input(INPUT_GET, 'editar', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 0;
$ingresoEdit = null;
if ($editId > 0) {
    $stmtE = $pdo->prepare('SELECT * FROM ingresos WHERE id = ? AND usuario_id = ?');
    $stmtE->execute([$editId, $uid]);
    $ingresoEdit = $stmtE->fetch() ?: null;
}

$pageTitle = 'Ingresos';
require __DIR__ . '/../templates/header.php';
?>
<div class="py-12">
    <header class="mb-8 flex items-end justify-between flex-wrap gap-4">
        <div>
            <a href="/index.php" class="text-sm text-slate-500 hover:text-slate-900 inline-flex items-center gap-1 mb-3">← Volver al inicio</a>
            <h1 class="text-3xl font-bold tracking-tight">Ingresos</h1>
            <p class="text-sm text-slate-500 mt-1"><?= e(nombre_mes($mes) . ' ' . $anio) ?> · <?= count($ingresos) ?> registro<?= count($ingresos) !== 1 ? 's' : '' ?></p>
        </div>
        <div class="text-right">
            <div class="text-xs text-slate-500">Total del periodo</div>
            <div class="hero-number text-3xl mt-1 text-emerald-600"><?= e(format_currency($total)) ?></div>
        </div>
    </header>

    <form method="GET" class="flex flex-wrap gap-2 mb-6 pb-6 border-b border-slate-100 text-sm">
        <select name="mes" class="input-clean w-auto">
            <?php for ($m = 1; $m <= 12; $m++): ?>
                <option value="<?= $m ?>" <?= $m === $mes ? 'selected' : '' ?>><?= e(nombre_mes($m)) ?></option>
            <?php endfor; ?>
        </select>
        <select name="anio" class="input-clean w-auto">
            <?php for ($a = 2024; $a <= (int)date('Y') + 1; $a++): ?>
                <option value="<?= $a ?>" <?= $a === $anio ? 'selected' : '' ?>><?= $a ?></option>
            <?php endfor; ?>
        </select>
        <button class="btn-primary">Filtrar</button>
    </form>

    <section class="bg-slate-50 rounded-xl p-6 mb-8">
        <h2 class="font-semibold mb-4">
            <?= $ingresoEdit ? 'Editar ingreso' : 'Nuevo ingreso' ?>
        </h2>
        <form method="POST" class="space-y-4">
            <?= csrf_field() ?>
            <input type="hidden" name="accion" value="<?= $ingresoEdit ? 'actualizar' : 'crear' ?>">
            <?php if ($ingresoEdit): ?>
                <input type="hidden" name="id" value="<?= (int)$ingresoEdit['id'] ?>">
            <?php endif; ?>

            <div class="grid grid-cols-3 gap-4">
                <div class="col-span-2">
                    <label class="block text-sm font-medium mb-1.5 text-slate-700">Fuente</label>
                    <input type="text" name="fuente" required maxlength="100"
                           placeholder="Ej. Mesada, Beca, Freelance"
                           value="<?= $ingresoEdit ? e_attr((string)$ingresoEdit['fuente']) : '' ?>"
                           class="input-clean">
                </div>
                <div>
                    <label class="block text-sm font-medium mb-1.5 text-slate-700">Monto</label>
                    <div class="relative">
                        <span class="absolute left-3 top-1/2 -translate-y-1/2 text-slate-400">$</span>
                        <input type="number" step="0.01" min="0.01" max="99999999.99" name="monto" required
                               value="<?= $ingresoEdit ? e_attr((string)$ingresoEdit['monto']) : '' ?>"
                               class="input-clean pl-7">
                    </div>
                </div>
            </div>

            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-medium mb-1.5 text-slate-700">Fecha</label>
                    <input type="date" name="fecha" required
                           min="2020-01-01" max="2100-12-31"
                           value="<?= $ingresoEdit ? e_attr((string)$ingresoEdit['fecha']) : e_attr(date('Y-m-d')) ?>"
                           class="input-clean">
                </div>
                <div class="flex items-end pb-2">
                    <label class="inline-flex items-center gap-2 text-sm">
                        <input type="checkbox" name="es_recurrente" value="1"
                               <?= $ingresoEdit && $ingresoEdit['es_recurrente'] ? 'checked' : '' ?>
                               class="w-4 h-4 rounded border-slate-300">
                        Es un ingreso recurrente (mensual)
                    </label>
                </div>
            </div>

            <div>
                <label class="block text-sm font-medium mb-1.5 text-slate-700">Notas <span class="text-slate-400 font-normal">— opcional</span></label>
                <input type="text" name="notas" maxlength="255"
                       value="<?= $ingresoEdit ? e_attr((string)($ingresoEdit['notas'] ?? '')) : '' ?>"
                       class="input-clean">
            </div>

            <div class="flex gap-3 pt-2">
                <button type="submit" class="btn-primary"><?= $ingresoEdit ? 'Guardar cambios' : 'Registrar ingreso' ?></button>
                <?php if ($ingresoEdit): ?>
                    <a href="/ingresos.php" class="btn-secondary">Cancelar</a>
                <?php endif; ?>
            </div>
        </form>
    </section>

    <?php if (!$ingresos): ?>
        <p class="text-center text-slate-400 py-12">No hay ingresos en este periodo.</p>
    <?php else: ?>
        <ul class="divide-y divide-slate-100">
            <?php foreach ($ingresos as $i): ?>
                <li class="py-4 flex items-center justify-between gap-4 group">
                    <div class="flex items-center gap-4 min-w-0 flex-1">
                        <div class="text-xs text-slate-400 tabular-nums w-20 shrink-0">
                            <?= e((string)$i['fecha']) ?>
                        </div>
                        <div class="min-w-0 flex-1">
                            <div class="font-medium flex items-center gap-2">
                                <?= e((string)$i['fuente']) ?>
                                <?php if ($i['es_recurrente']): ?>
                                    <span class="badge text-indigo-700 bg-indigo-50">Recurrente</span>
                                <?php endif; ?>
                            </div>
                            <?php if (!empty($i['notas'])): ?>
                                <div class="text-xs text-slate-400 mt-0.5"><?= e((string)$i['notas']) ?></div>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="flex items-center gap-3 shrink-0">
                        <span class="font-semibold tabular-nums text-emerald-700">+<?= e(format_currency((float)$i['monto'])) ?></span>
                        <div class="flex items-center gap-1 opacity-60 group-hover:opacity-100 transition-opacity">
                            <a href="/ingresos.php?editar=<?= (int)$i['id'] ?>"
                               class="text-xs px-2.5 py-1 rounded text-slate-500 hover:bg-slate-100 hover:text-slate-900">
                                Editar
                            </a>
                            <form method="POST" class="inline"
                                  onsubmit="return confirm('¿Eliminar este ingreso?');">
                                <?= csrf_field() ?>
                                <input type="hidden" name="accion" value="eliminar">
                                <input type="hidden" name="id" value="<?= (int)$i['id'] ?>">
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
