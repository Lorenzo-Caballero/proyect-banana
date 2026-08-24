<?php
/**
 * Alta de cuenta desde la landing (crear-cuenta.html). ENDPOINT PUBLICO.
 *
 *   POST                        -> {"usuario":"martin23","password":"aB3x..."}
 *                                  encola el pedido y devuelve {ok, id, usuario}
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
 * OJO USUARIO: lo que manda el jugador es un NOMBRE, no necesariamente el
 * username final -- alta_usuario_disponible() lo sanea y, si está ocupado,
 * le agrega un sufijo hasta encontrar uno libre. Nunca se devuelve "ese
 * usuario ya existe" acá: en un formulario público reintentar a mano es
 * fricción que no existía en la versión anterior de esta pantalla, y no
 * hace falta -- el username 'final' vuelve en la respuesta del POST para
 * que la landing lo muestre.
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

    $nombrePedido = trim((string)($body['usuario'] ?? ''));
    if ($nombrePedido === '') {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Escribí un nombre.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    try {
        // Se resuelve un username LIBRE a partir de lo que puso el jugador
        // -- ver el porqué en el docblock de arriba. alta_encolar() ya no
        // puede rechazar por "ese usuario ya existe" salvo una carrera
        // extrema entre el chequeo y el INSERT (dos pestañas a la vez con
        // el mismo nombre), que sigue cubierta por el UNIQUE de la tabla.
        $usuarioFinal = alta_usuario_disponible($pdo, $nombrePedido);

        // La clave la genera el SERVER, una distinta por cuenta. Antes la
        // mandaba el navegador y era la misma para todos ("12345678"): con eso,
        // cualquiera que supiera un nombre de usuario entraba a esa cuenta y le
        // pedia un retiro. No se acepta mas lo que venga en el body.
        $clave = alta_clave_random();

        $r = alta_encolar($pdo, [
            'usuario'  => $usuarioFinal,
            'password' => $clave,
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

    // El frontend necesita saber el username REAL que quedó encolado (puede
    // no ser el que el jugador tipeó, si ese estaba ocupado).
    if ($r['cuerpo']['ok'] ?? false) {
        $r['cuerpo']['usuario']  = $usuarioFinal;
        // La clave viaja ACA porque es la unica vez que existe en claro para
        // el jugador. La landing la guarda y la muestra recien cuando el alta
        // pasa a 'listo' (ver registro.html): nunca antes de que la cuenta
        // exista de verdad en el panel.
        $r['cuerpo']['password'] = $clave;
    }

    http_response_code($r['http']);
    echo json_encode($r['cuerpo'], JSON_UNESCAPED_UNICODE);
    exit;
}

http_response_code(405);
echo json_encode(['ok' => false, 'error' => 'Metodo no permitido']);
