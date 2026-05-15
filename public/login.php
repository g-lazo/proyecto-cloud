<?php
declare(strict_types=1);
require __DIR__ . '/../config/db.php';
require __DIR__ . '/../config/helpers.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start([
        'cookie_httponly' => true,
        'cookie_secure'   => (($_SERVER['HTTPS'] ?? '') === 'on'),
        'cookie_samesite' => 'Lax',
        'use_strict_mode' => true,
    ]);
}

if (!empty($_SESSION['user_id'])) {
    header('Location: /index.php');
    exit;
}

$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify_or_die();

    $ip = client_ip();
    if (login_bloqueado($pdo, $ip)) {
        $error = 'Demasiados intentos. Intenta en 15 minutos.';
    } else {
        $username = trim((string)($_POST['username'] ?? ''));
        $password = (string)($_POST['password'] ?? '');

        $stmt = $pdo->prepare('SELECT id, password_hash, nombre FROM usuarios WHERE username = ?');
        $stmt->execute([$username]);
        $user = $stmt->fetch();

        // Verificar siempre para evitar timing/enumeration
        $hash = $user['password_hash'] ?? '$2y$10$XXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXX';
        $ok = password_verify($password, $hash);

        if ($user && $ok) {
            log_intento_login($pdo, $ip, $username, true);
            session_regenerate_id(true);
            $_SESSION['user_id'] = (int)$user['id'];
            $_SESSION['nombre']  = (string)$user['nombre'];
            header('Location: /index.php');
            exit;
        }

        log_intento_login($pdo, $ip, $username, false);
        usleep(random_int(200_000, 600_000));
        $error = 'Credenciales inválidas';
    }
}

$pageTitle = 'Iniciar sesión';
require __DIR__ . '/../templates/header.php';
?>
<div class="max-w-md mx-auto bg-white rounded-xl shadow border border-slate-200 p-6 mt-10">
    <h1 class="text-xl font-bold mb-1">Bienvenido</h1>
    <p class="text-sm text-slate-500 mb-4">Ingresa con tu cuenta para continuar.</p>

    <?php if ($error): ?>
        <div class="mb-3 p-3 border border-rose-200 bg-rose-50 text-rose-700 rounded">
            <?= e($error) ?>
        </div>
    <?php endif; ?>

    <form method="POST" class="space-y-3">
        <?= csrf_field() ?>
        <div>
            <label class="block text-sm font-medium mb-1">Usuario</label>
            <input type="text" name="username" required maxlength="50" autocomplete="username"
                   class="w-full border border-slate-300 rounded px-3 py-2 focus:outline-none focus:ring-2 focus:ring-indigo-400">
        </div>
        <div>
            <label class="block text-sm font-medium mb-1">Contraseña</label>
            <input type="password" name="password" required maxlength="100" autocomplete="current-password"
                   class="w-full border border-slate-300 rounded px-3 py-2 focus:outline-none focus:ring-2 focus:ring-indigo-400">
        </div>
        <button type="submit" class="w-full bg-indigo-600 text-white py-2 rounded hover:bg-indigo-700">
            Entrar
        </button>
    </form>

    <p class="text-xs text-slate-400 mt-4">Demo: <code>demo</code> / <code>Demo2026!</code></p>
</div>
<?php require __DIR__ . '/../templates/footer.php'; ?>
