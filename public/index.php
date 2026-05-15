<?php
declare(strict_types=1);
require __DIR__ . '/../config/db.php';
require __DIR__ . '/../config/helpers.php';
require __DIR__ . '/../config/auth_check.php';

$uid  = (int)$_SESSION['user_id'];
$mes  = (int)date('n');
$anio = (int)date('Y');

// ============ WIDGET 1: Balance del mes ============
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

// ============ WIDGET 2: Metas de ahorro ============
$stmt = $pdo->prepare('
    SELECT id, nombre, monto_objetivo, monto_actual, fecha_objetivo
    FROM metas_ahorro
    WHERE usuario_id = ? AND completada = FALSE
    ORDER BY fecha_objetivo IS NULL, fecha_objetivo ASC
    LIMIT 5
');
$stmt->execute([$uid]);
$metas = $stmt->fetchAll();

// ============ WIDGET 3: Presupuestos por categoría (top 3 por uso) ============
$stmt = $pdo->prepare('
    SELECT
        c.nombre,
        p.monto_limite,
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
    LIMIT 3
');
$stmt->execute([$uid, $mes, $anio]);
$presupuestos = $stmt->fetchAll();

// ============ WIDGET 4: Próximos gastos recurrentes ============
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

// ============ Datos para gráfica donut: gastos por categoría ============
$stmt = $pdo->prepare('
    SELECT c.nombre AS categoria, SUM(g.monto) AS total
    FROM gastos g JOIN categorias c ON c.id = g.categoria_id
    WHERE g.usuario_id = ? AND MONTH(g.fecha) = ? AND YEAR(g.fecha) = ?
    GROUP BY c.id, c.nombre
    ORDER BY total DESC
');
$stmt->execute([$uid, $mes, $anio]);
$distribucion = $stmt->fetchAll();

$pageTitle = 'Dashboard';
require __DIR__ . '/../templates/header.php';
?>

<!-- Balance grande -->
<div class="grid md:grid-cols-3 gap-4 mb-4">
    <div class="md:col-span-2 bg-white rounded-xl shadow border border-slate-200 p-6">
        <p class="text-sm text-slate-500">Balance de <?= e(nombre_mes($mes) . ' ' . $anio) ?></p>
        <p class="big-number <?= $balance >= 0 ? 'text-emerald-600' : 'text-rose-600' ?> mt-1">
            <?= e(format_currency($balance)) ?>
        </p>
        <p class="text-xs text-slate-500 mt-2">
            Ingresos: <strong class="text-emerald-700"><?= e(format_currency($ingresos_mes)) ?></strong>
            · Gastos: <strong class="text-rose-700"><?= e(format_currency($gastos_mes)) ?></strong>
        </p>
    </div>

    <div class="bg-white rounded-xl shadow border border-slate-200 p-6">
        <p class="text-sm font-medium mb-2">Distribución</p>
        <?php if ($distribucion): ?>
            <canvas id="donutCategorias" height="180"></canvas>
        <?php else: ?>
            <p class="text-sm text-slate-500">Sin gastos este mes.</p>
        <?php endif; ?>
    </div>
</div>

<div class="grid md:grid-cols-2 gap-4">
    <!-- Metas -->
    <section class="bg-white rounded-xl shadow border border-slate-200 p-6">
        <h3 class="font-bold mb-3">Metas de ahorro</h3>
        <?php if (!$metas): ?>
            <p class="text-sm text-slate-500">Sin metas activas.</p>
        <?php else: foreach ($metas as $m):
            $objetivo = (float)$m['monto_objetivo'];
            $actual   = (float)$m['monto_actual'];
            $pct      = $objetivo > 0 ? min(100, ($actual / $objetivo) * 100) : 0;
        ?>
            <div class="mb-3">
                <div class="flex justify-between text-sm">
                    <strong><?= e((string)$m['nombre']) ?></strong>
                    <span class="text-slate-500"><?= number_format($pct, 1) ?>%</span>
                </div>
                <div class="bar my-1"><div class="fill" style="width: <?= number_format($pct, 1) ?>%"></div></div>
                <small class="text-slate-500">
                    <?= e(format_currency($actual)) ?> / <?= e(format_currency($objetivo)) ?>
                    <?php if ($m['fecha_objetivo']): ?>
                        · meta: <?= e((string)$m['fecha_objetivo']) ?>
                    <?php endif; ?>
                </small>
            </div>
        <?php endforeach; endif; ?>
    </section>

    <!-- Presupuestos -->
    <section class="bg-white rounded-xl shadow border border-slate-200 p-6">
        <h3 class="font-bold mb-3">Presupuesto del mes</h3>
        <?php if (!$presupuestos): ?>
            <p class="text-sm text-slate-500">Sin presupuestos definidos para este mes.</p>
        <?php else: foreach ($presupuestos as $p):
            $limite    = (float)$p['monto_limite'];
            $consumido = (float)$p['consumido'];
            $pct       = $limite > 0 ? ($consumido / $limite) * 100 : 0;
            $color     = $pct >= 100 ? 'bg-rose-500' : ($pct >= 80 ? 'bg-amber-500' : 'bg-emerald-500');
            $txt       = $pct >= 100 ? 'text-rose-700' : ($pct >= 80 ? 'text-amber-700' : 'text-emerald-700');
        ?>
            <div class="mb-3">
                <div class="flex justify-between text-sm">
                    <strong><?= e((string)$p['nombre']) ?></strong>
                    <span class="<?= $txt ?>"><?= number_format($pct, 1) ?>% consumido</span>
                </div>
                <div class="bar my-1">
                    <div class="<?= $color ?> h-full" style="width: <?= number_format(min($pct, 100), 1) ?>%"></div>
                </div>
                <small class="text-slate-500">
                    <?= e(format_currency($consumido)) ?> / <?= e(format_currency($limite)) ?>
                </small>
            </div>
        <?php endforeach; endif; ?>
    </section>

    <!-- Próximos recurrentes -->
    <section class="md:col-span-2 bg-white rounded-xl shadow border border-slate-200 p-6">
        <h3 class="font-bold mb-3">Próximos pagos recurrentes este mes</h3>
        <?php if (!$recurrentes): ?>
            <p class="text-sm text-slate-500">No hay pagos recurrentes pendientes este mes.</p>
        <?php else: ?>
            <ul class="divide-y divide-slate-100 text-sm">
            <?php foreach ($recurrentes as $r): ?>
                <li class="py-2 flex justify-between">
                    <span>
                        <span class="text-slate-500">Día <?= (int)$r['dia_del_mes'] ?></span>
                        ·
                        <strong><?= e((string)$r['descripcion']) ?></strong>
                        <span class="text-slate-400">(<?= e((string)$r['categoria']) ?>)</span>
                    </span>
                    <span class="font-medium"><?= e(format_currency((float)$r['monto'])) ?></span>
                </li>
            <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </section>
</div>

<?php if ($distribucion): ?>
<script>
    const labels = <?= json_encode(array_column($distribucion, 'categoria'), JSON_THROW_ON_ERROR) ?>;
    const data   = <?= json_encode(array_map(fn($d) => (float)$d['total'], $distribucion), JSON_THROW_ON_ERROR) ?>;
    new Chart(document.getElementById('donutCategorias'), {
        type: 'doughnut',
        data: {
            labels,
            datasets: [{
                data,
                backgroundColor: ['#6366f1','#8b5cf6','#ec4899','#f59e0b','#10b981','#ef4444','#3b82f6','#6b7280'],
            }],
        },
        options: { plugins: { legend: { position: 'bottom', labels: { font: { size: 10 } } } } }
    });
</script>
<?php endif; ?>

<?php require __DIR__ . '/../templates/footer.php'; ?>
