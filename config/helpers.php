<?php
declare(strict_types=1);

// ============ OUTPUT ESCAPING ============
function e(?string $s): string {
    return htmlspecialchars($s ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
function e_attr(?string $s): string {
    return htmlspecialchars($s ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
function format_currency(float $n): string {
    return '$' . number_format($n, 2);
}

const MESES_ES = ['', 'Enero', 'Febrero', 'Marzo', 'Abril', 'Mayo', 'Junio',
                  'Julio', 'Agosto', 'Septiembre', 'Octubre', 'Noviembre', 'Diciembre'];
const DIAS_ES  = ['Lunes', 'Martes', 'Miércoles', 'Jueves', 'Viernes', 'Sábado', 'Domingo'];

function nombre_mes(int $m): string {
    return MESES_ES[$m] ?? '';
}

// ============ CSRF ============
function csrf_token(): string {
    if (empty($_SESSION['_csrf'])) {
        $_SESSION['_csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['_csrf'];
}
function csrf_field(): string {
    return '<input type="hidden" name="_csrf" value="' . e_attr(csrf_token()) . '">';
}
function csrf_verify_or_die(): void {
    $submitted = $_POST['_csrf'] ?? '';
    $expected  = $_SESSION['_csrf'] ?? '';
    if (!is_string($submitted) || !is_string($expected) || $expected === ''
        || !hash_equals($expected, $submitted)) {
        http_response_code(403);
        exit('CSRF inválido');
    }
}

// ============ FATAL HELPERS ============
function die_400(string $msg): never {
    http_response_code(400);
    exit('Solicitud inválida: ' . e($msg));
}
function die_404(): never {
    http_response_code(404);
    exit('No encontrado');
}

// ============ VALIDACIÓN DE INPUTS ============
function require_post_int(string $key, int $min = PHP_INT_MIN, int $max = PHP_INT_MAX): int {
    $v = filter_input(INPUT_POST, $key, FILTER_VALIDATE_INT, [
        'options' => ['min_range' => $min, 'max_range' => $max]
    ]);
    if ($v === false || $v === null) die_400("$key inválido");
    return $v;
}
function require_get_int(string $key, int $min = 1, int $max = PHP_INT_MAX): int {
    $v = filter_input(INPUT_GET, $key, FILTER_VALIDATE_INT, [
        'options' => ['min_range' => $min, 'max_range' => $max]
    ]);
    if ($v === false || $v === null) die_400("$key inválido");
    return $v;
}
function require_post_decimal(string $key, float $min = 0.01, float $max = 99999999.99): float {
    $raw = $_POST[$key] ?? '';
    if (!is_string($raw) || !preg_match('/^\d{1,10}(\.\d{1,2})?$/', $raw)) {
        die_400("$key no es un monto válido");
    }
    $v = (float)$raw;
    if ($v < $min || $v > $max) die_400("$key fuera de rango");
    return $v;
}
function require_post_date(string $key, int $minYear = 2020, int $maxYear = 2100): string {
    $raw = $_POST[$key] ?? '';
    if (!is_string($raw)) die_400("$key inválido");
    $d = DateTimeImmutable::createFromFormat('Y-m-d', $raw);
    if (!$d || $d->format('Y-m-d') !== $raw) die_400("$key no es fecha válida");
    $y = (int)$d->format('Y');
    if ($y < $minYear || $y > $maxYear) die_400("$key fuera de rango");
    return $raw;
}
function require_post_string(string $key, int $maxLen, bool $required = true): string {
    $raw = $_POST[$key] ?? '';
    if (!is_string($raw)) die_400("$key inválido");
    $raw = trim($raw);
    if ($required && $raw === '') die_400("$key requerido");
    if (mb_strlen($raw) > $maxLen) die_400("$key demasiado largo");
    return $raw;
}
function require_post_enum(string $key, array $allowed): string {
    $raw = $_POST[$key] ?? '';
    if (!is_string($raw) || !in_array($raw, $allowed, true)) die_400("$key inválido");
    return $raw;
}

// ============ HELPERS DE SCOPE (anti-IDOR) ============
function categoria_pertenece_al_usuario(PDO $pdo, int $catId, int $uid): bool {
    $stmt = $pdo->prepare(
        'SELECT 1 FROM categorias WHERE id = ? AND (usuario_id IS NULL OR usuario_id = ?)'
    );
    $stmt->execute([$catId, $uid]);
    return (bool)$stmt->fetchColumn();
}

// ============ RATE LIMIT LOGIN ============
function login_bloqueado(PDO $pdo, string $ip, int $max = 5, int $minutos = 15): bool {
    $stmt = $pdo->prepare("
        SELECT COUNT(*) FROM intentos_login
        WHERE ip = ? AND exito = FALSE
        AND intentado_en > NOW() - INTERVAL $minutos MINUTE
    ");
    $stmt->execute([$ip]);
    return (int)$stmt->fetchColumn() >= $max;
}
function log_intento_login(PDO $pdo, string $ip, string $username, bool $exito): void {
    $stmt = $pdo->prepare(
        'INSERT INTO intentos_login (ip, username, exito) VALUES (?, ?, ?)'
    );
    $stmt->execute([$ip, $username, $exito ? 1 : 0]);
}
function client_ip(): string {
    return $_SERVER['REMOTE_ADDR'] ?? 'unknown';
}
