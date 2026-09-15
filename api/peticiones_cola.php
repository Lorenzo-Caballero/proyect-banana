<?php
/**
 * peticiones_cola.php — Cerebro del "camino A": las cargas que el jugador pide
 *                       desde el boton Depositos de la plataforma.
 *
 * Auth: header X-API-Key (o X-Api-Token) = BOT_API_KEY.
 *
 * POST ?accion=evaluar
 *      { "peticiones":[{id, username, amount, name, created_at, type}, ...],
 *        "dias_ventana": 2 }
 *   -> { ok, datos:[{request_id, decision, pago_id_unico, monto, bono_pct,
 *                    confianza, motivo}] }
 *      decision: 'aprobar' | 'esperar' | 'nada'
 *
 * POST ?accion=confirmar
 *      { request_id, estado:'aprobada'|'error'|'revisar', mensaje }
 *   -> { ok, ya_cerrada? }
 *
 * POR QUE EL CRUCE SE HACE ACA Y NO EN EL WORKER
 * El worker (colector/aprobar_cargas.py) es un brazo: tiene la sesion del
 * panel y nada mas. Toda la decision vive de este lado, con la base y el
 * matcher que ya existe. Cuando esto se resolvia en Python habia DOS matchers
 * (colector/matcher.py y el de recargas_lib.php) que se fueron separando: el
 * de PHP aprendio distancia de edicion y el otro no. Un solo matcher.
 *
 * ACA SE MUEVE PLATA. Las mismas reglas que en acciones_cola.php:
 *
 *   - Se RECLAMA la transferencia antes de aprobar, con el UNIQUE de
 *     peticiones_carga.pago_id_unico. Dos solicitudes no pueden cobrarse la
 *     misma transferencia.
 *   - 'error' (el panel rechazo, 4xx) suelta el reclamo: la transferencia
 *     vuelve a estar disponible.
 *   - 'revisar' (5xx, timeout: no sabemos si entro) NO lo suelta y no se
 *     reintenta. Reintentar una aprobacion que quizas entro es acreditar dos
 *     veces.
 *   - Lo ambiguo nunca se aprueba solo.
 *
 * Requiere sql/48_peticiones_carga.sql.
 */

declare(strict_types=1);
require __DIR__ . '/config.php';
require __DIR__ . '/db.php';
// recargas_lib carga varias libs por su cuenta: TODO require_once, si no salta
// "Cannot redeclare". Ya nos tiro el chat abajo dos veces.
require_once __DIR__ . '/peticiones_lib.php';   // trae recargas_lib
require_once __DIR__ . '/fichas_lib.php';
$actividadLib = __DIR__ . '/actividad_lib.php';
if (is_file($actividadLib)) { require_once $actividadLib; }
$notifLib = __DIR__ . '/notificaciones_lib.php';
if (is_file($notifLib)) { require_once $notifLib; }

header('Content-Type: application/json; charset=utf-8');

$key = cfg('BOT_API_KEY');
$enviada = $_SERVER['HTTP_X_API_KEY'] ?? $_SERVER['HTTP_X_API_TOKEN'] ?? '';
if ($key === '' || strlen($key) < 16 || !hash_equals($key, $enviada)) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'No autorizado']);
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Usa POST']);
    exit;
}

// PC_GRACIA_ANTES_MIN, PC_TIPO_DEPOSITO y pc_elegir_pago() viven en
// peticiones_lib.php para poder probarlos (este archivo autentica al incluirse).

$accion = (string)($_GET['accion'] ?? '');
$body   = json_decode(file_get_contents('php://input'), true) ?: [];

