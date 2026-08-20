<?php
/**
 * Alta de cuenta desde la landing (crear-cuenta.html). ENDPOINT PUBLICO.
 *
 *   POST                        -> {"usuario":"martin23","password":"aB3x..."}
 *                                  encola el pedido y devuelve {ok, id}
 *   GET ?id=123&usuario=martin23 -> {ok, estado, listo, fallo}
 *
 * NO lleva API key, y por eso NO puede vivir en altas_cola.php: ese endpoint
 * devuelve las contrasenas en claro de toda la cola con un GET. La landing es
 * publica; su clave la lee cualquiera que abra el inspector del navegador.
 *
 * El alta no es inmediata: esto solo deja el pedido en la tabla `altas`. El
 * que lo cumple es bot_crear_jugador.py, que sondea desde el VPS y llena el
 * formulario del panel de agentes. Por eso la landing tiene que preguntar por
 * el estado hasta que diga 'ok'.
 *
 * Requiere sql/13_cola_altas.sql y sql/14_altas_landing.sql.
 */

declare(strict_types=1);
require __DIR__ . '/config.php';
require __DIR__ . '/db.php';
require __DIR__ . '/altas_lib.php';

header('Content-Type: application/json; charset=utf-8');
// La respuesta depende del pedido y cambia sola cuando el bot avanza: si un
// proxy la cachea, la landing se queda esperando para siempre un 'pendiente'.
header('Cache-Control: no-store');

$metodo = $_SERVER['REQUEST_METHOD'];

// ---------------------------------------------------------------------------
// GET: en que anda mi pedido
// ---------------------------------------------------------------------------
if ($metodo === 'GET') {

    $id      = (int)($_GET['id'] ?? 0);
    $usuario = trim((string)($_GET['usuario'] ?? ''));

    if (!$id || $usuario === '') {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Faltan datos']);
        exit;
    }

    $r = alta_estado($pdo, $id, $usuario);
    http_response_code($r['http']);
    echo json_encode($r['cuerpo'], JSON_UNESCAPED_UNICODE);
    exit;
}

// ---------------------------------------------------------------------------
// POST: pedir la cuenta
// ---------------------------------------------------------------------------
if ($metodo === 'POST') {

    $body = json_decode(file_get_contents('php://input'), true) ?: [];
    $ip   = alta_ip();

    // El freno va ANTES de tocar nada: cada fila que entra a la cola hace que
    // el bot abra Chromium y opere el panel de verdad.
    $frenado = alta_limite_superado($pdo, $ip);
    if ($frenado !== null) {
        http_response_code(429);
        echo json_encode(['ok' => false, 'error' => $frenado], JSON_UNESCAPED_UNICODE);
        exit;
    }

    try {
        $r = alta_encolar($pdo, [
            'usuario'  => $body['usuario']  ?? '',
            'password' => $body['password'] ?? '',
            // La landing solo pide el usuario. Nombre, apellido y correo los
            // completa el bot al llenar el formulario del panel.
            'origen'   => 'landing',
            'ip'       => $ip,
        ]);
    } catch (Throwable $e) {
        // El detalle va al log, nunca a la respuesta: aca contesta cualquiera.
        error_log('crear_cuenta: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'No se pudo crear la cuenta. Probá de nuevo.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    http_response_code($r['http']);
    echo json_encode($r['cuerpo'], JSON_UNESCAPED_UNICODE);
    exit;
}

http_response_code(405);
echo json_encode(['ok' => false, 'error' => 'Metodo no permitido']);
