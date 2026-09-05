<?php
/**
 * alta_estado.php — Estado de una cuenta pedida por el chat, y sus credenciales
 *                   UNA VEZ QUE EL BOT LA CREO DE VERDAD.
 *
 * El widget lo sondea con el id que devolvió chatbot.php al encolar el alta
 * (respuesta.alta.id) y va contando el proceso al jugador:
 *   en_curso -> "dame un minuto que la estoy creando"
 *   ok       -> usuario y contraseña, en mensajes separados
 *   error    -> "no pude crearla, te paso con un agente"
 *
 * Por qué existe (y por qué no alcanzaba con devolver la clave al encolar):
 * cuando el chat pide el alta, la cuenta TODAVÍA NO EXISTE. Queda en la cola
 * `altas` y la crea bot_crear_jugador.py contra el panel de agentes, que puede
 * fallar. Entregar las credenciales antes de esa confirmación deja al jugador
 * con un usuario y una contraseña que no entran a ningún lado.
 *
 * La clave se devuelve UNA sola vez y solo al `session_id` que pidió el alta
 * (ver alta_entrega() en altas_lib.php): sin eso, cualquiera que recorra
 * id=1,2,3... se lleva las contraseñas de los demás.
 *
 * GET ?id=N&sid=<session_id>
 *   -> { ok:true, estado:"en_curso", listo:false }
 *   -> { ok:true, estado:"ok", listo:true, usuario, password }
 *   -> { ok:true, estado:"ok", listo:true, entregada:true }   (ya la mostró)
 *   -> { ok:true, estado:"error", listo:false, fallo:true }
 *
 * Requiere la migración sql/35_alta_entrega.sql.
 */

declare(strict_types=1);
require __DIR__ . '/config.php';
require __DIR__ . '/db.php';
require __DIR__ . '/altas_lib.php';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
// La respuesta cambia sola cuando el bot avanza: si un proxy la cachea, el
// widget se queda esperando para siempre un 'en_curso'. Y la clave viaja acá:
// no puede quedar guardada en ningún intermedio.
header('Cache-Control: no-store');

$id  = (int)($_GET['id'] ?? 0);
$sid = trim((string)($_GET['sid'] ?? ''));

if (!$id || $sid === '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Faltan datos']);
    exit;
}

try {
    $e = alta_entrega($pdo, $id, $sid);
    // El que sondea es un jugador esperando su cuenta: el momento justo para
    // avisar al agente si la cola esta trabada (ver alta_avisar_trabadas).
    // Best-effort: un Telegram caido no puede romperle el sondeo al jugador.
    if (empty($e['listo']) && empty($e['fallo'])) {
        try { alta_avisar_trabadas($pdo); } catch (Throwable $ex) {}
    }
    echo json_encode($e, JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    // El detalle al log, nunca a la respuesta: acá contesta cualquiera.
    error_log('alta_estado: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'error']);
}