try {
    // ------------------------- evaluar ----------------------------
    if ($accion === 'evaluar') {

        if (!array_key_exists('peticiones', $body) || !is_array($body['peticiones'])) {
            // Sin la clave no se toca nada: "no vino nada" no puede significar
            // "ganamos no tiene ninguna solicitud abierta" y disparar el cierre
            // masivo de abajo.
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'Falta peticiones']);
            exit;
        }
        $dias = (int)($body['dias_ventana'] ?? 2);
        if ($dias < 1 || $dias > 30) { $dias = 2; }

        $bonoPct = 0;
        if (function_exists('fichas_limite')) {
            $bonoPct = fichas_limite($pdo, 'lim_bono_carga_pct', 0);
            if ($bonoPct < 0 || $bonoPct > 100) { $bonoPct = 0; }
        }

        $pdo->beginTransaction();

        // 1) Espejar lo que manda el panel. `primera_vez` se fija al insertar y
        //    no se pisa nunca: es el ancla de la regla de temporalidad.
        $up = $pdo->prepare(
            "INSERT INTO peticiones_carga
                    (request_id, username, titular, monto, alias_destino, creada_api)
             VALUES (?,?,?,?,?,?)
             ON DUPLICATE KEY UPDATE username = VALUES(username),
                                     titular  = VALUES(titular),
                                     monto    = VALUES(monto),
                                     alias_destino = VALUES(alias_destino),
                                     creada_api    = VALUES(creada_api)"
        );
        $vistas = [];
        foreach ($body['peticiones'] as $p) {
            $rid  = (int)($p['id'] ?? 0);
            $usr  = trim((string)($p['username'] ?? ''));
            $mon  = (float)($p['amount'] ?? 0);
            $tipo = $p['type'] ?? null;
            // Un retiro aprobado por error saca plata. Se saltea siempre,
            // aunque el worker ya deberia haberlo filtrado.
            if ($rid <= 0 || $usr === '' || $mon <= 0) { continue; }
            if ($tipo !== null && (int)$tipo !== PC_TIPO_DEPOSITO) { continue; }
            $up->execute([
                $rid, mb_substr($usr, 0, 60),
                mb_substr(trim((string)($p['name'] ?? '')), 0, 160),
                $mon,
                mb_substr(trim((string)($p['cbu'] ?? '')), 0, 120),
                mb_substr(trim((string)($p['created_at'] ?? '')), 0, 40),
            ]);
            $vistas[] = $rid;
        }

        /* 2) Las que teniamos esperando y ganamos ya no lista: alguien las
              resolvio por fuera (un agente aprobo o rechazo a mano). Se cierran
              y SE SUELTA la transferencia que tuvieran reclamada, si no quedaria
              trabada para siempre.

              Solo dentro de la ventana que el worker realmente miro: una
              solicitud mas vieja que eso no aparece en el listado por la fecha,
              no porque se haya resuelto. */
        $cerradas = 0;
        $sqlCerrar = "UPDATE peticiones_carga
                         SET estado = 'cerrada', pago_id_unico = NULL,
                             motivo = 'se resolvio fuera del CRM'
                       WHERE estado = 'esperando'
                         AND primera_vez >= DATE_SUB(NOW(), INTERVAL " . ($dias + 1) . " DAY)";
        if ($vistas) {
            $marcas = implode(',', array_fill(0, count($vistas), '?'));
            $st = $pdo->prepare($sqlCerrar . " AND request_id NOT IN ($marcas)");
            $st->execute($vistas);
        } else {
            $st = $pdo->prepare($sqlCerrar);
            $st->execute();
        }
        $cerradas = $st->rowCount();

        // 3) Decidir sobre cada una que siga esperando.
        $abiertasPorMonto = [];
        $qa = $pdo->query(
            "SELECT ROUND(monto*100) AS c, COUNT(*) AS n
               FROM peticiones_carga WHERE estado = 'esperando' GROUP BY c"
        );
        foreach ($qa->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $abiertasPorMonto[(string)$r['c']] = (int)$r['n'];
        }

        /* Candidatas: transferencias no consumidas (ni por una recarga del
           camino B, ni reclamadas por otra solicitud), del monto exacto, y que
           entraron despues de que la solicitud aparecio.

           El LEFT JOIN contra peticiones_carga es lo que hace que dos
           solicitudes no se peleen la misma plata. Va sin COLLATE a proposito:
           las dos tablas comparten collation (ver sql/48). */
        $qc = $pdo->prepare(
            "SELECT p.id_unico, p.monto, p.remitente, p.cuit, p.cbu_origen, p.capturado_en
               FROM pagos p
               LEFT JOIN peticiones_carga q ON q.pago_id_unico = p.id_unico
              WHERE p.estado IN ('pendiente','revision')
                AND p.recarga_id IS NULL
                AND q.request_id IS NULL
                AND p.monto IS NOT NULL
                AND ROUND(p.monto * 100) = ?
                AND p.capturado_en >= DATE_SUB(?, INTERVAL " . PC_GRACIA_ANTES_MIN . " MINUTE)
              ORDER BY p.capturado_en ASC"
        );
        $reclamar = $pdo->prepare(
            "UPDATE peticiones_carga SET pago_id_unico = ?, confianza = ?, motivo = ?
              WHERE request_id = ? AND pago_id_unico IS NULL AND estado = 'esperando'"
        );
        $anotar = $pdo->prepare(
            "UPDATE peticiones_carga SET motivo = ?, estado = ?
              WHERE request_id = ? AND estado = 'esperando'"
        );

        /* rechazo_pedido_en es de la migracion 63: si falta, se sigue sin la
           columna y simplemente no hay rechazos que ejecutar. */
        try {
            $pend = $pdo->query(
                "SELECT request_id, username, titular, monto, primera_vez, pago_id_unico,
                        rechazo_pedido_en
                   FROM peticiones_carga WHERE estado = 'esperando' ORDER BY primera_vez ASC"
            )->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            $pend = $pdo->query(
                "SELECT request_id, username, titular, monto, primera_vez, pago_id_unico,
                        NULL AS rechazo_pedido_en
                   FROM peticiones_carga WHERE estado = 'esperando' ORDER BY primera_vez ASC"
            )->fetchAll(PDO::FETCH_ASSOC);
        }

        $datos = [];
        foreach ($pend as $q) {
            $rid    = (int)$q['request_id'];
            $centavos = (string)round((float)$q['monto'] * 100);

            /* UN OPERADOR PIDIO RECHAZARLA. Va PRIMERO, antes de buscarle
               transferencia: si se evaluara despues, una solicitud marcada para
               rechazo podria reclamar un pago en la misma pasada y quedar
               reclamando plata que despues se cancela.
               Y si ya tenia un pago reclamado, NO se rechaza: el jugador pago y
               lo que corresponde es aprobar. Es la misma guarda que hace el CRM
               al aceptar el pedido, repetida aca porque entre una cosa y la
               otra pudo entrar la transferencia. */
            if (!empty($q['rechazo_pedido_en'])) {
                if (!empty($q['pago_id_unico'])) {
                    $datos[] = ['request_id' => $rid, 'decision' => 'nada',
                                'usuario' => (string)$q['username'],
                                'monto' => (float)$q['monto'],
                                'motivo' => 'se pidio rechazarla pero YA tiene transferencia: '
                                          . 'miralo, corresponde aprobar'];
                    continue;
                }
                $datos[] = ['request_id' => $rid, 'decision' => 'rechazar',
                            'usuario' => (string)$q['username'],
                            'monto' => (float)$q['monto'],
                            'motivo' => 'rechazo pedido desde el CRM'];
                continue;
            }

            // Ya tenia una transferencia reclamada de una vuelta anterior (el
            // worker se corto entre evaluar y confirmar): se le devuelve la
            // misma, no se busca otra.
            if (!empty($q['pago_id_unico'])) {
                $datos[] = ['request_id' => $rid, 'decision' => 'aprobar',
                            'pago_id_unico' => (string)$q['pago_id_unico'],
                            'usuario' => (string)$q['username'],
                            'monto' => (float)$q['monto'], 'bono_pct' => $bonoPct,
                            'confianza' => 'alta',
                            'motivo' => 'ya tenia la transferencia reclamada'];
                continue;
            }

            $qc->execute([$centavos, (string)$q['primera_vez']]);
            $cands = $qc->fetchAll(PDO::FETCH_ASSOC);

            $abiertas = $abiertasPorMonto[$centavos] ?? 1;
            [$pago, $conf, $motivo] = pc_elegir_pago(
                $pdo, $cands, (string)$q['username'], (string)($q['titular'] ?? ''), $abiertas
            );

            if ($pago !== null) {
                $reclamar->execute([$pago['id_unico'], $conf, mb_substr($motivo, 0, 250), $rid]);
                if ($reclamar->rowCount() === 0) {
                    // Otro se la llevo entre el SELECT y el UPDATE.
                    $datos[] = ['request_id' => $rid, 'decision' => 'esperar',
                                'motivo' => 'la transferencia se la llevo otra solicitud'];
                    continue;
                }
                $datos[] = ['request_id' => $rid, 'decision' => 'aprobar',
                            'pago_id_unico' => (string)$pago['id_unico'],
                            'usuario' => (string)$q['username'],
                            'monto' => (float)$q['monto'], 'bono_pct' => $bonoPct,
                            'confianza' => $conf, 'motivo' => $motivo];
                continue;
            }

            /* Sin respaldo. Se separan dos cosas que no son lo mismo:

               - ambiguo -> 'revision'. Necesita una persona y NO se vuelve a
                 evaluar solo: que aparezca otra transferencia no despeja el
                 empate, y reintentar seria empeorarlo.
               - todavia no llego la plata -> sigue 'esperando'. Si el mail del
                 banco se demora, la proxima vuelta la agarra. El CRM muestra
                 hace cuanto espera cada una; no se rechaza nada solo. */
            $esAmbiguo = pc_es_ambiguo($motivo);
            $anotar->execute([mb_substr($motivo, 0, 250),
                              $esAmbiguo ? 'revision' : 'esperando', $rid]);
            $datos[] = ['request_id' => $rid,
                        'decision' => $esAmbiguo ? 'nada' : 'esperar',
                        'motivo' => $motivo];
        }

        $pdo->commit();
        echo json_encode(['ok' => true, 'cerradas' => $cerradas, 'datos' => $datos],
                         JSON_UNESCAPED_UNICODE);
        exit;
    }

    // ------------------------- confirmar --------------------------
    if ($accion === 'confirmar') {

        $rid     = (int)($body['request_id'] ?? 0);
        $estado  = (string)($body['estado'] ?? '');
        $mensaje = mb_substr((string)($body['mensaje'] ?? ''), 0, 250);

        /* 'cerrada' la manda el worker cuando cancelo la solicitud en ganamos
           (rechazo pedido desde el CRM). Es el mismo estado que usa el cierre
           automatico: la solicitud ya no existe del lado de la plataforma. */
        if (!$rid || !in_array($estado, ['aprobada', 'error', 'revisar', 'cerrada'], true)) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'Datos invalidos']);
            exit;
        }

        $pdo->beginTransaction();

        $q = $pdo->prepare(
            "SELECT * FROM peticiones_carga WHERE request_id = ? AND estado = 'esperando' FOR UPDATE"
        );
        $q->execute([$rid]);
        $pet = $q->fetch(PDO::FETCH_ASSOC);

        if (!$pet) {
            // Ya se cerro. El worker reintenta los POST, y un 'confirmar'
            // repetido no puede volver a acreditar.
            $pdo->commit();
            echo json_encode(['ok' => true, 'ya_cerrada' => true]);
            exit;
        }

        if ($estado === 'error') {
            /* El panel rechazo el pedido: no entro. Se suelta la transferencia
               para que pueda respaldar otra solicitud o asignarse a mano. */
            $pdo->prepare(
                "UPDATE peticiones_carga SET estado='error', pago_id_unico=NULL, motivo=?
                  WHERE request_id = ?"
            )->execute([$mensaje !== '' ? $mensaje : 'el panel rechazo la aprobacion', $rid]);
            $pdo->commit();
            echo json_encode(['ok' => true, 'liberado' => true]);
            exit;
        }

        if ($estado === 'revisar') {
            /* No sabemos si entro. Se MANTIENE el reclamo sobre la
               transferencia: soltarla podria acreditarsela a otro mientras esta
               ya se aprobo. Lo mira una persona. */
            $pdo->prepare(
                "UPDATE peticiones_carga SET estado='revision', motivo=? WHERE request_id = ?"
            )->execute([$mensaje !== '' ? $mensaje : 'no se pudo confirmar la aprobacion', $rid]);
            $pdo->commit();
            echo json_encode(['ok' => true, 'a_revision' => true]);
            exit;
        }

        /* ---- cerrada: LA RECHAZAMOS EN GANAMOS ----
           ESTA RAMA NO EXISTIA, y era un agujero serio. 'cerrada' pasaba la
           validacion de arriba y caia derecho en el bloque de `aprobada`: una
           carga que el operador RECHAZO quedaba con estado='aprobada' y con su
           linea en `movimientos` (origen='peticion', monto positivo) -- que es
           justo lo que Finanzas cuenta como ingreso. O sea que rechazar una
           carga la sumaba a la facturacion.

           Lo que corresponde: cerrarla, NO registrar ningun movimiento (no
           entro un peso), soltar cualquier transferencia que tuviera reclamada
           para que pueda respaldar otra solicitud, y BAJAR la marca del pedido
           de rechazo -- si no, el cartel "Rechazo en camino" se queda para
           siempre aunque el rechazo ya se haya hecho. Eso ultimo fue lo que
           reporto Nahuel: "que cuando se rechace ahi no quede eternamente en
           rechazo en camino".

           No se toca `usuarios.coins` ni el saldo: no hubo plata. */
        if ($estado === 'cerrada') {
            $soltar = (string)($pet['pago_id_unico'] ?? '');
            $sql = "UPDATE peticiones_carga
                       SET estado = 'cerrada', pago_id_unico = NULL, motivo = ?";
            /* rechazo_pedido_en es de la migracion 63: si no esta, se cierra
               igual -- una columna que falta no puede dejar la solicitud
               colgada. */
            try {
                $pdo->prepare($sql . ", rechazo_pedido_en = NULL WHERE request_id = ?")
                    ->execute([$mensaje !== '' ? $mensaje : 'rechazada en ganamos', $rid]);
            } catch (Throwable $e) {
                $pdo->prepare($sql . " WHERE request_id = ?")
                    ->execute([$mensaje !== '' ? $mensaje : 'rechazada en ganamos', $rid]);
            }
            /* La transferencia que tuviera reclamada vuelve a estar disponible.
               En teoria no deberia haber ninguna --el CRM y el worker se niegan
               a rechazar una solicitud con pago reclamado-- pero entre esas dos
               guardas y este punto pudo entrar, y dejarla marcada la sacaria de
               circulacion sin que nadie sepa por que. */
            if ($soltar !== '') {
                try {
                    $pdo->prepare(
                        "UPDATE pagos SET estado='revision' WHERE id_unico = ? AND estado <> 'usado'"
                    )->execute([$soltar]);
                } catch (Throwable $e) {
                    error_log('peticiones_cola/rechazo: no pude soltar el pago: ' . $e->getMessage());
                }
            }
            $pdo->commit();
            echo json_encode(['ok' => true, 'rechazada' => true]);
            exit;
        }

        // ---- aprobada ----
        $usuario = (string)$pet['username'];
        $monto   = (float)$pet['monto'];
        $idUnico = (string)($pet['pago_id_unico'] ?? '');

        $pdo->prepare(
            "UPDATE peticiones_carga SET estado='aprobada', motivo=? WHERE request_id = ?"
        )->execute([$mensaje !== '' ? $mensaje : (string)$pet['motivo'], $rid]);

        /* La transferencia queda consumida. `recarga_id` se deja NULL a
           proposito: no hubo recarga nuestra, la carga se pidio del otro lado.
           `asignado_por` deja la marca de quien la resolvio. */
        if ($idUnico !== '') {
            $pdo->prepare(
                "UPDATE pagos SET estado='usado', asignado_por=?, asignado_en=NOW()
                  WHERE id_unico = ?"
            )->execute(['camino-a:' . $rid, $idUnico]);
        }

        /* NO se toca `usuarios.coins`. En este camino la plata la acredita la
           plataforma sobre el saldo real, que sync_usuarios.py espeja a
           `usuarios.balance`. Sumar coins ademas le duplicaria el saldo en
           pantalla. Lo que si queda es la linea del historial: sin esto, el
           agente abre la ficha del jugador y no ve la carga por ningun lado. */
        try {
            $pdo->prepare(
                "INSERT INTO movimientos (usuario, tipo, monto, motivo, origen)
                 VALUES (?, 'saldo', ?, ?, 'peticion')"
            )->execute([
                // `monto` es BIGINT (migracion 05): va entero. La plataforma
                // trabaja en pesos enteros, asi que no se pierde nada.
                mb_substr($usuario, 0, 50), (int)round($monto),
                'Carga pedida desde la plataforma (#' . $rid . ') aprobada',
            ]);
        } catch (Throwable $e) {
            error_log('peticiones_cola: no pude registrar el movimiento: ' . $e->getMessage());
        }

        // Aprender de que cuenta paga este jugador: el proximo pago suyo se
        // resuelve por huella y no depende de como escriba el nombre.
        if ($idUnico !== '') {
            try {
                $pg = $pdo->prepare("SELECT cuit, cbu_origen, remitente FROM pagos WHERE id_unico = ? LIMIT 1");
                $pg->execute([$idUnico]);
                if ($datosPago = $pg->fetch()) {
                    rl_aprender_huella($pdo, $usuario, $datosPago);
                }
            } catch (Throwable $e) {
                error_log('peticiones_cola: no pude aprender la huella: ' . $e->getMessage());
            }
        }

        if (function_exists('actividad_marcar')) { actividad_marcar($pdo, $usuario); }

        $pdo->commit();

        /* Todo lo que sigue va DESPUES del commit: son llamadas HTTP a Meta y
           escrituras de avisos, y ninguna puede retener los locks de arriba ni
           hacer que una carga ya acreditada parezca fallida. */
        if (function_exists('notif_crear')) {
            notif_crear(
                $pdo, $usuario, '¡Fichas acreditadas!',
                'Ya te cargamos ' . number_format($monto, 0, ',', '.') . ' fichas. A jugar.',
                'fichas', null, 'carga'
            );
        }

        // Purchase de Meta: plata real acreditada, mismo criterio que en
        // acciones_cola.php -- se reporta cuando entro, no cuando se pidio.
        try {
            require_once __DIR__ . '/meta_lib.php';
            require_once __DIR__ . '/publicidad_lib.php';
            $atrib = publicidad_atribucion_por_usuario($pdo, $usuario);
            meta_evento($pdo, 'Purchase', [
                'usuario' => $usuario,
                'valor'   => $monto,
                'ref'     => 'peticion:' . $rid,
                'fbp'     => $atrib['fbp'],
                'fbc'     => $atrib['fbc'],
                // Del jugador, no de quien dispara este evento.
                'ip'      => $atrib['ip'] ?? '',
                'ua'      => $atrib['ua'] ?? '',
                'url'     => $atrib['url'] ?? '',
                'pixel'   => publicidad_pixel_propio($atrib['publicista']),
            ]);
        } catch (Throwable $e) {
            error_log('peticiones_cola meta Purchase: ' . $e->getMessage());
        }

        echo json_encode(['ok' => true]);
        exit;
    }

    /* ---------------------------------------------------------------------
       avisar_pendientes: las transferencias que siguen sin resolverse.

       Vive ACA y no en un cron propio a proposito. El worker que pega esta
       llamada es el mismo que acaba de aprobar las cargas del panel, asi que
       cuando corre ya paso lo unico que podia resolverlas solas. Un cron
       aparte tendria que adivinar cuanto esperar; este no.

       Ver rl_avisar_revision_vieja() para por que el aviso no sale cuando
       entra el pago.
       --------------------------------------------------------------------- */
    if ($accion === 'avisar_pendientes') {
        $body    = json_decode(file_get_contents('php://input'), true) ?: [];
        $minutos = (int)($body['minutos'] ?? 3);
        $n = function_exists('rl_avisar_revision_vieja')
            ? rl_avisar_revision_vieja($pdo, $minutos)
            : 0;

        /* Tambien las ALTAS trabadas. Los sondeos de la landing y el widget ya
           lo chequean, pero solo mientras el jugador tiene la pestaña abierta:
           si se fue, nadie mas pregunta. Esta pasada del worker es el respaldo
           que no depende de ningun navegador (y tampoco del bot de altas, que
           es justo el que puede estar caido). */
        $nAltas = 0;
        try {
            require_once __DIR__ . '/altas_lib.php';
            if (function_exists('alta_avisar_trabadas')) {
                $nAltas = alta_avisar_trabadas($pdo);
            }
        } catch (Throwable $e) {
            error_log('peticiones_cola avisar altas: ' . $e->getMessage());
        }

        echo json_encode(['ok' => true, 'avisados' => $n, 'altas_avisadas' => $nAltas]);
        exit;
    }

    /* ---------------------------------------------------------------------
       avisar_retiros: los RETIROS que el jugador pide desde la plataforma
       (boton "Retiros" dentro del juego).

       Este worker NO los aprueba -- aprobar un retiro SACA plata y eso lo
       decide una persona -- pero hasta ahora nadie se enteraba de que habia uno
       esperando: el worker los filtraba y seguia de largo. Aca se avisa por
       Telegram UNA vez por solicitud (dedup 'retiro_panel:<id>'; la misma
       solicitud vuelve a listarse cada minuto hasta que un agente la resuelve,
       asi que sin el dedup serian decenas de avisos por el mismo retiro).

       Es el gemelo del aviso de retiro por chat (fichas_lib -> tg_evento
       'retiro'): el jugador puede pedir el retiro por el chat o por el boton de
       la plataforma, y los dos caminos tienen que avisar. Mismo toggle
       (tg_ev_retiro), asi que se prenden/apagan juntos. */
    if ($accion === 'avisar_retiros') {
        $lista = (isset($body['retiros']) && is_array($body['retiros'])) ? $body['retiros'] : [];
        if (!function_exists('tg_evento')) {
            $tl = __DIR__ . '/telegram_lib.php';
            if (is_file($tl)) { require_once $tl; }
        }
        /* ESPEJAR, no solo avisar. Hasta ahora esto mandaba el Telegram y no
           guardaba nada, asi que un retiro pedido dentro del juego existia
           EXACTAMENTE durante el tiempo que el aviso tardaba en perderse en el
           grupo. Y es el canal con mas volumen: en produccion el ultimo retiro
           por chat es del 19/08/2026 y sin embargo llegan pedidos seguido.
           Ahora quedan en `retiros_panel` (migracion 64) y se ven en el CRM.
           Best-effort: si la migracion no corrio, se sigue avisando igual --
           perder el espejo es aceptable, perder el aviso no. */
        $espejo = null;
        $vistosRet = [];
        try {
            $espejo = $pdo->prepare(
                "INSERT INTO retiros_panel
                        (request_id, username, titular, monto, destino, creada_api,
                         primera_vez, actualizada_en)
                 VALUES (?,?,?,?,?,?, NOW(), NOW())
                 ON DUPLICATE KEY UPDATE username = VALUES(username),
                                         titular  = VALUES(titular),
                                         monto    = VALUES(monto),
                                         destino  = VALUES(destino),
                                         creada_api = VALUES(creada_api),
                                         actualizada_en = NOW()"
            );
        } catch (Throwable $e) {
            error_log('avisar_retiros: sin espejo (falta la migracion 64): ' . $e->getMessage());
        }

        $avisados = 0;
        foreach ($lista as $r) {
            if (!is_array($r)) { continue; }
            $rid = (int)($r['id'] ?? 0);
            if ($rid <= 0 || !function_exists('tg_evento')) { continue; }
            $usr = trim((string)($r['username'] ?? ''));
            $mon = (float)($r['amount'] ?? 0);
            /* Mismo criterio que el aviso del chat: el mensaje tiene que
               alcanzar para pagarle sin abrir nada mas. El CBU va como bloque
               de codigo para copiarlo de un toque. */
            $cbu = trim((string)($r['cbu'] ?? ''));
            $ok = tg_evento($pdo, 'retiro', '💸 Pedido de retiro (desde el juego)', [
                'Jugador'   => $usr !== '' ? $usr : '(sin nombre)',
                'Quiere'    => '$' . number_format($mon, 0, ',', '.'),
                'A nombre de' => trim((string)($r['name'] ?? '')),
                'CBU/alias' => $cbu !== '' ? ['code' => $cbu]
                                           : 'NO LO DEJÓ — pedíselo antes de pagar',
                'Pedido'    => trim((string)($r['created_at'] ?? '')),
                'Qué hacer' => 'Resolvelo en el panel de agentes. El CRM se entera solo.',
            ], 'retiro_panel:' . $rid);
            if ($ok) { $avisados++; }

            if ($espejo) {
                try {
                    $espejo->execute([$rid, mb_substr($usr, 0, 60),
                        mb_substr(trim((string)($r['name'] ?? '')), 0, 160) ?: null,
                        $mon,
                        mb_substr(trim((string)($r['cbu'] ?? '')), 0, 120) ?: null,
                        mb_substr(trim((string)($r['created_at'] ?? '')), 0, 40) ?: null]);
                    $vistosRet[] = $rid;
                } catch (Throwable $e) {
                    error_log('avisar_retiros/espejo: ' . $e->getMessage());
                }
            }
        }

        /* Los que TENIAMOS abiertos y el panel ya no lista: alguien los pago o
           los rechazo ahi. Se cierran solos, igual que las cargas del juego.

           SE CIERRA TAMBIEN CON LA LISTA VACIA, y eso es deliberado: que no
           haya ninguno pendiente es justamente el caso en que TODOS se
           resolvieron. Es seguro porque el worker solo llama a este endpoint
           DESPUES de leer el panel con exito -- si la lectura falla, no llama y
           no se cierra nada. (Por eso tambien se saco el `return` temprano del
           worker cuando la lista venia vacia: sin esa llamada, el ultimo retiro
           en resolverse quedaba abierto para siempre.) */
        if ($espejo) {
            try {
                if ($vistosRet) {
                    $marcas = implode(',', array_fill(0, count($vistosRet), '?'));
                    $st = $pdo->prepare(
                        "UPDATE retiros_panel SET estado='cerrado', actualizada_en=NOW()
                          WHERE estado='abierto' AND request_id NOT IN ($marcas)");
                    $st->execute($vistosRet);
                } else {
                    $st = $pdo->prepare(
                        "UPDATE retiros_panel SET estado='cerrado', actualizada_en=NOW()
                          WHERE estado='abierto'");
                    $st->execute();
                }
            } catch (Throwable $e) {
                error_log('avisar_retiros/cerrar: ' . $e->getMessage());
            }
        }

        echo json_encode(['ok' => true, 'avisados' => $avisados,
                          'espejados' => count($vistosRet)]);
        exit;
    }

    /* Decir CUALES son las acciones validas, no solo que esta mal. La primera
       corrida del worker fallo justo aca -- posteaba sin `?accion=evaluar` -- y
       "accion desconocida" a secas no daba ninguna pista de si el problema era
       el nombre, el metodo o la URL. */
    http_response_code(400);
    $validas = 'evaluar, confirmar, avisar_pendientes o avisar_retiros';
    echo json_encode(['ok' => false,
                      'error' => $accion === ''
                          ? "falta ?accion= en la URL (esperaba $validas)"
                          : "accion desconocida: '$accion' (esperaba $validas)"]);

} catch (Throwable $e) {
    if ($pdo->inTransaction()) { $pdo->rollBack(); }
    error_log('peticiones_cola: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Error', 'detalle' => $e->getMessage()]);
}
