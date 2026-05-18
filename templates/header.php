<?php
declare(strict_types=1);
$pageTitle = $pageTitle ?? 'StudentWallet';

// Detecta página activa para resaltar nav
$current = basename($_SERVER['SCRIPT_NAME'] ?? '');
// alta.php, editar.php y eliminar.php son acciones que viven dentro del flujo de consulta.php
// (un solo lugar para listar, crear, editar y eliminar gastos).
$navItems = [
    'index.php'     => 'Inicio',
    'consulta.php'  => 'Gastos',
    'descargas.php' => 'Descargas',
    'analisis.php'  => 'Análisis',
];
// Resaltar "Gastos" cuando estés en cualquiera de las páginas hijas
if (in_array($current, ['alta.php', 'editar.php', 'eliminar.php'], true)) {
    $current = 'consulta.php';
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($pageTitle) ?> · StudentWallet</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/assets/css/custom.css">
</head>
<body class="bg-white text-slate-900 min-h-screen antialiased">
<header class="sticky top-0 z-50 bg-white/80 backdrop-blur-md border-b border-slate-100">
    <div class="max-w-5xl mx-auto px-6 h-14 grid grid-cols-[auto_1fr_auto] items-center gap-6">
        <!-- Logo a la izquierda -->
        <a href="/index.php" class="font-semibold tracking-tight text-slate-900">
            StudentWallet
        </a>

        <?php if (!empty($_SESSION['user_id'])): ?>
            <!-- Nav centrado -->
            <nav class="flex items-center justify-center gap-1 text-sm">
                <?php foreach ($navItems as $file => $label):
                    $active = $current === $file; ?>
                    <a href="/<?= e($file) ?>"
                       class="px-3 py-1.5 rounded-md transition-colors <?= $active
                           ? 'text-slate-900 bg-slate-100 font-medium'
                           : 'text-slate-500 hover:text-slate-900 hover:bg-slate-50' ?>">
                        <?= e($label) ?>
                    </a>
                <?php endforeach; ?>
            </nav>

            <!-- Usuario + Salir a la derecha (visualmente separados) -->
            <div class="flex items-center gap-3">
                <?php if (!empty($_SESSION['nombre'])): ?>
                    <div class="flex items-center gap-2 text-sm">
                        <div class="w-7 h-7 rounded-full bg-indigo-100 text-indigo-700 flex items-center justify-center text-xs font-semibold">
                            <?= e(mb_strtoupper(mb_substr((string)$_SESSION['nombre'], 0, 1))) ?>
                        </div>
                        <span class="text-slate-600"><?= e((string)$_SESSION['nombre']) ?></span>
                    </div>
                <?php endif; ?>
                <form method="POST" action="/logout.php" class="pl-3 border-l border-slate-200">
                    <?= csrf_field() ?>
                    <button type="submit"
                            class="text-sm text-rose-600 hover:text-rose-700 hover:underline">
                        Salir
                    </button>
                </form>
            </div>
        <?php else: ?>
            <span></span><span></span>
        <?php endif; ?>
    </div>
</header>
<main class="max-w-5xl mx-auto px-6">
<?php
$flash = $_SESSION['_flash'] ?? [];
unset($_SESSION['_flash']);
if ($flash): ?>
    <div class="pt-6 space-y-2">
    <?php foreach ($flash as $type => $msgs):
        $css = match ($type) {
            'success' => 'bg-emerald-50 text-emerald-900 border-emerald-100',
            'error'   => 'bg-rose-50 text-rose-900 border-rose-100',
            default   => 'bg-slate-50 text-slate-700 border-slate-100',
        };
        foreach ($msgs as $m): ?>
            <div class="px-4 py-2.5 border rounded-lg text-sm <?= $css ?>"><?= e((string)$m) ?></div>
    <?php endforeach; endforeach; ?>
    </div>
<?php endif; ?>
