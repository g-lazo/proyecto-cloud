<?php
declare(strict_types=1);
require __DIR__ . '/../config/db.php';
require __DIR__ . '/../config/helpers.php';
require __DIR__ . '/../config/auth_check.php';

$uid       = (int)$_SESSION['user_id'];
$resultado = null;
$error     = null;
$mes       = (int)date('n');
$anio      = (int)date('Y');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify_or_die();
    $mes  = require_post_int('mes',  1, 12);
    $anio = require_post_int('anio', 2020, 2100);

    // Gastos del mes actual
    $stmt = $pdo->prepare('
        SELECT g.id, g.monto, g.descripcion, g.fecha, c.nombre AS categoria
        FROM gastos g
        JOIN categorias c ON c.id = g.categoria_id
        WHERE g.usuario_id = ? AND MONTH(g.fecha) = ? AND YEAR(g.fecha) = ?
    ');
    $stmt->execute([$uid, $mes, $anio]);
    $gastos = $stmt->fetchAll();

    // Gastos del mes anterior (para comparación)
    $mesAnt  = $mes === 1 ? 12 : $mes - 1;
    $anioAnt = $mes === 1 ? $anio - 1 : $anio;
    $stmt = $pdo->prepare('
        SELECT g.monto, c.nombre AS categoria
        FROM gastos g
        JOIN categorias c ON c.id = g.categoria_id
        WHERE g.usuario_id = ? AND MONTH(g.fecha) = ? AND YEAR(g.fecha) = ?
    ');
    $stmt->execute([$uid, $mesAnt, $anioAnt]);
    $gastos_anteriores = $stmt->fetchAll();

    // Ingresos del mes
    $stmt = $pdo->prepare('
        SELECT COALESCE(SUM(monto), 0) FROM ingresos
        WHERE usuario_id = ? AND MONTH(fecha) = ? AND YEAR(fecha) = ?
    ');
    $stmt->execute([$uid, $mes, $anio]);
    $ingresos_mes = (float)$stmt->fetchColumn();

    // Presupuestos del periodo
    $stmt = $pdo->prepare('
        SELECT c.nombre AS categoria, p.monto_limite,
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
    ');
    $stmt->execute([$uid, $mes, $anio]);
    $presupuestos = $stmt->fetchAll();

    // Metas no completadas
    $stmt = $pdo->prepare('
        SELECT nombre, monto_objetivo, monto_actual, fecha_objetivo
        FROM metas_ahorro
        WHERE usuario_id = ? AND completada = FALSE
    ');
    $stmt->execute([$uid]);
    $metas = $stmt->fetchAll();

    // Recurrentes activos (con categoría para clasificar suscripciones)
    $stmt = $pdo->prepare('
        SELECT gr.descripcion, gr.monto, gr.dia_del_mes, c.nombre AS categoria
        FROM gastos_recurrentes gr
        JOIN categorias c ON c.id = gr.categoria_id
        WHERE gr.usuario_id = ? AND gr.activo = TRUE
    ');
    $stmt->execute([$uid]);
    $recurrentes = $stmt->fetchAll();

    $payload = [
        'gastos'            => $gastos,
        'gastos_anteriores' => $gastos_anteriores,
        'ingresos'          => $ingresos_mes,
        'presupuestos'      => $presupuestos,
        'metas'             => $metas,
        'recurrentes'       => $recurrentes,
    ];
    $lambdaFn = getenv('LAMBDA_FUNCTION_NAME') ?: 'MiFuncionAnalisisFinanciero';

    try {
        if ($lambdaFn === '') {
            require_once __DIR__ . '/../config/analisis_local.php';
            $resultado = analizar_gastos($payload, $mes, $anio);
        } else {
            require '/var/www/html/vendor/autoload.php';
            $lambda = new Aws\Lambda\LambdaClient([
                'version' => 'latest',
                'region'  => getenv('AWS_REGION') ?: 'us-east-2',
                'credentials' => [
                    'key'    => getenv('AWS_ACCESS_KEY_ID'),
                    'secret' => getenv('AWS_SECRET_ACCESS_KEY'),
                ],
            ]);
            $res = $lambda->invoke([
                'FunctionName'   => $lambdaFn,
                'InvocationType' => 'RequestResponse',
                'Payload'        => json_encode(array_merge($payload, [
                    'mes' => $mes, 'anio' => $anio,
                ]), JSON_THROW_ON_ERROR),
            ]);
            $body = json_decode((string)$res['Payload'], true, flags: JSON_THROW_ON_ERROR);
            $resultado = isset($body['body']) && is_string($body['body'])
                ? json_decode($body['body'], true, flags: JSON_THROW_ON_ERROR)
                : $body;
        }
    } catch (Throwable $e) {
        echo "<div style='color:red; background:#ffcccc; pading:10px; border:1px solid red;'>Error detected: " . htmlspecialchars($e->getMessage()) . "</div>";
        error_log('Analisis: ' . $e->getMessage());
        $error = 'No fue posible procesar el análisis';
  }
}

$pageTitle = 'Análisis';
require __DIR__ . '/../templates/header.php';
?>
<div class="py-12">
    <header class="mb-8">
        <h1 class="text-3xl font-bold tracking-tight">Análisis financiero</h1>
        <p class="text-sm text-slate-500 mt-1">
            Procesa tus gastos con una función serverless
            <?= getenv('LAMBDA_FUNCTION_NAME')
                ? '<span class="text-emerald-600">(AWS Lambda)</span>'
                : '<span class="text-slate-400">(modo local · fase 1)</span>' ?>
        </p>
    </header>

    <form method="POST" class="flex flex-wrap items-end gap-3 pb-8 mb-8 border-b border-slate-100">
        <?= csrf_field() ?>
        <div>
            <label class="block text-xs font-medium mb-1.5 text-slate-500">Mes</label>
            <select name="mes" class="input-clean w-auto">
                <?php for ($m = 1; $m <= 12; $m++): ?>
                    <option value="<?= $m ?>" <?= $m === $mes ? 'selected' : '' ?>><?= e(nombre_mes($m)) ?></option>
                <?php endfor; ?>
            </select>
        </div>
        <div>
            <label class="block text-xs font-medium mb-1.5 text-slate-500">Año</label>
            <select name="anio" class="input-clean w-auto">
                <?php for ($a = 2024; $a <= (int)date('Y') + 1; $a++): ?>
                    <option value="<?= $a ?>" <?= $a === $anio ? 'selected' : '' ?>><?= $a ?></option>
                <?php endfor; ?>
            </select>
        </div>
        <button class="btn-primary">Analizar</button>
    </form>

    <?php if ($error): ?>
        <div class="px-4 py-3 border border-rose-100 bg-rose-50 text-rose-900 rounded-lg"><?= e($error) ?></div>
    <?php endif; ?>

    <?php if ($resultado): ?>
        <?php if (!empty($resultado['mensaje'])): ?>
            <p class="text-center text-slate-400 py-12"><?= e((string)$resultado['mensaje']) ?></p>
        <?php else:
            $m  = $resultado['metricas'] ?? [];
            $cmp = $resultado['comparacion'] ?? null;
            $ahorro = $resultado['ahorro'] ?? null;
            $quiebra = $resultado['quiebra'] ?? null;
            $regla = $resultado['regla_50_30_20'] ?? null;
            $sinPresupuesto = $resultado['sin_presupuesto'] ?? [];
            $metasFact = $resultado['metas_factibilidad'] ?? [];
        ?>

            <!-- KPIs grandes -->
            <div class="grid md:grid-cols-3 gap-8 mb-12">
                <div>
                    <p class="text-xs text-slate-500 uppercase tracking-wider mb-2">Total gastado</p>
                    <p class="hero-number text-4xl text-slate-900">
                        <?= e(format_currency((float)($m['total'] ?? 0))) ?>
                    </p>
                    <p class="text-xs text-slate-400 mt-1"><?= (int)($m['numero_gastos'] ?? 0) ?> movimientos</p>
                </div>
                <div>
                    <p class="text-xs text-slate-500 uppercase tracking-wider mb-2">Promedio por gasto</p>
                    <p class="hero-number text-4xl text-slate-900">
                        <?= e(format_currency((float)($m['promedio_por_gasto'] ?? 0))) ?>
                    </p>
                </div>
                <div>
                    <p class="text-xs text-slate-500 uppercase tracking-wider mb-2">Proyección fin de mes</p>
                    <p class="hero-number text-4xl text-amber-600">
                        <?= e(format_currency((float)($m['proyeccion_fin_mes'] ?? 0))) ?>
                    </p>
                </div>
            </div>

            <!-- 1. Comparación vs mes anterior -->
            <?php if ($cmp && $cmp['delta_pct'] !== null):
                $delta = (float)$cmp['delta_pct'];
                $arrow = $delta > 0 ? '↑' : ($delta < 0 ? '↓' : '→');
                $col   = $delta > 5 ? 'text-rose-600' : ($delta < -5 ? 'text-emerald-600' : 'text-slate-500');
            ?>
                <div class="section-divider"><span>Vs mes anterior</span></div>
                <div class="grid md:grid-cols-2 gap-8 mb-4">
                    <div>
                        <p class="text-xs text-slate-500 uppercase tracking-wider mb-2">Diferencia total</p>
                        <p class="hero-number text-3xl <?= $col ?>">
                            <?= $arrow ?> <?= number_format(abs($delta), 1) ?>%
                        </p>
                        <p class="text-xs text-slate-400 mt-1">
                            Mes anterior: <?= e(format_currency((float)$cmp['total_anterior'])) ?>
                        </p>
                    </div>
                    <?php if (!empty($cmp['por_categoria'])): ?>
                        <div>
                            <p class="text-xs text-slate-500 uppercase tracking-wider mb-2">Por categoría</p>
                            <ul class="space-y-1 text-sm">
                            <?php foreach (array_slice($cmp['por_categoria'], 0, 5) as $row):
                                $d = $row['delta_pct'];
                                $a = $d === null ? '+' : ($d > 0 ? '↑' : ($d < 0 ? '↓' : '→'));
                                $c = $d === null ? 'text-emerald-600' : ($d > 10 ? 'text-rose-600' : ($d < -10 ? 'text-emerald-600' : 'text-slate-500'));
                            ?>
                                <li class="flex justify-between">
                                    <span><?= e((string)$row['categoria']) ?></span>
                                    <span class="<?= $c ?>">
                                        <?= $a ?>
                                        <?= $d === null ? 'nuevo' : number_format(abs((float)$d), 1) . '%' ?>
                                    </span>
                                </li>
                            <?php endforeach; ?>
                            </ul>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <!-- 2. Tasa de ahorro -->
            <?php if ($ahorro):
                $evalColor = match ($ahorro['evaluacion']) {
                    'excelente' => 'text-emerald-600',
                    'buena'     => 'text-emerald-500',
                    'baja'      => 'text-amber-600',
                    default     => 'text-rose-600',
                };
                $evalText = match ($ahorro['evaluacion']) {
                    'excelente' => 'Excelente · sigue así',
                    'buena'     => 'Buena tasa de ahorro',
                    'baja'      => 'Podrías ahorrar más',
                    default     => 'Estás gastando más de lo que ingresas',
                };
            ?>
                <div class="section-divider"><span>Tasa de ahorro</span></div>
                <div class="grid md:grid-cols-3 gap-8 mb-4">
                    <div>
                        <p class="text-xs text-slate-500 uppercase tracking-wider mb-2">Tasa</p>
                        <p class="hero-number text-4xl <?= $evalColor ?>">
                            <?= number_format((float)$ahorro['tasa_pct'], 1) ?>%
                        </p>
                        <p class="text-xs <?= $evalColor ?> mt-1"><?= e($evalText) ?></p>
                    </div>
                    <div>
                        <p class="text-xs text-slate-500 uppercase tracking-wider mb-2">Ingresos del mes</p>
                        <p class="text-2xl font-semibold text-emerald-700"><?= e(format_currency((float)$ahorro['ingresos'])) ?></p>
                    </div>
                    <div>
                        <p class="text-xs text-slate-500 uppercase tracking-wider mb-2">Balance</p>
                        <p class="text-2xl font-semibold <?= $ahorro['balance'] >= 0 ? 'text-slate-900' : 'text-rose-600' ?>">
                            <?= e(format_currency((float)$ahorro['balance'])) ?>
                        </p>
                    </div>
                </div>
            <?php endif; ?>

            <!-- 4. Días que aguantas -->
            <?php if ($quiebra): ?>
                <div class="section-divider"><span>Cuánto te alcanza</span></div>
                <div class="bg-slate-50 rounded-xl p-6 mb-4">
                    <p class="text-sm text-slate-600 mb-3">
                        Con un balance disponible de
                        <strong><?= e(format_currency((float)$quiebra['balance_disponible'])) ?></strong>
                        y gastando un promedio de
                        <strong><?= e(format_currency((float)$quiebra['promedio_diario'])) ?>/día</strong>...
                    </p>
                    <p class="text-2xl font-bold <?= $quiebra['alcanza'] ? 'text-emerald-600' : 'text-amber-600' ?>">
                        Te alcanza para <?= (int)$quiebra['dias_aguanta'] ?> día<?= $quiebra['dias_aguanta'] !== 1 ? 's' : '' ?> más
                    </p>
                    <p class="text-xs text-slate-500 mt-1">
                        Quedan <?= (int)$quiebra['dias_restantes_mes'] ?> días del mes ·
                        <?php if ($quiebra['alcanza']): ?>
                            <span class="text-emerald-600">Llegas al fin de mes sin problema</span>
                        <?php else: ?>
                            <span class="text-amber-600">Te quedarás corto <?= (int)$quiebra['dias_restantes_mes'] - (int)$quiebra['dias_aguanta'] ?> día(s)</span>
                        <?php endif; ?>
                    </p>
                </div>
            <?php endif; ?>

            <!-- 5. Regla 50/30/20 -->
            <?php if ($regla): ?>
                <div class="section-divider"><span>Regla 50/30/20</span></div>
                <p class="text-xs text-slate-500 mb-4">
                    Necesidades (renta, comida, transporte, salud, materiales) · Deseos (suscripciones, salidas, otros) · Ahorro
                    <?= $regla['basado_en'] === 'gastos' ? ' · <em>basado en gastos (sin ingresos registrados)</em>' : '' ?>
                </p>
                <div class="grid md:grid-cols-3 gap-4 mb-4">
                    <?php foreach (['necesidades', 'deseos', 'ahorro'] as $tipo):
                        $r = $regla[$tipo];
                        $txtClass = match ($r['estado']) {
                            'ok'   => 'text-emerald-600',
                            'alto' => 'text-amber-600',
                            'bajo' => 'text-rose-600',
                        };
                        $barColor = match ($r['estado']) {
                            'ok'   => '#10b981',
                            'alto' => '#f59e0b',
                            'bajo' => '#ef4444',
                        };
                    ?>
                        <div class="border border-slate-100 rounded-xl p-4">
                            <div class="flex items-baseline justify-between mb-1">
                                <span class="text-xs uppercase tracking-wider text-slate-500"><?= e($tipo) ?></span>
                                <span class="text-xs text-slate-400">objetivo <?= (int)$r['objetivo'] ?>%</span>
                            </div>
                            <p class="hero-number text-2xl <?= $txtClass ?> mb-1">
                                <?= number_format((float)$r['pct'], 1) ?>%
                            </p>
                            <p class="text-xs text-slate-500"><?= e(format_currency((float)$r['monto'])) ?></p>
                            <div class="bar mt-2">
                                <div class="h-full" style="width: <?= number_format(min(100, (float)$r['pct']), 1) ?>%; background: <?= $barColor ?>;"></div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <!-- 6. Compromisos mensuales / Suscripciones -->
            <?php $compromisos = $resultado['compromisos'] ?? null; ?>
            <?php if ($compromisos): ?>
                <div class="section-divider"><span>Compromisos mensuales</span></div>
                <div class="grid md:grid-cols-3 gap-8 mb-6">
                    <div>
                        <p class="text-xs text-slate-500 uppercase tracking-wider mb-2">Total fijo al mes</p>
                        <p class="hero-number text-4xl text-slate-900">
                            <?= e(format_currency((float)$compromisos['total'])) ?>
                        </p>
                        <p class="text-xs text-slate-400 mt-1">
                            <?= (int)$compromisos['numero'] ?> pago(s) recurrente(s)
                            <?php if ($compromisos['pct_ingresos'] !== null): ?>
                                · <?= number_format((float)$compromisos['pct_ingresos'], 1) ?>% de tus ingresos
                            <?php endif; ?>
                        </p>
                    </div>
                    <?php if ($compromisos['suscripciones'] > 0): ?>
                        <div>
                            <p class="text-xs text-slate-500 uppercase tracking-wider mb-2">Solo suscripciones</p>
                            <p class="hero-number text-4xl text-indigo-600">
                                <?= e(format_currency((float)$compromisos['suscripciones'])) ?>
                            </p>
                            <p class="text-xs text-slate-400 mt-1">streaming, SaaS, apps</p>
                        </div>
                    <?php endif; ?>
                    <?php
                    // Costo anual aproximado
                    $anual = (float)$compromisos['total'] * 12;
                    ?>
                    <div>
                        <p class="text-xs text-slate-500 uppercase tracking-wider mb-2">Proyección anual</p>
                        <p class="hero-number text-4xl text-amber-600">
                            <?= e(format_currency($anual)) ?>
                        </p>
                        <p class="text-xs text-slate-400 mt-1">si todo sigue activo</p>
                    </div>
                </div>

                <ul class="divide-y divide-slate-100 mb-4">
                <?php foreach ($compromisos['items'] as $item): ?>
                    <li class="py-3 flex items-center justify-between gap-3">
                        <div class="flex items-center gap-3 min-w-0 flex-1">
                            <div class="w-8 h-8 rounded-md bg-slate-100 flex items-center justify-center text-xs font-semibold text-slate-600 shrink-0">
                                <?= (int)$item['dia_del_mes'] ?>
                            </div>
                            <div class="min-w-0">
                                <div class="font-medium truncate text-sm"><?= e((string)$item['descripcion']) ?></div>
                                <div class="text-xs text-slate-400"><?= e((string)$item['categoria']) ?></div>
                            </div>
                        </div>
                        <span class="font-semibold tabular-nums text-sm"><?= e(format_currency((float)$item['monto'])) ?></span>
                    </li>
                <?php endforeach; ?>
                </ul>
                <p class="text-xs text-slate-400 text-right">
                    <a href="/recurrentes.php" class="hover:text-slate-900 underline">Gestionar pagos recurrentes →</a>
                </p>
            <?php endif; ?>

            <!-- 7. Sin presupuesto -->
            <?php if ($sinPresupuesto): ?>
                <div class="section-divider"><span>Categorías sin presupuesto</span></div>
                <div class="bg-amber-50 border border-amber-100 rounded-xl p-4 mb-4">
                    <p class="text-sm text-amber-900 mb-3">
                        Estás gastando en categorías que no tienen un presupuesto definido.
                        Defínelo para mejor control.
                    </p>
                    <ul class="space-y-1 text-sm">
                    <?php foreach ($sinPresupuesto as $sp): ?>
                        <li class="flex justify-between">
                            <span><?= e((string)$sp['categoria']) ?></span>
                            <span class="font-semibold"><?= e(format_currency((float)$sp['gastado'])) ?> gastado</span>
                        </li>
                    <?php endforeach; ?>
                    </ul>
                    <a href="/presupuestos.php" class="text-xs text-amber-900 underline mt-3 inline-block">
                        Definir presupuestos →
                    </a>
                </div>
            <?php endif; ?>

            <!-- 8. Factibilidad de metas -->
            <?php if ($metasFact): ?>
                <div class="section-divider"><span>¿Llegas a tus metas?</span></div>
                <ul class="divide-y divide-slate-100 mb-4">
                <?php foreach ($metasFact as $mf):
                    $estado = $mf['estado'];
                    $col = match ($estado) {
                        'a_tiempo'   => 'text-emerald-600',
                        'sin_fecha'  => 'text-slate-600',
                        'tarde'      => 'text-amber-600',
                        'imposible'  => 'text-rose-600',
                        'completada' => 'text-emerald-600',
                    };
                    $msg = match ($estado) {
                        'a_tiempo'  => 'Llegas a tiempo',
                        'sin_fecha' => 'Sin fecha objetivo',
                        'tarde'     => 'Llegas tarde',
                        'imposible' => 'No es posible al ritmo actual',
                        'completada' => 'Completada',
                    };
                ?>
                    <li class="py-4">
                        <div class="flex items-baseline justify-between mb-1">
                            <span class="font-medium"><?= e((string)$mf['nombre']) ?></span>
                            <span class="text-sm font-semibold <?= $col ?>"><?= e($msg) ?></span>
                        </div>
                        <?php if (isset($mf['faltante'])): ?>
                            <p class="text-xs text-slate-500">
                                Faltan <?= e(format_currency((float)$mf['faltante'])) ?>
                                <?php if (isset($mf['meses'])): ?>
                                    · ~<?= (int)$mf['meses'] ?> mes(es) al ritmo actual
                                <?php endif; ?>
                                <?php if (isset($mf['fecha_proyectada'])): ?>
                                    · proyectada <?= e((string)$mf['fecha_proyectada']) ?>
                                    <?php if (isset($mf['fecha_objetivo'])): ?>
                                        (objetivo <?= e((string)$mf['fecha_objetivo']) ?>)
                                    <?php endif; ?>
                                <?php endif; ?>
                            </p>
                        <?php endif; ?>
                    </li>
                <?php endforeach; ?>
                </ul>
            <?php endif; ?>

            <!-- Top categoría / día (compacto) -->
            <div class="section-divider"><span>Categoría y día dominantes</span></div>
            <div class="grid md:grid-cols-2 gap-8 mb-4">
                <div>
                    <p class="text-xs text-slate-500 uppercase tracking-wider mb-2">Categoría top</p>
                    <p class="text-xl font-semibold"><?= e((string)($m['categoria_top']['nombre'] ?? '—')) ?></p>
                    <p class="text-sm text-slate-500 mt-1"><?= e(format_currency((float)($m['categoria_top']['monto'] ?? 0))) ?></p>
                </div>
                <div>
                    <p class="text-xs text-slate-500 uppercase tracking-wider mb-2">Día con más gasto</p>
                    <p class="text-xl font-semibold"><?= e((string)($m['dia_top']['nombre'] ?? '—')) ?></p>
                    <p class="text-sm text-slate-500 mt-1"><?= e(format_currency((float)($m['dia_top']['monto'] ?? 0))) ?></p>
                </div>
            </div>

            <!-- Anomalías -->
            <?php if (!empty($resultado['anomalias'])): ?>
                <div class="section-divider"><span>Gastos inusuales</span></div>
                <ul class="divide-y divide-slate-100">
                    <?php foreach ($resultado['anomalias'] as $a): ?>
                        <li class="py-3 flex justify-between items-center">
                            <div>
                                <div class="font-medium"><?= e((string)$a['descripcion']) ?></div>
                                <div class="text-xs text-slate-400"><?= e((string)$a['fecha']) ?></div>
                            </div>
                            <span class="font-semibold text-amber-600 tabular-nums"><?= e(format_currency((float)$a['monto'])) ?></span>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>

            <!-- Recomendaciones -->
            <?php if (!empty($resultado['recomendaciones'])): ?>
                <div class="section-divider"><span>Recomendaciones</span></div>
                <ul class="space-y-3 mb-4">
                    <?php foreach ($resultado['recomendaciones'] as $r): ?>
                        <li class="flex gap-3 text-slate-700">
                            <span class="text-indigo-500 mt-1">→</span>
                            <span><?= e((string)$r) ?></span>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        <?php endif; ?>
    <?php endif; ?>
</div>
<?php require __DIR__ . '/../templates/footer.php'; ?>
