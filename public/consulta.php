<?php
declare(strict_types=1);
require __DIR__ . '/../config/db.php';
require __DIR__ . '/../config/helpers.php';
require __DIR__ . '/../config/auth_check.php';

$uid = (int)$_SESSION['user_id'];

$mes  = filter_input(INPUT_GET, 'mes', FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1, 'max_range' => 12]]) ?: (int)date('n');
$anio = filter_input(INPUT_GET, 'anio', FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 2020, 'max_range' => 2100]]) ?: (int)date('Y');
$cat  = filter_input(INPUT_GET, 'categoria', FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1]]) ?: 0;

$where = 'g.usuario_id = ? AND MONTH(g.fecha) = ? AND YEAR(g.fecha) = ?';
$params = [$uid, $mes, $anio];
if ($cat > 0) {
    $where .= ' AND g.categoria_id = ?';
    $params[] = $cat;
}

$stmt = $pdo->prepare("
    SELECT g.id, g.monto, g.descripcion, g.fecha, g.metodo_pago, c.nombre AS categoria
    FROM gastos g
    JOIN categorias c ON c.id = g.categoria_id
    WHERE $where
    ORDER BY g.fecha DESC, g.id DESC
");
$stmt->execute($params);
$gastos = $stmt->fetchAll();

$total = array_sum(array_map(fn($g) => (float)$g['monto'], $gastos));

$catStmt = $pdo->prepare('SELECT id, nombre FROM categorias WHERE usuario_id IS NULL OR usuario_id = ? ORDER BY nombre');
$catStmt->execute([$uid]);
$categorias = $catStmt->fetchAll();

$pageTitle = 'Consultar gastos';
require __DIR__ . '/../templates/header.php';
?>
<div class="bg-white rounded-xl shadow border border-slate-200 p-6">
    <div class="flex flex-wrap items-center justify-between gap-3 mb-4">
        <h1 class="text-xl font-bold">Gastos de <?= e(nombre_mes($mes) . ' ' . $anio) ?></h1>
        <span class="text-sm text-slate-500">Total: <strong><?= e(format_currency($total)) ?></strong> · <?= count($gastos) ?> registro(s)</span>
    </div>

    <form method="GET" class="flex flex-wrap gap-2 mb-4 text-sm">
        <select name="mes" class="border border-slate-300 rounded px-2 py-1">
            <?php for ($m = 1; $m <= 12; $m++): ?>
                <option value="<?= $m ?>" <?= $m === $mes ? 'selected' : '' ?>><?= e(nombre_mes($m)) ?></option>
            <?php endfor; ?>
        </select>
        <select name="anio" class="border border-slate-300 rounded px-2 py-1">
            <?php for ($a = 2024; $a <= (int)date('Y') + 1; $a++): ?>
                <option value="<?= $a ?>" <?= $a === $anio ? 'selected' : '' ?>><?= $a ?></option>
            <?php endfor; ?>
        </select>
        <select name="categoria" class="border border-slate-300 rounded px-2 py-1">
            <option value="">Todas las categorías</option>
            <?php foreach ($categorias as $c): ?>
                <option value="<?= (int)$c['id'] ?>" <?= (int)$c['id'] === $cat ? 'selected' : '' ?>>
                    <?= e($c['nombre']) ?>
                </option>
            <?php endforeach; ?>
        </select>
        <button class="bg-indigo-600 text-white px-3 py-1 rounded hover:bg-indigo-700">Filtrar</button>
    </form>

    <?php if (!$gastos): ?>
        <p class="text-slate-500">No hay gastos en este periodo. <a href="/alta.php" class="text-indigo-600 underline">Registra uno</a>.</p>
    <?php else: ?>
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-slate-100 text-left">
                    <tr>
                        <th class="px-3 py-2">Fecha</th>
                        <th class="px-3 py-2">Categoría</th>
                        <th class="px-3 py-2">Descripción</th>
                        <th class="px-3 py-2">Método</th>
                        <th class="px-3 py-2 text-right">Monto</th>
                        <th class="px-3 py-2"></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($gastos as $g): ?>
                    <tr class="border-b border-slate-100">
                        <td class="px-3 py-2"><?= e((string)$g['fecha']) ?></td>
                        <td class="px-3 py-2"><?= e((string)$g['categoria']) ?></td>
                        <td class="px-3 py-2"><?= e((string)($g['descripcion'] ?? '')) ?></td>
                        <td class="px-3 py-2 text-slate-500"><?= e((string)$g['metodo_pago']) ?></td>
                        <td class="px-3 py-2 text-right font-medium"><?= e(format_currency((float)$g['monto'])) ?></td>
                        <td class="px-3 py-2 text-right">
                            <a href="/editar.php?id=<?= (int)$g['id'] ?>" class="text-indigo-600 hover:underline">Editar</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>
<?php require __DIR__ . '/../templates/footer.php'; ?>
