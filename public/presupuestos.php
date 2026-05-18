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

    if ($accion === 'crear' || $accion === 'actualizar') {
        $categoria_id = require_post_int('categoria_id', 1);
        $monto_limite = require_post_decimal('monto_limite');
        $mes  = require_post_int('mes',  1, 12);
        $anio = require_post_int('anio', 2020, 2100);

        if (!categoria_pertenece_al_usuario($pdo, $categoria_id, $uid)) {
            die_400('Categoría inválida');
        }

        if ($accion === 'crear') {
            try {
                $stmt = $pdo->prepare('
                    INSERT INTO presupuestos (usuario_id, categoria_id, monto_limite, mes, anio)
                    VALUES (?, ?, ?, ?, ?)
                ');
                $stmt->execute([$uid, $categoria_id, $monto_limite, $mes, $anio]);
                $_SESSION['_flash']['success'][] = 'Presupuesto creado.';
            } catch (PDOException $e) {
                if ($e->getCode() === '23000') {
                    $_SESSION['_flash']['error'][] = 'Ya existe un presupuesto para esa categoría en ese mes.';
                } else {
                    throw $e;
                }
            }
        } else {
            $id = require_post_int('id', 1);
            $upd = $pdo->prepare('
                UPDATE presupuestos
                SET categoria_id = ?, monto_limite = ?, mes = ?, anio = ?
                WHERE id = ? AND usuario_id = ?
            ');
            $upd->execute([$categoria_id, $monto_limite, $mes, $anio, $id, $uid]);
            $_SESSION['_flash']['success'][] = 'Presupuesto actualizado.';
        }
    }
    elseif ($accion === 'eliminar') {
        $id = require_post_int('id', 1);
        $del = $pdo->prepare('DELETE FROM presupuestos WHERE id = ? AND usuario_id = ?');
        $del->execute([$id, $uid]);
        $_SESSION['_flash']['success'][] = 'Presupuesto eliminado.';
    }
    else {
        die_400('Acción inválida');
    }

    header('Location: /presupuestos.php');
    exit;
}

// Categorías disponibles
$catStmt = $pdo->prepare('
    SELECT id, nombre FROM categorias
    WHERE usuario_id IS NULL OR usuario_id = ?
    ORDER BY usuario_id IS NULL DESC, nombre
');
$catStmt->execute([$uid]);
$categorias = $catStmt->fetchAll();

// Presupuestos del usuario con consumo agregado
$stmt = $pdo->prepare('
    SELECT p.id, p.categoria_id, p.monto_limite, p.mes, p.anio,
           c.nombre AS categoria,
           COALESCE(SUM(g.monto), 0) AS consumido
    FROM presupuestos p
    JOIN categorias c ON c.id = p.categoria_id
    LEFT JOIN gastos g
        ON g.categoria_id = p.categoria_id
       AND g.usuario_id   = p.usuario_id
       AND MONTH(g.fecha) = p.mes
       AND YEAR(g.fecha)  = p.anio
    WHERE p.usuario_id = ?
    GROUP BY p.id, p.categoria_id, p.monto_limite, p.mes, p.anio, c.nombre
    ORDER BY p.anio DESC, p.mes DESC, c.nombre
');
$stmt->execute([$uid]);
$presupuestos = $stmt->fetchAll();

// Prefill si viene ?editar
$editId = filter_input(INPUT_GET, 'editar', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 0;
$presupuestoEdit = null;
if ($editId > 0) {
    foreach ($presupuestos as $p) {
        if ((int)$p['id'] === $editId) { $presupuestoEdit = $p; break; }
    }
}

$pageTitle = 'Presupuestos';
require __DIR__ . '/../templates/header.php';
?>
<div class="py-12">
    <header class="mb-8">
        <a href="/index.php" class="text-sm text-slate-500 hover:text-slate-900 inline-flex items-center gap-1 mb-3">← Volver al inicio</a>
        <h1 class="text-3xl font-bold tracking-tight">Presupuestos</h1>
        <p class="text-sm text-slate-500 mt-1">Define límites mensuales por categoría.</p>
    </header>

    <section class="bg-slate-50 rounded-xl p-6 mb-8">
        <h2 class="font-semibold mb-4">
            <?= $presupuestoEdit ? 'Editar presupuesto' : 'Nuevo presupuesto' ?>
        </h2>
        <form method="POST" class="space-y-4">
            <?= csrf_field() ?>
            <input type="hidden" name="accion" value="<?= $presupuestoEdit ? 'actualizar' : 'crear' ?>">
            <?php if ($presupuestoEdit): ?>
                <input type="hidden" name="id" value="<?= (int)$presupuestoEdit['id'] ?>">
            <?php endif; ?>

            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-medium mb-1.5 text-slate-700">Categoría</label>
                    <select name="categoria_id" required class="input-clean">
                        <?php foreach ($categorias as $c): ?>
                            <option value="<?= (int)$c['id'] ?>"
                                <?= $presupuestoEdit && (int)$c['id'] === (int)$presupuestoEdit['categoria_id'] ? 'selected' : '' ?>>
                                <?= e($c['nombre']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="block text-sm font-medium mb-1.5 text-slate-700">Monto límite</label>
                    <div class="relative">
                        <span class="absolute left-3 top-1/2 -translate-y-1/2 text-slate-400">$</span>
                        <input type="number" step="0.01" min="0.01" max="99999999.99" name="monto_limite" required
                               value="<?= $presupuestoEdit ? e_attr((string)$presupuestoEdit['monto_limite']) : '' ?>"
                               class="input-clean pl-7">
                    </div>
                </div>
            </div>

            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-medium mb-1.5 text-slate-700">Mes</label>
                    <select name="mes" required class="input-clean">
                        <?php $mesPre = $presupuestoEdit ? (int)$presupuestoEdit['mes'] : (int)date('n');
                        for ($m = 1; $m <= 12; $m++): ?>
                            <option value="<?= $m ?>" <?= $m === $mesPre ? 'selected' : '' ?>>
                                <?= e(nombre_mes($m)) ?>
                            </option>
                        <?php endfor; ?>
                    </select>
                </div>
                <div>
                    <label class="block text-sm font-medium mb-1.5 text-slate-700">Año</label>
                    <select name="anio" required class="input-clean">
                        <?php $anioPre = $presupuestoEdit ? (int)$presupuestoEdit['anio'] : (int)date('Y');
                        for ($a = 2024; $a <= (int)date('Y') + 2; $a++): ?>
                            <option value="<?= $a ?>" <?= $a === $anioPre ? 'selected' : '' ?>><?= $a ?></option>
                        <?php endfor; ?>
                    </select>
                </div>
            </div>

            <div class="flex gap-3 pt-2">
                <button type="submit" class="btn-primary"><?= $presupuestoEdit ? 'Guardar cambios' : 'Crear presupuesto' ?></button>
                <?php if ($presupuestoEdit): ?>
                    <a href="/presupuestos.php" class="btn-secondary">Cancelar</a>
                <?php endif; ?>
            </div>
        </form>
    </section>

    <?php if (!$presupuestos): ?>
        <p class="text-center text-slate-400 py-12">No tienes presupuestos definidos.</p>
    <?php else: ?>
        <ul class="divide-y divide-slate-100">
            <?php foreach ($presupuestos as $p):
                $limite    = (float)$p['monto_limite'];
                $consumido = (float)$p['consumido'];
                $pct       = $limite > 0 ? ($consumido / $limite) * 100 : 0;
                $barColor  = $pct >= 100 ? 'background: #ef4444' : ($pct >= 80 ? 'background: #f59e0b' : 'background: linear-gradient(90deg, #6366f1, #8b5cf6)');
                $txtColor  = $pct >= 100 ? 'text-rose-600' : ($pct >= 80 ? 'text-amber-600' : 'text-slate-500');
            ?>
                <li class="py-5 group">
                    <div class="flex items-baseline justify-between mb-2">
                        <div class="flex items-center gap-2">
                            <span class="font-medium"><?= e((string)$p['categoria']) ?></span>
                            <span class="badge"><?= e(nombre_mes((int)$p['mes']) . ' ' . $p['anio']) ?></span>
                        </div>
                        <div class="flex items-center gap-3">
                            <span class="text-sm <?= $txtColor ?>">
                                <span class="font-semibold"><?= e(format_currency($consumido)) ?></span>
                                de <?= e(format_currency($limite)) ?>
                            </span>
                            <div class="flex items-center gap-1 opacity-60 group-hover:opacity-100 transition-opacity">
                                <a href="/presupuestos.php?editar=<?= (int)$p['id'] ?>"
                                   class="text-xs px-2.5 py-1 rounded text-slate-500 hover:bg-slate-100 hover:text-slate-900">
                                    Editar
                                </a>
                                <form method="POST" class="inline"
                                      onsubmit="return confirm('¿Eliminar este presupuesto?');">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="accion" value="eliminar">
                                    <input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
                                    <button type="submit" class="text-xs px-2.5 py-1 rounded text-rose-600 hover:bg-rose-50">
                                        Eliminar
                                    </button>
                                </form>
                            </div>
                        </div>
                    </div>
                    <div class="bar">
                        <div class="h-full transition-all" style="width: <?= number_format(min($pct, 100), 1) ?>%; <?= $barColor ?>"></div>
                    </div>
                    <div class="text-xs text-slate-400 mt-1.5">
                        <?= number_format($pct, 1) ?>% consumido
                        <?php if ($pct >= 100): ?> · <span class="text-rose-600">presupuesto excedido</span><?php endif; ?>
                    </div>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</div>
<?php require __DIR__ . '/../templates/footer.php'; ?>
