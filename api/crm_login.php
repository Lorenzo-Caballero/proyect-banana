<?php
/**
 * crm_login.php — Login/logout de operadores del CRM.
 *
 * POST { accion:"login", usuario, password } -> { ok, operador, csrf }
 * POST { accion:"logout" }                   -> { ok }
 *
 * Es el ÚNICO endpoint del CRM que no exige sesión (exigir_operador()): es
 * el que la crea. Todo lo demás (crm.php, admin_usuarios.php, y lo que se
 * sume en Fase A) sí la exige.
 *
 * Requiere sql/18_operadores.sql.
 */

declare(strict_types=1);
require __DIR__ . '/config.php';
require __DIR__ . '/db.php';
require __DIR__ . '/crm_auth.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Usa POST']);
    exit;
}

/**
 * Freno de fuerza bruta por IP, archivo temporal — mismo mecanismo que ya
 * usa auth.php::_limite() (login de jugadores), pero sin tocar ese archivo:
 * es un login distinto (operadores, no jugadores), y no vale la pena rozar
 * un login que ya está en producción por compartir diez líneas.
 *
 * Umbral más duro que el de auth.php a propósito: esto protege el acceso a
 * plata real (bot/ corre en LIVE), no una cuenta de juego. 5 intentos cada
 * 15 minutos, después 429 por 15 minutos.
 *
 * Cuenta CADA intento, no sólo los fallidos: si sólo contara fallos, alguien
 * podría probar contraseñas sin límite mientras de vez en cuando acierte el
 * usuario. Simple a propósito — el volumen (2-3 operadores) no justifica
 * una tabla.
 */
function crm_login_limite(int $max, int $ventanaSeg): bool
{
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'desconocida';
    $f  = sys_get_temp_dir() . '/crm_login_rl_' . md5($ip);
    $ahora = time();
    $hits = [];
    if (is_file($f)) {
        foreach (explode(',', (string)@file_get_contents($f)) as $t) {
            if ($t !== '' && (int)$t > $ahora - $ventanaSeg) {
                $hits[] = (int)$t;
            }
        }
    }
    if (count($hits) >= $max) {
        return false;
    }
    $hits[] = $ahora;
    @file_put_contents($f, implode(',', $hits), LOCK_EX);
    return true;
}

$body   = json_decode(file_get_contents('php://input'), true) ?: [];
$accion = (string)($body['accion'] ?? 'login');

if ($accion === 'logout') {
    operador_logout();
    echo json_encode(['ok' => true]);
    exit;
}

if ($accion === 'login') {
    if (!crm_login_limite(5, 900)) {
        http_response_code(429);
        echo json_encode(['ok' => false, 'error' => 'Demasiados intentos. Esperá 15 minutos.']);
        exit;
    }

    $usuario  = trim((string)($body['usuario'] ?? ''));
    $password = (string)($body['password'] ?? '');

    if ($usuario === '' || $password === '') {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Completá usuario y contraseña']);
        exit;
    }

    if (!operador_login($pdo, $usuario, $password)) {
        http_response_code(401);
        echo json_encode(['ok' => false, 'error' => 'Usuario o contraseña incorrectos']);
        exit;
    }

    echo json_encode([
        'ok'       => true,
        'operador' => operador_actual(),
        'csrf'     => csrf_token(),
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

http_response_code(400);
echo json_encode(['ok' => false, 'error' => 'Acción desconocida']);
