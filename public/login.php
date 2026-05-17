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
<div class="min-h-[70vh] flex items-center justify-center py-12">
    <div class="w-full max-w-sm">
        <div class="text-center mb-8">
            <h1 class="text-3xl font-bold tracking-tight">Bienvenido</h1>
            <p class="text-sm text-slate-500 mt-2">Ingresa para gestionar tus finanzas estudiantiles.</p>
        </div>

        <?php if ($error): ?>
            <div class="mb-4 px-4 py-2.5 border border-rose-100 bg-rose-50 text-rose-900 text-sm rounded-lg">
                <?= e($error) ?>
            </div>
        <?php endif; ?>

        <form method="POST" class="space-y-4">
            <?= csrf_field() ?>
            <div>
                <label class="block text-sm font-medium mb-1.5 text-slate-700">Usuario</label>
                <input type="text" name="username" required maxlength="50" autocomplete="username" class="input-clean">
            </div>
            <div>
                <label class="block text-sm font-medium mb-1.5 text-slate-700">Contraseña</label>
                <input type="password" name="password" required maxlength="100" autocomplete="current-password" class="input-clean">
            </div>
            <button type="submit" class="btn-primary w-full">Entrar</button>
        </form>

        <div class="mt-8 p-4 bg-slate-50 rounded-lg text-xs text-slate-500 text-center">
            <div class="font-medium text-slate-700 mb-1">Cuenta demo</div>
            <code class="text-slate-600">demo</code> · <code class="text-slate-600">Demo2026!</code>
        </div>
    </div>
</div>
<?php require __DIR__ . '/../templates/footer.php'; ?>
