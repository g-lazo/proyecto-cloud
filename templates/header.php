<?php
declare(strict_types=1);
// Espera $pageTitle (opcional) y que la sesión ya esté iniciada.
$pageTitle = $pageTitle ?? 'StudentWallet';
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($pageTitle) ?> · StudentWallet</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
    <link rel="stylesheet" href="/assets/css/custom.css">
</head>
<body class="bg-slate-50 text-slate-800 min-h-screen">
<header class="bg-white border-b border-slate-200">
    <div class="max-w-6xl mx-auto px-4 py-3 flex flex-wrap items-center gap-4">
        <a href="/index.php" class="font-bold text-lg text-indigo-600">StudentWallet</a>
        <?php if (!empty($_SESSION['user_id'])): ?>
        <nav class="flex flex-wrap gap-2 ml-auto text-sm">
            <a href="/index.php"     class="px-3 py-1.5 rounded hover:bg-slate-100">Dashboard</a>
            <a href="/alta.php"      class="px-3 py-1.5 rounded hover:bg-slate-100">+ Gasto</a>
            <a href="/consulta.php"  class="px-3 py-1.5 rounded hover:bg-slate-100">Consultar</a>
            <a href="/editar.php"    class="px-3 py-1.5 rounded hover:bg-slate-100">Editar</a>
            <a href="/eliminar.php"  class="px-3 py-1.5 rounded hover:bg-slate-100">Eliminar</a>
            <a href="/descargas.php" class="px-3 py-1.5 rounded hover:bg-slate-100">Descargas</a>
            <a href="/analisis.php"  class="px-3 py-1.5 rounded hover:bg-slate-100">Análisis</a>
            <form method="POST" action="/logout.php" class="inline">
                <?= csrf_field() ?>
                <button type="submit" class="px-3 py-1.5 rounded bg-rose-100 text-rose-700 hover:bg-rose-200">
                    Salir<?= !empty($_SESSION['nombre']) ? ' (' . e((string)$_SESSION['nombre']) . ')' : '' ?>
                </button>
            </form>
        </nav>
        <?php endif; ?>
    </div>
</header>
<main class="max-w-6xl mx-auto px-4 py-6">
<?php
$flash = $_SESSION['_flash'] ?? [];
unset($_SESSION['_flash']);
foreach ($flash as $type => $msgs):
    $css = match ($type) {
        'success' => 'bg-emerald-50 border-emerald-200 text-emerald-800',
        'error'   => 'bg-rose-50 border-rose-200 text-rose-800',
        default   => 'bg-slate-50 border-slate-200 text-slate-800',
    };
    foreach ($msgs as $m): ?>
        <div class="mb-3 p-3 border rounded <?= $css ?>"><?= e((string)$m) ?></div>
<?php endforeach; endforeach; ?>
