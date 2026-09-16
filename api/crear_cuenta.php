<?php
/**
 * Alta de cuenta desde la landing (landing/registro.html). ENDPOINT PUBLICO.
 * (El archivo se llamaba crear-cuenta.html; el nombre viejo quedo aca.)
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
require_once __DIR__ . '/config_crm.php';
require __DIR__ . '/publicidad_lib.php';
require __DIR__ . '/landings_lib.php';

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
    $sid     = trim((string)($_GET['sid'] ?? ''));

    if (!$id || $usuario === '') {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Faltan datos']);
        exit;
    }

    // Con sid entra por el mismo camino que el chat: la clave sale UNA vez y
    // solo con el alta confirmada por el bot contra el panel. Sin sid queda el
    // comportamiento viejo (solo estado), para no romper una pestaña que haya
    // quedado abierta con el flujo anterior.
    if ($sid !== '') {
        $e = alta_entrega($pdo, $id, $sid);
        if (empty($e['ok'])) {
            http_response_code(404);
            echo json_encode(['ok' => false, 'error' => 'Pedido inexistente'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        // El que pregunta es un jugador ESPERANDO su cuenta: si la cola esta
        // trabada, este es el momento exacto de avisarle al agente (no hay
        // cron; el detalle en alta_avisar_trabadas). Despues de armar la
        // respuesta y best-effort: el sondeo del jugador no puede romperse
        // porque Telegram ande mal.
        if (empty($e['listo']) && empty($e['fallo'])) {
            try { alta_avisar_trabadas($pdo); } catch (Throwable $ex) {}
        }
        echo json_encode([
            'ok'       => true,
            'estado'   => $e['estado'],
            'listo'    => !empty($e['listo']),
            'fallo'    => !empty($e['fallo']),
            'usuario'  => $e['usuario']  ?? null,
            'password' => $e['password'] ?? null,
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $r = alta_estado($pdo, $id, $usuario);
    // Mismo aviso que arriba, para las pestañas con el flujo viejo (sin sid).
    if (empty($r['cuerpo']['listo']) && empty($r['cuerpo']['fallo'])) {
        try { alta_avisar_trabadas($pdo); } catch (Throwable $ex) {}
    }
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

    // Alta apagada desde el CRM (Configuracion -> Alta de cuentas nuevas).
    if (!cfg_crm_activo($pdo, 'registro_activo')) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'codigo' => 'registro_cerrado',
            'error' => 'Por ahora no estamos creando cuentas nuevas. Escribinos por chat.'],
            JSON_UNESCAPED_UNICODE);
        exit;
    }

    $nombrePedido = trim((string)($body['usuario'] ?? ''));
    if ($nombrePedido === '') {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Escribí un nombre.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    try {
        // El sid lo genera el navegador; se lee ACA arriba porque ademas de
        // autorizar la entrega de la clave ahora tambien deduplica.
        $sid = mb_substr(trim((string)($body['sid'] ?? '')), 0, 64);

        /* DEDUP POR SESION (16/09/2026). El chatbot tiene esta guarda; la
           landing no la tenia, y el comentario que decia que el doble-submit
           quedaba "cubierto por el UNIQUE de la tabla" era FALSO desde que
           alta_usuario_disponible() sufija SIEMPRE con digitos al azar: dos
           POST del mismo navegador (doble click, reintento de red) generan
           DOS nombres distintos, el UNIQUE no choca, y salian DOS cuentas
           -- una entregada y la otra huerfana en el panel. Si este sid ya
           tiene un alta viva o recien creada, se devuelve ESA y el front
           sigue sondeando por id+sid como siempre. Las 'error' quedan
           afuera: ahi el reintento es legitimo. */
        if ($sid !== '') {
            try {
                $qs = $pdo->prepare(
                    "SELECT id, usuario FROM altas
                      WHERE entrega_sid = ?
                        AND estado IN ('pendiente', 'procesando', 'ok')
                        AND pedido_en > (NOW() - INTERVAL 30 MINUTE)
                      ORDER BY id DESC LIMIT 1"
                );
                $qs->execute([$sid]);
                if ($prev = $qs->fetch()) {
                    echo json_encode(['ok' => true, 'id' => (int)$prev['id'],
                                      'usuario' => (string)$prev['usuario'],
                                      'repetida' => true], JSON_UNESCAPED_UNICODE);
                    exit;
                }
            } catch (Throwable $e) {
                // Sin la migracion 35 no hay entrega_sid: se sigue sin dedup,
                // como siempre.
                error_log('crear_cuenta (dedup sid): ' . $e->getMessage());
            }
        }

        // Se resuelve un username LIBRE a partir de lo que puso el jugador
        // -- ver el porqué en el docblock de arriba. La carrera entre dos
        // requests CONCURRENTES del mismo navegador la achica el dedup de
        // arriba (queda la ventana de milisegundos entre SELECT e INSERT).
        $usuarioFinal = alta_usuario_disponible($pdo, $nombrePedido);

        // La clave la genera el SERVER, una distinta por cuenta. Antes la
        // mandaba el navegador y era la misma para todos ("12345678"): con eso,
        // cualquiera que supiera un nombre de usuario entraba a esa cuenta y le
        // pedia un retiro. No se acepta mas lo que venga en el body.
        $clave = alta_clave_nueva();

        // (El sid ya se leyo arriba, antes del dedup. Sigue siendo lo unico
        // que despues autoriza a ver la clave: sin el, cualquiera que recorra
        // id=1,2,3... se lleva las credenciales de los demas.)

        // De que publicista vino el pedido (?pub=<slug> en la landing) y los
        // identificadores de Meta que agarro en el camino. Todo opcional: un
        // jugador que entro directo (sin publicista) sigue creando su cuenta
        // igual, solo que sin datos de campaña para el modulo de Publicidad.
        $pubSlug    = trim((string)($body['pub']    ?? ''));
        $publicista = $pubSlug !== '' ? publicidad_por_slug($pdo, $pubSlug) : null;
        $fbclid     = trim((string)($body['fbclid'] ?? ''));
        $fbp        = trim((string)($body['fbp']    ?? ''));
        $fbc        = trim((string)($body['fbc']    ?? ''));

        /* Por que landing entro. WHITELIST y no texto libre: el origen despues
           decide si rl_acreditar() regala un bono real en la primera carga
           (RL_BONO_BIENVENIDA_ORIGEN en recargas_lib.php), asi que aceptar
           cualquier cosa seria dejar que el navegador elija promociones. Un
           valor desconocido cae en 'landing', como siempre.

           'lp:<slug>' son las landings creadas desde el CRM (lp.html,
           migracion 52). La whitelist ahi es la tabla: solo cuenta si la
           landing existe y esta ACTIVA en este momento -- una pausada deja
           de prometer bonos, aunque el link viejo siga circulando. El bono
           concreto lo cumple recargas_lib.php con el % de esa fila. */
        $promo  = trim((string)($body['promo'] ?? ''));
        $origen = 'landing';
        if ($promo === 'bono50') {
            $origen = 'bono50';
        } elseif (preg_match('/^lp:([a-z0-9-]{1,24})$/', $promo, $mLp)
                  && landings_por_slug($pdo, $mLp[1]) !== null) {
            $origen = 'lp:' . $mLp[1];
        }

        $r = alta_encolar($pdo, [
            'usuario'  => $usuarioFinal,
            'password' => $clave,
            // La landing solo pide el usuario. Nombre, apellido y correo los
            // completa el bot al llenar el formulario del panel.
            'origen'   => $origen,
            'ip'       => $ip,
            // Con sid, la clave queda guardada para entregarla cuando el bot
            // confirme el alta. Sin sid (pestaña vieja) se sigue devolviendo
            // en el POST, como antes.
            'entrega_clave' => $sid !== '' ? $clave : '',
            'entrega_sid'   => $sid,
            'publicista_id' => $publicista['id'] ?? null,
            'fbclid'        => $fbclid !== '' ? $fbclid : null,
            'fbp'           => $fbp    !== '' ? $fbp    : null,
            'fbc'           => $fbc    !== '' ? $fbc    : null,
            /* El navegador y la URL DEL JUGADOR, que es el unico momento en que
               los tenemos: este endpoint corre dentro de su pedido, desde su
               telefono. Los eventos que valen (Purchase) los dispara despues el
               bot del VPS, donde $_SERVER es del servidor.
               Sin esto, a Meta le llegaban todas las conversiones con la misma
               IP de datacenter y un User-Agent de Python. Ver migracion 51. */
            'ua'            => mb_substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 400) ?: null,
            'url_landing'   => mb_substr((string)($_SERVER['HTTP_REFERER'] ?? ''), 0, 255) ?: null,
        ]);

        // Lead: el jugador pidio la cuenta (no que se haya creado todavia --
        // eso es CompleteRegistration, que ya dispara altas_cola.php cuando el
        // bot confirma). `ref` con el id de la landing hace el event_id
        // reproducible por si la pestaña reintenta. Nunca puede tumbar el
        /* Plan de referidos: con que codigo entro este alta (bono.html
           arrastra el ?ref= del link compartido hasta aca).

           Se valida TODO antes de guardar -- plan activo, formato, que el
           codigo exista y que su dueño no sea el mismo nombre que se esta
           creando -- porque este campo despues PAGA plata: cuando el amigo
           acredite su primera carga, referidos_lib le suma el bono al dueño
           del codigo. Un valor inventado no puede llegar a la base.

           Va en un UPDATE aparte y best-effort, no dentro de alta_encolar():
           la columna es de la migracion 53 y el alta de un amigo no puede
           morir porque una base no migro todavia -- pierde el bono del
           referidor (queda en el log), no la cuenta. */
        if (($r['cuerpo']['ok'] ?? false) && ($r['cuerpo']['id'] ?? 0)) {
            // La validacion vive en ref_anotar_en_alta (compartida con el
            // alta por chat de chatbot.php): plan activo, formato, dueño
            // real distinto del que se registra. Best-effort adentro.
            $refCod = (string)($body['ref'] ?? '');
            if ($refCod !== '') {
                require_once __DIR__ . '/referidos_lib.php';
                ref_anotar_en_alta($pdo, (int)$r['cuerpo']['id'], $refCod, $usuarioFinal);
            }
        }

        // alta: si Meta esta caido, la cuenta se crea igual.
        if (($r['cuerpo']['ok'] ?? false) && ($r['cuerpo']['id'] ?? 0)) {
            try {
                require_once __DIR__ . '/meta_lib.php';
                meta_evento($pdo, 'Lead', [
                    'usuario' => $usuarioFinal,
                    'ref'     => 'alta:' . $r['cuerpo']['id'],
                    'fbp'     => $fbp,
                    'fbc'     => $fbc,
                    'pixel'   => publicidad_pixel_propio($publicista),
                ]);
            } catch (Throwable $e) {
                error_log('meta Lead: ' . $e->getMessage());
            }
        }
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
        $r['cuerpo']['usuario'] = $usuarioFinal;
        // La clave NO viaja aca si hay sid: a esta altura la cuenta todavia no
        // existe en el panel y el bot puede fallar. Se entrega en el sondeo,
        // cuando el alta pase a 'ok'. Sin sid se devuelve, para no dejar sin
        // credenciales a una pestaña con el flujo viejo.
        if ($sid === '') {
            $r['cuerpo']['password'] = $clave;
        }
    }

    http_response_code($r['http']);
    echo json_encode($r['cuerpo'], JSON_UNESCAPED_UNICODE);
    exit;
}

http_response_code(405);
echo json_encode(['ok' => false, 'error' => 'Metodo no permitido']);
