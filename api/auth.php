<?php
/**
 * auth.php — Login real del sitio (en TU dominio, no el iframe de ganamos).
 *
 * POST { accion:"registro", usuario, password }
 *      -> crea el acceso (si el usuario existe en `usuarios` y no tiene acceso aun)
 *      -> { ok, token, usuario }
 * POST { accion:"login", usuario, password }
 *      -> verifica y devuelve el token JWT
 *      -> { ok, token, usuario }  o  { ok:false, error }
 *
 * El token es un JWT firmado con JWT_SECRET; el front lo guarda en localStorage
 * como API_AUTH_ACCESS_TOKEN. Requiere JWT_SECRET en config.local.php.
 */

declare(strict_types=1);
require __DIR__ . '/config.php';
require __DIR__ . '/db.php';
require __DIR__ . '/auth_lib.php';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405); echo json_encode(['ok' => false, 'error' => 'Usa POST']); exit;
}

$secret = cfg('JWT_SECRET');
if ($secret === '' || strlen($secret) < 16) {
    http_response_code(500);
    error_log('auth: falta JWT_SECRET (min 16) en config.local.php');
    echo json_encode(['ok' => false, 'error' => 'Login sin configurar (falta JWT_SECRET)']);
    exit;
}

// --- Limite simple por IP (anti fuerza bruta) ---
function _limite(int $max, int $ventana): bool
{
    $f = sys_get_temp_dir() . '/auth_rl_' . md5($_SERVER['REMOTE_ADDR'] ?? 'x');
    $ahora = time(); $hits = [];
    if (is_file($f)) {
        foreach (explode(',', (string)@file_get_contents($f)) as $t) {
            if ($t !== '' && (int)$t > $ahora - $ventana) { $hits[] = (int)$t; }
        }
    }
    if (count($hits) >= $max) { return false; }
    $hits[] = $ahora;
    @file_put_contents($f, implode(',', $hits), LOCK_EX);
    return true;
}
if (!_limite(15, 300)) {
    http_response_code(429);
    echo json_encode(['ok' => false, 'error' => 'Demasiados intentos. Esperá unos minutos.']);
    exit;
}

$body     = json_decode(file_get_contents('php://input'), true) ?: [];
$accion   = (string)($body['accion'] ?? '');
$usuario  = trim((string)($body['usuario'] ?? ''));
$password = (string)($body['password'] ?? '');

if ($usuario === '' || $password === '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Completá usuario y contraseña']);
    exit;
}

function token_de(string $usuario, string $secret): string
{
    return jwt_crear(['username' => $usuario], $secret);
}

try {
    // ------------------------------ registro ------------------------------
    if ($accion === 'registro') {
        if (strlen($password) < 6) {
            echo json_encode(['ok' => false, 'error' => 'La contraseña debe tener al menos 6 caracteres']); exit;
        }
        // el usuario tiene que existir en la plataforma
        $st = $pdo->prepare("SELECT 1 FROM usuarios WHERE username = ? LIMIT 1");
        $st->execute([$usuario]);
        if (!$st->fetchColumn()) {
            echo json_encode(['ok' => false, 'error' => 'Ese usuario no existe en la plataforma.']); exit;
        }
        // no debe tener acceso ya
        $st = $pdo->prepare("SELECT 1 FROM accesos WHERE usuario = ? LIMIT 1");
        $st->execute([$usuario]);
        if ($st->fetchColumn()) {
            echo json_encode(['ok' => false, 'codigo' => 'ya_existe',
                'error' => 'Ese usuario ya tiene acceso. Iniciá sesión.']); exit;
        }
        $pdo->prepare("INSERT INTO accesos (usuario, password_hash) VALUES (?, ?)")
            ->execute([mb_substr($usuario, 0, 50), password_hash($password, PASSWORD_DEFAULT)]);
        echo json_encode(['ok' => true, 'usuario' => $usuario, 'token' => token_de($usuario, $secret)],
            JSON_UNESCAPED_UNICODE);
        exit;
    }

    // -------------------------------- login -------------------------------
    if ($accion === 'login') {
        $st = $pdo->prepare("SELECT password_hash FROM accesos WHERE usuario = ? LIMIT 1");
        $st->execute([$usuario]);
        $hash = $st->fetchColumn();
        if (!$hash || !password_verify($password, (string)$hash)) {
            // mismo mensaje para no filtrar si el usuario existe
            echo json_encode(['ok' => false, 'error' => 'Usuario o contraseña incorrectos.']); exit;
        }
        $pdo->prepare("UPDATE accesos SET ultimo_login = NOW() WHERE usuario = ?")->execute([$usuario]);
        // Iniciar sesion es actividad. accesos.ultimo_login ya lo guardaba,
        // pero solo lo ve este archivo: ultima_actividad es la que mira el CRM.
        if (is_file(__DIR__ . '/actividad_lib.php')) {
            require_once __DIR__ . '/actividad_lib.php';
            actividad_marcar($pdo, $usuario);
        }
        echo json_encode(['ok' => true, 'usuario' => $usuario, 'token' => token_de($usuario, $secret)],
            JSON_UNESCAPED_UNICODE);
        exit;
    }

    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'accion desconocida']);
} catch (Throwable $e) {
    error_log('auth: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Error', 'detalle' => $e->getMessage()]);
}
