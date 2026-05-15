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
            // ---- FASE 1: lógica PHP local ----
            require_once __DIR__ . '/../config/analisis_local.php';
            $resultado = analizar_gastos($gastos, $mes, $anio);
        } else {
            // ---- FASE 2: Lambda real via SDK ----
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

$pageTitle = 'Análisis financiero';
require __DIR__ . '/../templates/header.php';
?>

<div class="bg-white rounded-xl shadow border border-slate-200 p-6 mb-4">
    <h1 class="text-xl font-bold mb-1">Análisis financiero mensual</h1>
    <p class="text-sm text-slate-500 mb-4">
        Procesa tus gastos del periodo seleccionado con una función serverless
        <?= getenv('LAMBDA_FUNCTION_NAME') ? '(Lambda AWS)' : '(Fase 1: lógica local equivalente)' ?>.
    </p>

    <form method="POST" class="flex flex-wrap items-end gap-3">
        <?= csrf_field() ?>
        <div>
            <label class="block text-sm font-medium mb-1">Mes</label>
            <select name="mes" class="border border-slate-300 rounded px-2 py-1">
                <?php for ($m = 1; $m <= 12; $m++): ?>
                    <option value="<?= $m ?>" <?= $m === $mes ? 'selected' : '' ?>><?= e(nombre_mes($m)) ?></option>
                <?php endfor; ?>
            </select>
        </div>
        <div>
            <label class="block text-sm font-medium mb-1">Año</label>
            <select name="anio" class="border border-slate-300 rounded px-2 py-1">
                <?php for ($a = 2024; $a <= (int)date('Y') + 1; $a++): ?>
                    <option value="<?= $a ?>" <?= $a === $anio ? 'selected' : '' ?>><?= $a ?></option>
                <?php endfor; ?>
            </select>
        </div>
        <button class="bg-indigo-600 text-white px-4 py-2 rounded hover:bg-indigo-700">Analizar</button>
    </form>
</div>

<?php if ($error): ?>
    <div class="mb-4 p-3 border border-rose-200 bg-rose-50 text-rose-800 rounded"><?= e($error) ?></div>
<?php endif; ?>

<?php if ($resultado): ?>
    <?php if (!empty($resultado['mensaje'])): ?>
        <div class="bg-white rounded-xl shadow border border-slate-200 p-6">
            <p class="text-slate-600"><?= e((string)$resultado['mensaje']) ?></p>
        </div>
    <?php else: $m = $resultado['metricas'] ?? []; ?>
        <div class="grid md:grid-cols-3 gap-4 mb-4">
            <div class="bg-white rounded-xl shadow border border-slate-200 p-5">
                <p class="text-xs text-slate-500">Total gastado</p>
                <p class="big-number text-indigo-600"><?= e(format_currency((float)($m['total'] ?? 0))) ?></p>
                <p class="text-xs text-slate-500 mt-1"><?= (int)($m['numero_gastos'] ?? 0) ?> registro(s)</p>
            </div>
            <div class="bg-white rounded-xl shadow border border-slate-200 p-5">
                <p class="text-xs text-slate-500">Promedio por gasto</p>
                <p class="big-number text-slate-700"><?= e(format_currency((float)($m['promedio_por_gasto'] ?? 0))) ?></p>
            </div>
            <div class="bg-white rounded-xl shadow border border-slate-200 p-5">
                <p class="text-xs text-slate-500">Proyección fin de mes</p>
                <p class="big-number text-amber-600"><?= e(format_currency((float)($m['proyeccion_fin_mes'] ?? 0))) ?></p>
            </div>
        </div>

        <div class="grid md:grid-cols-2 gap-4 mb-4">
            <div class="bg-white rounded-xl shadow border border-slate-200 p-5">
                <p class="text-xs text-slate-500">Categoría top</p>
                <p class="text-lg font-bold"><?= e((string)($m['categoria_top']['nombre'] ?? '—')) ?></p>
                <p class="text-sm text-slate-500"><?= e(format_currency((float)($m['categoria_top']['monto'] ?? 0))) ?></p>
            </div>
            <div class="bg-white rounded-xl shadow border border-slate-200 p-5">
                <p class="text-xs text-slate-500">Día con más gasto</p>
                <p class="text-lg font-bold"><?= e((string)($m['dia_top']['nombre'] ?? '—')) ?></p>
                <p class="text-sm text-slate-500"><?= e(format_currency((float)($m['dia_top']['monto'] ?? 0))) ?></p>
            </div>
        </div>

        <?php if (!empty($resultado['anomalias'])): ?>
            <div class="bg-amber-50 border border-amber-200 rounded-xl p-5 mb-4">
                <h3 class="font-bold text-amber-800 mb-2">⚠️ Gastos inusuales detectados</h3>
                <ul class="text-sm space-y-1">
                <?php foreach ($resultado['anomalias'] as $a): ?>
                    <li>
                        <strong><?= e((string)$a['descripcion']) ?></strong>
                        — <?= e(format_currency((float)$a['monto'])) ?>
                        <span class="text-slate-500">(<?= e((string)$a['fecha']) ?>)</span>
                    </li>
                <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <?php if (!empty($resultado['recomendaciones'])): ?>
            <div class="bg-white rounded-xl shadow border border-slate-200 p-5">
                <h3 class="font-bold mb-2">💡 Recomendaciones</h3>
                <ul class="list-disc list-inside text-sm space-y-1">
                    <?php foreach ($resultado['recomendaciones'] as $r): ?>
                        <li><?= e((string)$r) ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>
    <?php endif; ?>
<?php endif; ?>

<?php require __DIR__ . '/../templates/footer.php'; ?>
