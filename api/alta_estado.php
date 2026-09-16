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
    /* ACA VIAJABA LA PROMO DE LA APP junto con las credenciales, y se saco el
       14/09/2026. Nahuel: "me creo usuario y cuando entro ya me sale eso, no
       lo quiero ahi porque bloquea la primera carga".

       Lo que sigue a recibir el usuario y la contraseña es la PRIMERA CARGA,
       que es la accion mas valiosa que ese jugador va a hacer. Un modal encima
       ofreciendole fichas gratis por instalar una app cambia una carga real
       por un regalo, y encima al que todavia no demostro que paga.

       La promo no desaparecio: la sigue mandando notificaciones.php, pero solo
       a quien YA cargo al menos una vez, y el widget elige el momento bueno --
       cuando se le estan acabando las fichas jugando. No hace falta mandarla
       aca, y mandarla igual seria dejar un dato que nadie lee. */
    /* QUE EN EL CRM CONSTE QUE LA CUENTA SE ENTREGO.
       EL REPORTE (Nahuel, 16/09/2026): "el bot si me da las credenciales de
       acceso, pero cuando intento ver ese mismo chat desde el CRM, hay mensajes
       como ese de las credenciales que no estan visibles".

       Y es cierto, por como estaba hecho: ese mensaje lo DIBUJA EL WIDGET en el
       navegador del jugador (pintarVarios, widget.js) con lo que devuelve este
       endpoint. Nunca pasa por `mensajes`, asi que el CRM --que muestra esa
       tabla-- no tiene nada que mostrar. Para el operador, la conversacion
       terminaba con el bot diciendo "ya te la estoy creando" y despues nada: no
       habia forma de saber si el jugador recibio sus datos o se fue sin cuenta.

       Y SE GUARDA EL MENSAJE EXACTO QUE VIO EL JUGADOR, contraseña incluida
       (16/09/2026). Antes se anotaba un resumen --"credenciales entregadas,
       usuario X, la contraseña no se guarda"-- por miedo a dejar una clave en
       `mensajes`, que queda ahi para siempre y a la vista de cualquier agente.

       Ese miedo no se sostenia: la clave es `ALTA_CLAVE_FIJA` y vale
       '12345678' para TODOS los jugadores (altas_lib.php:46, decision de
       negocio). O sea que el resumen estaba ocultando una constante que esta
       en el codigo fuente, y a cambio el operador no podia ver lo mismo que
       tenia el jugador en pantalla. Nahuel lo pidio asi: *"me gustaria que el
       mensaje que yo vea en el chat sea exactamente el mismo que recibe el...
       tal cual lo ve el, con el usuario y la contraseña"*.

       Son los MISMOS cuatro renglones, en el mismo orden, que pinta el widget
       (`pintarVarios` en widget.js, narrarAlta). Si alguno de los dos lados
       cambia, hay que cambiar el otro: no hay una fuente unica porque el
       widget los dibuja sin pasar por `mensajes` --ese es justamente el bug
       que esto vino a tapar-- y compartir el texto obligaria a que el widget
       pida al server un string que ya sabe.

       > Si algun dia la clave deja de ser fija, ESTO HAY QUE REVISARLO: una
       > clave por jugador en el historial del CRM es otra cosa.

       Se escribe SOLO en la entrega de verdad (`password` presente): esta
       respuesta tambien vuelve con `entregada` cuando alguien recarga la
       pagina, y anotar eso llenaria el chat de notas repetidas por algo que ya
       paso una vez.

       Best-effort de punta a punta: el jugador ya tiene sus credenciales en
       pantalla cuando esto corre. Que falle la nota no puede romperle nada. */
    if (!empty($e['listo']) && !empty($e['password']) && !empty($e['usuario'])) {
        try {
            require_once __DIR__ . '/crm_lib.php';
            if (function_exists('crm_conversacion_id') && function_exists('crm_mensaje')) {
                $convId = crm_conversacion_id($pdo, $sid, (string)$e['usuario']);
                if ($convId > 0) {
                    $meta = ['tipo' => 'alta_entregada', 'alta_id' => $id,
                             'usuario' => (string)$e['usuario']];
                    foreach ([
                        '¡Listo! Ya te creé la cuenta. Anotá estos datos:',
                        'Usuario: ' . $e['usuario'],
                        'Contraseña: ' . $e['password'],
                        'Guardala bien, no te la voy a poder repetir. '
                            . 'Ya podés iniciar sesión con esos datos.',
                    ] as $linea) {
                        crm_mensaje($pdo, $convId, 'bot', $linea, $meta);
                    }
                }
            }
        } catch (Throwable $ex) {
            error_log('alta_estado: no pude anotar la entrega en el chat: ' . $ex->getMessage());
        }
    }

    echo json_encode($e, JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    // El detalle al log, nunca a la respuesta: acá contesta cualquiera.
    error_log('alta_estado: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'error']);
}
