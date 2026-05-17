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

    $stmt = $pdo->prepare('
        SELECT g.id, g.monto, g.descripcion, g.fecha, c.nombre AS categoria
        FROM gastos g
        JOIN categorias c ON c.id = g.categoria_id
        WHERE g.usuario_id = ? AND MONTH(g.fecha) = ? AND YEAR(g.fecha) = ?
    ');
    $stmt->execute([$uid, $mes, $anio]);
    $gastos = $stmt->fetchAll();

    $lambdaFn = getenv('LAMBDA_FUNCTION_NAME') ?: '';

    try {
        if ($lambdaFn === '') {
            require_once __DIR__ . '/../config/analisis_local.php';
            $resultado = analizar_gastos($gastos, $mes, $anio);
        } else {
            require __DIR__ . '/../vendor/autoload.php';
            $lambda = new Aws\Lambda\LambdaClient([
                'version' => 'latest',
                'region'  => getenv('AWS_REGION') ?: 'us-east-1',
            ]);
            $res = $lambda->invoke([
                'FunctionName'   => $lambdaFn,
                'InvocationType' => 'RequestResponse',
                'Payload'        => json_encode([
                    'mes' => $mes, 'anio' => $anio, 'gastos' => $gastos,
                ], JSON_THROW_ON_ERROR),
            ]);
            $payload = json_decode((string)$res['Payload'], true, flags: JSON_THROW_ON_ERROR);
            $resultado = isset($payload['body']) && is_string($payload['body'])
                ? json_decode($payload['body'], true, flags: JSON_THROW_ON_ERROR)
                : $payload;
        }
    } catch (Throwable $e) {
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
        <?php else: $m = $resultado['metricas'] ?? []; ?>

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

            <!-- Top categoría / día -->
            <div class="grid md:grid-cols-2 gap-8 mb-12">
                <div class="py-4 border-t border-slate-100">
                    <p class="text-xs text-slate-500 uppercase tracking-wider mb-2">Categoría dominante</p>
                    <p class="text-2xl font-semibold"><?= e((string)($m['categoria_top']['nombre'] ?? '—')) ?></p>
                    <p class="text-sm text-slate-500 mt-1"><?= e(format_currency((float)($m['categoria_top']['monto'] ?? 0))) ?></p>
                </div>
                <div class="py-4 border-t border-slate-100">
                    <p class="text-xs text-slate-500 uppercase tracking-wider mb-2">Día con más gasto</p>
                    <p class="text-2xl font-semibold"><?= e((string)($m['dia_top']['nombre'] ?? '—')) ?></p>
                    <p class="text-sm text-slate-500 mt-1"><?= e(format_currency((float)($m['dia_top']['monto'] ?? 0))) ?></p>
                </div>
            </div>

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

            <?php if (!empty($resultado['recomendaciones'])): ?>
                <div class="section-divider"><span>Recomendaciones</span></div>
                <ul class="space-y-3">
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
