<?php
declare(strict_types=1);
require __DIR__ . '/../config/db.php';
require __DIR__ . '/../config/helpers.php';
require __DIR__ . '/../config/auth_check.php';

$uid  = (int)$_SESSION['user_id'];
$mes  = (int)date('n');
$anio = (int)date('Y');

// Balance del mes
$stmt = $pdo->prepare('
    SELECT
        (SELECT COALESCE(SUM(monto),0) FROM ingresos
         WHERE usuario_id = ? AND MONTH(fecha) = ? AND YEAR(fecha) = ?) AS total_ingresos,
        (SELECT COALESCE(SUM(monto),0) FROM gastos
         WHERE usuario_id = ? AND MONTH(fecha) = ? AND YEAR(fecha) = ?) AS total_gastos
');
$stmt->execute([$uid, $mes, $anio, $uid, $mes, $anio]);
$bal = $stmt->fetch();
$ingresos_mes = (float)$bal['total_ingresos'];
$gastos_mes   = (float)$bal['total_gastos'];
$balance      = $ingresos_mes - $gastos_mes;

// Distribución para donut
$stmt = $pdo->prepare('
    SELECT c.nombre AS categoria, SUM(g.monto) AS total
    FROM gastos g JOIN categorias c ON c.id = g.categoria_id
    WHERE g.usuario_id = ? AND MONTH(g.fecha) = ? AND YEAR(g.fecha) = ?
    GROUP BY c.id, c.nombre
    ORDER BY total DESC
');
$stmt->execute([$uid, $mes, $anio]);
$distribucion = $stmt->fetchAll();

// Metas
$stmt = $pdo->prepare('
    SELECT id, nombre, monto_objetivo, monto_actual, fecha_objetivo
    FROM metas_ahorro
    WHERE usuario_id = ? AND completada = FALSE
    ORDER BY fecha_objetivo IS NULL, fecha_objetivo ASC
    LIMIT 5
');
$stmt->execute([$uid]);
$metas = $stmt->fetchAll();

// Presupuestos
$stmt = $pdo->prepare('
    SELECT c.nombre, p.monto_limite,
           COALESCE(SUM(g.monto), 0) AS consumido
    FROM presupuestos p
    JOIN categorias c ON c.id = p.categoria_id
    LEFT JOIN gastos g
        ON g.categoria_id = p.categoria_id
       AND g.usuario_id   = p.usuario_id
       AND MONTH(g.fecha) = p.mes
       AND YEAR(g.fecha)  = p.anio
    WHERE p.usuario_id = ? AND p.mes = ? AND p.anio = ?
    GROUP BY p.id, c.nombre, p.monto_limite
    ORDER BY (COALESCE(SUM(g.monto),0) / p.monto_limite) DESC
');
$stmt->execute([$uid, $mes, $anio]);
$presupuestos = $stmt->fetchAll();

// Próximos recurrentes
$diaHoy  = (int)date('j');
$diasMes = (int)date('t');
$primerDelMes = sprintf('%04d-%02d-01', $anio, $mes);
$stmt = $pdo->prepare('
    SELECT gr.descripcion, gr.monto, gr.dia_del_mes, c.nombre AS categoria
    FROM gastos_recurrentes gr
    JOIN categorias c ON c.id = gr.categoria_id
    WHERE gr.usuario_id = ?
      AND gr.activo = TRUE
      AND gr.frecuencia = "mensual"
      AND gr.fecha_inicio <= LAST_DAY(?)
      AND (gr.fecha_fin IS NULL OR gr.fecha_fin >= ?)
      AND gr.dia_del_mes BETWEEN ? AND ?
    ORDER BY gr.dia_del_mes ASC
');
$stmt->execute([$uid, $primerDelMes, $primerDelMes, $diaHoy, $diasMes]);
$recurrentes = $stmt->fetchAll();

$pageTitle = 'Inicio';
require __DIR__ . '/../templates/header.php';
?>

<!-- HERO: balance cinematográfico -->
<section class="py-16 md:py-20 text-center">
    <p class="text-sm text-slate-500 mb-3">
        <?= $balance >= 0 ? 'Este mes vas ahorrando' : 'Este mes has gastado de más' ?>
    </p>
    <h1 class="hero-number text-6xl md:text-8xl <?= $balance >= 0 ? 'text-slate-900' : 'text-rose-600' ?>">
        <?= e(format_currency(abs($balance))) ?>
    </h1>
    <p class="mt-6 text-sm text-slate-500">
        <a href="/ingresos.php" class="hover:text-slate-900">
            Ingresos
            <span class="font-semibold text-emerald-700"><?= e(format_currency($ingresos_mes)) ?></span>
        </a>
        <span class="mx-2 text-slate-300">·</span>
        <a href="/consulta.php" class="hover:text-slate-900">
            Gastos
            <span class="font-semibold text-rose-700"><?= e(format_currency($gastos_mes)) ?></span>
        </a>
        <span class="mx-2 text-slate-300">·</span>
        <?= e(nombre_mes($mes) . ' ' . $anio) ?>
    </p>

    <!-- Acciones rápidas -->
    <div class="mt-8 flex items-center justify-center gap-3">
        <a href="/ingresos.php"
           class="inline-flex items-center gap-2 px-4 py-2 rounded-lg bg-emerald-600 text-white text-sm font-medium hover:bg-emerald-700 transition-colors">
            + Ingreso
        </a>
        <a href="/alta.php"
           class="inline-flex items-center gap-2 px-4 py-2 rounded-lg bg-slate-900 text-white text-sm font-medium hover:bg-slate-800 transition-colors">
            + Gasto
        </a>
    </div>
</section>

<!-- SECCIÓN: Distribución -->
<div class="section-divider"><span>Dónde va tu dinero</span></div>

<?php if ($distribucion): ?>
    <div class="grid md:grid-cols-2 gap-8 items-center">
        <div class="relative mx-auto" style="max-width: 320px;">
            <canvas id="donutCategorias" height="280"></canvas>
        </div>
        <ul class="space-y-3">
            <?php
            $totalDist = array_sum(array_map(fn($d) => (float)$d['total'], $distribucion));
            $colors = ['#6366f1', '#8b5cf6', '#ec4899', '#f59e0b', '#10b981', '#ef4444', '#3b82f6', '#6b7280'];
            foreach ($distribucion as $i => $d):
                $pct = $totalDist > 0 ? ((float)$d['total'] / $totalDist) * 100 : 0;
                $color = $colors[$i % count($colors)];
            ?>
                <li class="flex items-center justify-between text-sm">
                    <span class="flex items-center gap-3">
                        <span class="w-3 h-3 rounded-full" style="background: <?= e($color) ?>"></span>
                        <?= e((string)$d['categoria']) ?>
                    </span>
                    <span class="text-slate-500">
                        <?= e(format_currency((float)$d['total'])) ?>
                        <span class="text-slate-400 ml-2"><?= number_format($pct, 1) ?>%</span>
                    </span>
                </li>
            <?php endforeach; ?>
        </ul>
    </div>
<?php else: ?>
    <p class="text-center text-slate-400 py-8">Sin gastos este mes.
        <a href="/alta.php" class="text-slate-900 underline ml-1">Registra el primero →</a>
    </p>
<?php endif; ?>

<!-- SECCIÓN: Metas -->
<div class="section-divider">
    <span>Tus metas</span>
</div>
<div class="flex justify-end -mt-4 mb-2">
    <a href="/metas.php" class="text-xs text-indigo-600 hover:text-indigo-700 hover:underline">
        Gestionar metas →
    </a>
</div>

<?php if (!$metas): ?>
    <p class="text-center text-slate-400 py-8">No tienes metas activas.</p>
<?php else: ?>
    <ul class="divide-y divide-slate-100">
    <?php foreach ($metas as $m):
        $objetivo = (float)$m['monto_objetivo'];
        $actual   = (float)$m['monto_actual'];
        $pct      = $objetivo > 0 ? min(100, ($actual / $objetivo) * 100) : 0;
        $faltante = max(0.0, $objetivo - $actual);
    ?>
        <li class="py-5">
            <div class="flex items-baseline justify-between mb-2 flex-wrap gap-2">
                <span class="font-medium"><?= e((string)$m['nombre']) ?></span>
                <div class="flex items-center gap-3">
                    <?php if ($faltante > 0): ?>
                        <form method="POST" action="/metas.php"
                              data-max="<?= e_attr((string)$faltante) ?>"
                              data-meta="<?= e_attr((string)$m['nombre']) ?>"
                              onsubmit="return promptMonto(this, 'abonar');">
                            <?= csrf_field() ?>
                            <input type="hidden" name="accion" value="abonar">
                            <input type="hidden" name="id" value="<?= (int)$m['id'] ?>">
                            <input type="hidden" name="volver_a" value="inicio">
                            <input type="hidden" name="abono" value="">
                            <button type="submit"
                                    class="text-xs px-2.5 py-1 rounded bg-emerald-600 text-white hover:bg-emerald-700">
                                + Abonar
                            </button>
                        </form>
                    <?php endif; ?>
                    <span class="text-sm text-slate-500">
                        <span class="text-slate-900 font-semibold"><?= e(format_currency($actual)) ?></span>
                        de <?= e(format_currency($objetivo)) ?>
                    </span>
                </div>
            </div>
            <div class="bar"><div class="fill" style="width: <?= number_format($pct, 1) ?>%"></div></div>
            <div class="flex justify-between text-xs text-slate-400 mt-1.5">
                <span><?= number_format($pct, 1) ?>% completado</span>
                <?php if ($m['fecha_objetivo']): ?>
                    <span>Meta: <?= e((string)$m['fecha_objetivo']) ?></span>
                <?php endif; ?>
            </div>
            <?php if ($faltante <= 0): ?>
                <div class="mt-2 text-xs text-emerald-600 font-medium">Meta completada</div>
            <?php endif; ?>
        </li>
    <?php endforeach; ?>
    </ul>
<?php endif; ?>

<!-- SECCIÓN: Presupuestos -->
<div class="section-divider"><span>Tu presupuesto</span></div>
<div class="flex justify-end -mt-4 mb-2">
    <a href="/presupuestos.php" class="text-xs text-indigo-600 hover:text-indigo-700 hover:underline">
        Gestionar presupuestos →
    </a>
</div>

<?php if (!$presupuestos): ?>
    <p class="text-center text-slate-400 py-8">Sin presupuestos definidos este mes.</p>
<?php else: ?>
    <ul class="divide-y divide-slate-100">
    <?php foreach ($presupuestos as $p):
        $limite    = (float)$p['monto_limite'];
        $consumido = (float)$p['consumido'];
        $pct       = $limite > 0 ? ($consumido / $limite) * 100 : 0;
        $barColor  = $pct >= 100 ? 'background: #ef4444' : ($pct >= 80 ? 'background: #f59e0b' : 'background: linear-gradient(90deg, #6366f1, #8b5cf6)');
        $txtColor  = $pct >= 100 ? 'text-rose-600' : ($pct >= 80 ? 'text-amber-600' : 'text-slate-500');
    ?>
        <li class="py-5">
            <div class="flex items-baseline justify-between mb-2">
                <span class="font-medium"><?= e((string)$p['nombre']) ?></span>
                <span class="text-sm <?= $txtColor ?>">
                    <span class="font-semibold"><?= e(format_currency($consumido)) ?></span>
                    de <?= e(format_currency($limite)) ?>
                </span>
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

<!-- SECCIÓN: Próximos pagos -->
<div class="section-divider"><span>Próximos pagos</span></div>
<div class="flex justify-end -mt-4 mb-2">
    <a href="/recurrentes.php" class="text-xs text-indigo-600 hover:text-indigo-700 hover:underline">
        Gestionar pagos recurrentes →
    </a>
</div>

<?php if (!$recurrentes): ?>
    <p class="text-center text-slate-400 py-8">No hay pagos recurrentes pendientes este mes.</p>
<?php else: ?>
    <ul class="divide-y divide-slate-100">
    <?php foreach ($recurrentes as $r): ?>
        <li class="py-4 flex items-center justify-between">
            <div class="flex items-center gap-4">
                <div class="w-10 h-10 rounded-lg bg-slate-100 flex items-center justify-center text-sm font-semibold text-slate-600">
                    <?= (int)$r['dia_del_mes'] ?>
                </div>
                <div>
                    <div class="font-medium"><?= e((string)$r['descripcion']) ?></div>
                    <div class="text-xs text-slate-400"><?= e((string)$r['categoria']) ?></div>
                </div>
            </div>
            <span class="font-semibold tabular-nums"><?= e(format_currency((float)$r['monto'])) ?></span>
        </li>
    <?php endforeach; ?>
    </ul>
<?php endif; ?>

<?php if ($distribucion): ?>
<script>
    const ctx = document.getElementById('donutCategorias');
    new Chart(ctx, {
        type: 'doughnut',
        data: {
            labels: <?= json_encode(array_column($distribucion, 'categoria'), JSON_THROW_ON_ERROR) ?>,
            datasets: [{
                data: <?= json_encode(array_map(fn($d) => (float)$d['total'], $distribucion), JSON_THROW_ON_ERROR) ?>,
                backgroundColor: ['#6366f1','#8b5cf6','#ec4899','#f59e0b','#10b981','#ef4444','#3b82f6','#6b7280'],
                borderWidth: 0,
                hoverOffset: 8,
            }],
        },
        options: {
            cutout: '70%',
            plugins: { legend: { display: false } },
            animation: { animateScale: true, animateRotate: true }
        }
    });
</script>
<?php endif; ?>

<?php require __DIR__ . '/../templates/footer.php'; ?>
