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

$pageTitle = 'Gastos';
require __DIR__ . '/../templates/header.php';
?>
<div class="py-12">
    <header class="mb-8 flex items-end justify-between flex-wrap gap-4">
        <div>
            <h1 class="text-3xl font-bold tracking-tight">Gastos</h1>
            <p class="text-sm text-slate-500 mt-1">
                <?= e(nombre_mes($mes) . ' ' . $anio) ?> · <?= count($gastos) ?> registro<?= count($gastos) !== 1 ? 's' : '' ?>
            </p>
        </div>
        <div class="text-right">
            <div class="text-xs text-slate-500">Total del periodo</div>
            <div class="hero-number text-3xl mt-1"><?= e(format_currency($total)) ?></div>
        </div>
    </header>

    <!-- Filtros + acciones (dos forms hermanos en un solo flex row) -->
    <div class="flex flex-wrap gap-2 mb-6 pb-6 border-b border-slate-100 text-sm items-center">
        <form method="GET" class="flex flex-wrap gap-2 items-center">
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
            <select name="categoria" class="input-clean w-auto">
                <option value="">Todas las categorías</option>
                <?php foreach ($categorias as $c): ?>
                    <option value="<?= (int)$c['id'] ?>" <?= (int)$c['id'] === $cat ? 'selected' : '' ?>>
                        <?= e($c['nombre']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <button class="btn-primary">Filtrar</button>
        </form>

        <a href="/alta.php" class="btn-secondary ml-auto">+ Nuevo gasto</a>
    </div>

    <?php if (!$gastos): ?>
        <div class="text-center py-16">
            <p class="text-slate-400 mb-4">No hay gastos en este periodo.</p>
            <a href="/alta.php" class="btn-primary">Registrar un gasto</a>
        </div>
    <?php else:
        $catNombre = '';
        if ($cat > 0) {
            foreach ($categorias as $c) {
                if ((int)$c['id'] === $cat) { $catNombre = (string)$c['nombre']; break; }
            }
        }
        $descripcionScope = $catNombre !== ''
            ? "todos los gastos de {$catNombre} en " . nombre_mes($mes) . " {$anio}"
            : "todos los gastos de " . nombre_mes($mes) . " {$anio}";
        $confirmMsg = "¿Estás seguro de eliminar {$descripcionScope}? Esta acción no se puede deshacer.";
    ?>
        <div class="flex justify-end mb-2">
            <form method="POST" action="/eliminar.php"
                  onsubmit="return confirm(<?= e_attr(json_encode($confirmMsg, JSON_THROW_ON_ERROR)) ?>);">
                <?= csrf_field() ?>
                <input type="hidden" name="modo" value="bulk">
                <input type="hidden" name="mes"  value="<?= (int)$mes ?>">
                <input type="hidden" name="anio" value="<?= (int)$anio ?>">
                <?php if ($cat > 0): ?>
                    <input type="hidden" name="categoria" value="<?= (int)$cat ?>">
                <?php endif; ?>
                <button type="submit" class="text-xs text-rose-600 hover:text-rose-700 hover:underline">
                    Eliminar todos
                </button>
            </form>
        </div>

        <ul class="divide-y divide-slate-100">
            <?php foreach ($gastos as $g): ?>
                <li class="py-4 flex items-center justify-between gap-4 group">
                    <div class="flex items-center gap-4 min-w-0 flex-1">
                        <div class="text-xs text-slate-400 tabular-nums w-20 shrink-0">
                            <?= e((string)$g['fecha']) ?>
                        </div>
                        <div class="min-w-0 flex-1">
                            <div class="font-medium truncate">
                                <?= ($g['descripcion'] ?? '') !== ''
                                    ? e((string)$g['descripcion'])
                                    : '<span class="text-slate-400">(sin descripción)</span>' ?>
                            </div>
                            <div class="text-xs text-slate-400 mt-0.5 flex items-center gap-2">
                                <span class="badge"><?= e((string)$g['categoria']) ?></span>
                                <span><?= e((string)$g['metodo_pago']) ?></span>
                            </div>
                        </div>
                    </div>
                    <div class="flex items-center gap-3 shrink-0">
                        <span class="font-semibold tabular-nums"><?= e(format_currency((float)$g['monto'])) ?></span>
                        <div class="flex items-center gap-1 opacity-60 group-hover:opacity-100 transition-opacity">
                            <a href="/editar.php?id=<?= (int)$g['id'] ?>"
                               class="text-xs px-2.5 py-1 rounded text-slate-500 hover:bg-slate-100 hover:text-slate-900">
                                Editar
                            </a>
                            <form method="POST" action="/eliminar.php" class="inline"
                                  onsubmit="return confirm('¿Eliminar este gasto de <?= e(format_currency((float)$g['monto'])) ?>?');">
                                <?= csrf_field() ?>
                                <input type="hidden" name="id" value="<?= (int)$g['id'] ?>">
                                <button type="submit"
                                        class="text-xs px-2.5 py-1 rounded text-rose-600 hover:bg-rose-50">
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
