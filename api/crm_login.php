<?php
/**
 * crm_login.php — login de operador para crm.html (ver api/crm_auth.php).
 *
 * POST { accion:"login",  usuario, password }  -> { ok, csrf, operador }
 * POST { accion:"logout" }                     -> { ok:true }
 *
 * Rate limit propio (crm_login_limite), separado de crm_rate_limite() de
 * crm_lib.php: un login fallido no debe compartir cupo con "cancelar retiro"
 * ni ningún otro botón del CRM, y viceversa (ver el comentario en crm_lib.php
 * que reserva este nombre).
 */

declare(strict_types=1);
require __DIR__ . '/config.php';
require __DIR__ . '/db.php';
require __DIR__ . '/crm_auth.php';

header('Content-Type: application/json; charset=utf-8');

/**
 * 5 intentos cada 15 minutos, por combinación IP+usuario: alcanza para que
 * alguien se equivoque de clave un par de veces sin frenarlo, y frena un
 * ataque de fuerza bruta contra un usuario puntual.
 */
function crm_login_limite(string $clave, int $max, int $ventanaSeg): bool
{
    $f = sys_get_temp_dir() . '/crm_login_rl_' . md5($clave);
    $ahora = time();
    $hits = [];
    if (is_file($f)) {
        foreach (explode(',', (string) @file_get_contents($f)) as $t) {
            if ($t !== '' && (int) $t > $ahora - $ventanaSeg) {
                $hits[] = (int) $t;
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

$in     = json_decode(file_get_contents('php://input'), true);
$in     = is_array($in) ? $in : [];
$accion = (string) ($in['accion'] ?? '');

if ($accion === 'logout') {
    operador_logout();
    echo json_encode(['ok' => true]);
    exit;
}

if ($accion === 'login') {
    $usuario = trim((string) ($in['usuario'] ?? ''));
    $ip      = (string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');

    if (!crm_login_limite('login_' . $ip . '_' . strtolower($usuario), 5, 900)) {
        http_response_code(429);
        echo json_encode(['ok' => false, 'error' => 'Demasiados intentos. Esperá 15 minutos.']);
        exit;
    }

    if (operador_login($pdo, $usuario, (string) ($in['password'] ?? ''))) {
        echo json_encode(['ok' => true, 'csrf' => csrf_token(), 'operador' => operador_actual()]);
        exit;
    }

    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Usuario o contraseña incorrectos']);
    exit;
}

http_response_code(400);
echo json_encode(['ok' => false, 'error' => 'acción desconocida']);
