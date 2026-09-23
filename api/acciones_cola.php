<?php
/**
 * acciones_cola.php — Cola de acciones de SALDO para el bot del VPS.
 *
 * El sitio encola en `acciones_saldo` (el chatbot al canjear fichas, el CRM a
 * mano) y bot_cargar_fichas.py las ejecuta en el panel de agentes, porque el
 * MySQL de Hostinger no acepta conexiones remotas.
 *
 * Auth:  header  X-API-Key = BOT_API_KEY.
 *
 * GET  ?accion=pendientes[&limite=20]  -> RECLAMA (pasa a 'procesando') y devuelve
 * POST ?accion=marcar   body {id, estado:'hecha'|'error'|'revisar', mensaje}
 * POST ?accion=liberar                 -> destraba las que quedaron 'procesando'
 *
 * ACA SE MUEVE PLATA. Tres reglas que no hay que aflojar:
 *
 *   - Se reclama ANTES de entregar. Si dos consumidores leen la misma fila
 *     'pendiente' (el colector viejo y este bot, por ejemplo), depositan los
 *     dos y el jugador cobra doble.
 *   - 'error' devuelve las fichas; 'revisar' NO. 'revisar' significa "el bot no
 *     pudo confirmar si el deposito entro": devolver ahi es regalar plata.
 *   - Una accion que se cayo a la mitad NO vuelve sola a 'pendiente'. Vuelve a
 *     'revisar', porque reintentar un deposito que quizas entro es depositar
 *     dos veces. Destrabar es una decision de una persona (?accion=liberar).
 *
 * Requiere sql/15_fichas_al_panel.sql.
 */

declare(strict_types=1);

/* Cuantas veces vuelve sola a la cola una accion que se sabe que NO se ejecuto
   (hoy: solo el challenge del WAF). La cola corre cada minuto, asi que son ~5
   minutos de insistir antes de pedir ayuda humana. Mas que esto ya no es un
   corte pasajero y conviene que lo mire alguien. */
const ACCION_REINTENTOS_MAX = 5;

require __DIR__ . '/config.php';
require __DIR__ . '/db.php';
require __DIR__ . '/fichas_lib.php';
// Opcional: si notificaciones_lib.php no esta subido, la cola sigue andando y
// simplemente no se avisa. Una carga acreditada nunca puede fallar por el aviso.
$notifLib = __DIR__ . '/notificaciones_lib.php';
if (is_file($notifLib)) { require_once $notifLib; }
/* Igual de opcional, y por el mismo motivo: si falta, la cola sigue y solo se
   pierde el aviso. Hace falta aca para avisar cuando un deposito NO sale --
   sin este require, tg_evento no existe y el aviso se cae en silencio, que es
   exactamente el problema que vino a resolver. config_crm es su dependencia
   (decide si el tipo de aviso esta prendido). */
foreach (['/config_crm.php', '/telegram_lib.php'] as $opc) {
    if (is_file(__DIR__ . $opc)) { require_once __DIR__ . $opc; }
}

header('Content-Type: application/json; charset=utf-8');
exigir_api_key();   // corta si la X-API-Key no coincide con BOT_API_KEY

const MINUTOS_COLGADA = 15;   // una accion 'procesando' mas vieja que esto se revisa

$accion = (string)($_GET['accion'] ?? 'pendientes');
$metodo = $_SERVER['REQUEST_METHOD'];

try {
    // ------------------------- pendientes -------------------------
    if ($accion === 'pendientes') {

        /* LATIDO del loop de CARGAS, gemelo del de altas (altas_cola.php).
           Es el discriminador que falto el 10/9: el latido de altas fresco
           decia "el bot vive", pero un bot VIEJO (pre-14e0d9b) crea altas y
           NO tiene loop de depositos -- las cargas del CRM quedaban
           'pendiente' para siempre sin que nada lo delatara. salud_bot.php
           muestra los dos latidos: altas fresco + cargas nulo/viejo = el
           contenedor corre una imagen sin loop de depositos (deploy-bot).
           Best-effort: jamas frena la cola. */
        try {
            require_once __DIR__ . '/config_crm.php';
            cfg_crm_guardar($pdo, ['bot_cargas_visto_en' => date('Y-m-d H:i:s')], 'bot');
        } catch (Throwable $e) {
            error_log('acciones_cola: no pude anotar el latido: ' . $e->getMessage());
        }

        // Las que quedaron colgadas (el bot se cayo con el navegador abierto)
        // van a 'revisar', NO a 'pendiente': no sabemos si alcanzo a depositar.
        $pdo->exec(
            "UPDATE acciones_saldo
                SET estado  = 'revisar',
                    mensaje = 'El bot se cortó mientras la ejecutaba. Verificar en el panel.'
              WHERE estado = 'procesando'
                AND tomada_en < DATE_SUB(NOW(), INTERVAL " . MINUTOS_COLGADA . " MINUTE)"
        );

        $limite = (int)($_GET['limite'] ?? 20);
        if ($limite < 1 || $limite > 100) { $limite = 20; }

        $pdo->beginTransaction();

        // Los RETIROS necesitan aprobacion de un agente (aprobado=1) antes de
        // ejecutarse; las cargas se toman igual que siempre. Ver migracion
        // sql/24_retiro_aprobar.sql y crm_retiros.php?accion=aprobar.
        /* QUE TIPO SE ENTREGA. Hasta ahora se entregaba todo junto, y el
           worker de depositos (bot_crear_jugador) al encontrar un 'retirar' lo
           mandaba a 'revisar' con "lo resuelve un agente" -- o sea que un
           retiro aprobado nunca se ejecutaba, solo cambiaba de estado.
           Ahora el default entrega SOLO cargas, asi que ese worker deja de
           tocar los retiros sin que haya que modificarlo (es de otro repo), y
           quien quiera ejecutarlos los pide explicitamente con ?tipo=retirar.
           Esa separacion es lo que permite que los retiros se ejecuten de
           verdad sin que los dos workers se peleen la misma fila. */
        $tipoPedido = (string)($_GET['tipo'] ?? 'cargar');
        if ($tipoPedido === 'retirar') {
            // aprobado = 1 sigue siendo obligatorio: sacar plata es una
            // decision de una persona, nunca del worker.
            $filtroTipo = "tipo = 'retirar' AND aprobado = 1";
        } else {
            $filtroTipo = "tipo = 'cargar'";
        }
        $sel = $pdo->prepare(
            "SELECT id FROM acciones_saldo
              WHERE estado = 'pendiente'
                AND $filtroTipo
              ORDER BY id ASC
              LIMIT $limite
              FOR UPDATE"
        );
        $sel->execute();
        $ids = $sel->fetchAll(PDO::FETCH_COLUMN);

        if (!$ids) {
            $pdo->commit();
            echo json_encode(['ok' => true, 'datos' => []]);
            exit;
        }

        $marcas = implode(',', array_fill(0, count($ids), '?'));
        $pdo->prepare(
            "UPDATE acciones_saldo
                SET estado = 'procesando', tomada_en = NOW()
              WHERE id IN ($marcas)"
        )->execute($ids);

        /* `usuario_id` es el id del jugador EN GANAMOS (usuarios.id, que es
           justamente ese id, no uno nuestro). Se manda para que el worker
           pueda depositar por API --
             POST /api/agent_admin/user/{usuario_id}/payment/
           -- en vez de tener que buscar al jugador en el panel con un
           navegador. Esa busqueda es la que venia fallando: el listado no
           terminaba de cargar y la carga moria con "no encontre al usuario".

           LEFT JOIN y no JOIN: si el jugador no esta espejado todavia, la
           accion se entrega igual con usuario_id en null y el worker decide
           que hacer -- perder la accion seria peor.

           COALESCE con altas.id_ganamos (migracion 55): el id de ganamos se
           captura al CREAR el jugador y queda en `altas`, asi que aunque el
           espejo `usuarios` este atrasado o CAIDO, el deposito igual tiene el
           id y se completa. Sin esto, un jugador recien creado no espejado
           dejaba el deposito en 'revisar' con la plata ya cobrada.

           El COLLATE es obligatorio: `usuarios` esta en la collation por
           defecto del servidor y `acciones_saldo` en utf8mb4_unicode_ci. La
           subconsulta a `altas` va en un try aparte (abajo) para degradar sola
           si la migracion 55 no corrio. */
        try {
            $get = $pdo->prepare(
                "SELECT a.id, a.usuario, a.tipo, a.monto, a.motivo, a.origen, a.creada_en,
                        COALESCE(u.id, al.id_ganamos) AS usuario_id
                   FROM acciones_saldo a
                   LEFT JOIN usuarios u
                          ON u.username = a.usuario COLLATE utf8mb4_unicode_ci
                   LEFT JOIN altas al
                          ON al.usuario = a.usuario COLLATE utf8mb4_unicode_ci
                         AND al.estado = 'ok' AND al.id_ganamos IS NOT NULL
                  WHERE a.id IN ($marcas)
                  ORDER BY a.id ASC"
            );
            $get->execute($ids);
        } catch (PDOException $e) {
            // Sin la columna id_ganamos (migracion 55 sin correr): consulta
            // vieja, solo por el espejo. No rompe.
            error_log('acciones_cola: sin altas.id_ganamos (migracion 55). '
                    . 'Deposito depende del sync: ' . $e->getMessage());
            $get = $pdo->prepare(
                "SELECT a.id, a.usuario, a.tipo, a.monto, a.motivo, a.origen, a.creada_en,
                        u.id AS usuario_id
                   FROM acciones_saldo a
                   LEFT JOIN usuarios u
                          ON u.username = a.usuario COLLATE utf8mb4_unicode_ci
                  WHERE a.id IN ($marcas)
                  ORDER BY a.id ASC"
            );
            $get->execute($ids);
        }

        $datos = array_map(function ($r) {
            $r['id']    = (int)$r['id'];
            $r['monto'] = (float)$r['monto'];
            $r['usuario_id'] = $r['usuario_id'] !== null ? (int)$r['usuario_id'] : null;
            return $r;
        }, $get->fetchAll(PDO::FETCH_ASSOC));

        $pdo->commit();
        echo json_encode(['ok' => true, 'datos' => $datos], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // ------------------------- marcar -----------------------------
    if ($accion === 'marcar' && $metodo === 'POST') {

        $body    = json_decode(file_get_contents('php://input'), true) ?: [];
        $id      = (int)($body['id'] ?? 0);
        $estado  = (string)($body['estado'] ?? '');
        $mensaje = mb_substr((string)($body['mensaje'] ?? ''), 0, 300);

        /* 'reintentar' es el cuarto estado y NO se guarda: es un pedido del
           worker. Significa "esto NO se ejecuto, y lo se con certeza -- volve a
           ponerlo en la cola". Lo unico que lo manda hoy es el challenge del
           WAF, que prueba que la request ni llego a la plataforma.
           POR QUE HACE FALTA: antes esa carga quedaba en 'revisar' esperando a
           una persona. A las 4 de la manana no hay persona, y el jugador que ya
           transfirio se queda sin sus fichas hasta que alguien se despierte. */
        if ($estado === 'reintentar') {
            if (!$id) {
                http_response_code(400);
                echo json_encode(['ok' => false, 'error' => 'Falta id']);
                exit;
            }
            $tope = ACCION_REINTENTOS_MAX;
            try {
                /* El tope es lo que lo hace seguro: sin el, un problema
                   permanente reintentaria para siempre, y cada reintento es un
                   POST que mueve plata. Se incrementa y se decide en UN solo
                   UPDATE por fila, para que dos workers a la vez no puedan
                   pasarse del tope. */
                $pdo->prepare(
                    "UPDATE acciones_saldo
                        /* El IF va ANTES del incremento: MySQL evalua el SET de izquierda
                           a derecha, asi que despues de `intentos = intentos + 1` la
                           columna ya vale el valor NUEVO y la comparacion cortaba un
                           intento antes de tiempo. Lo agarro t_reintento.php. */
                        SET estado   = IF(intentos + 1 >= ?, 'revisar', 'pendiente'),
                            intentos = intentos + 1,
                            tomada_en = NULL,
                            mensaje  = ?
                      WHERE id = ? AND estado IN ('pendiente','procesando')"
                )->execute([$tope, $mensaje !== '' ? $mensaje : null, $id]);
                $q = $pdo->prepare("SELECT estado, intentos FROM acciones_saldo WHERE id = ?");
                $q->execute([$id]);
                $fila = $q->fetch(PDO::FETCH_ASSOC) ?: [];
            } catch (Throwable $e) {
                /* Sin la migracion 62 no existe `intentos`. Se cae al
                   comportamiento de antes -- 'revisar' -- que es peor pero
                   seguro: nunca reintenta a ciegas. */
                error_log('acciones_cola/reintentar: ' . $e->getMessage());
                $pdo->prepare(
                    "UPDATE acciones_saldo SET estado='revisar', mensaje=?, ejecutada_en=NOW()
                      WHERE id = ? AND estado IN ('pendiente','procesando')"
                )->execute([$mensaje !== '' ? $mensaje : null, $id]);
                $fila = ['estado' => 'revisar', 'intentos' => 0];
            }
            $agotado = ($fila['estado'] ?? '') === 'revisar';
            if ($agotado && function_exists('tg_evento')) {
                // Se agotaron los reintentos: ahora si hace falta una persona.
                try {
                    $q = $pdo->prepare("SELECT usuario, monto FROM acciones_saldo WHERE id = ?");
                    $q->execute([$id]);
                    $a = $q->fetch(PDO::FETCH_ASSOC) ?: [];
                    tg_evento($pdo, 'salud', '⚠️ Una carga no entra ni reintentando', [
                        'Jugador'   => (string)($a['usuario'] ?? '-'),
                        'Fichas'    => number_format((float)($a['monto'] ?? 0), 0, ',', '.'),
                        'Intentos'  => (string)($fila['intentos'] ?? '?'),
                        'Respuesta' => $mensaje !== '' ? $mensaje : '(sin detalle)',
                        'Donde'     => 'CRM > Cargas',
                    ], 'deposito_fallo');
                } catch (Throwable $e) { error_log('acciones_cola/aviso: ' . $e->getMessage()); }
            }
            echo json_encode(['ok' => true, 'reintentar' => !$agotado,
                              'intentos' => (int)($fila['intentos'] ?? 0),
                              'estado' => $fila['estado'] ?? 'revisar']);
            exit;
        }

        if (!$id || !in_array($estado, ['hecha', 'error', 'revisar'], true)) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'Datos invalidos']);
            exit;
        }

        // El saldo que el bot leyo en el panel, como comprobante de la carga.
        // Puede no venir: si no pudo leerlo, se guarda NULL y no se inventa.
        $sAntes   = isset($body['saldo_antes'])   ? (float)$body['saldo_antes']   : null;
        $sDespues = isset($body['saldo_despues']) ? (float)$body['saldo_despues'] : null;

        // Solo se cierra lo que estaba en curso. Sin esto, un mensaje repetido
        // del bot vuelve a "cerrar" una accion ya cerrada y dispara otra
        // devolucion de fichas.
        $upd = $pdo->prepare(
            "UPDATE acciones_saldo
                SET estado = ?, mensaje = ?, ejecutada_en = NOW(),
                    saldo_antes = ?, saldo_despues = ?
              WHERE id = ? AND estado IN ('pendiente','procesando')"
        );
        $upd->execute([$estado, $mensaje !== '' ? $mensaje : null, $sAntes, $sDespues, $id]);

        if ($upd->rowCount() === 0) {
            echo json_encode(['ok' => true, 'ya_cerrada' => true]);
            exit;
        }

        /* ---------------------------------------------------------------
           UNA CARGA QUE NO ENTRO NO PUEDE DEJAR UN MOVIMIENTO QUE DIGA QUE SI.

           `crm_saldo()` escribe la fila de `movimientos` AL ENCOLAR, no al
           ejecutar -- para que el operador vea el movimiento en la ficha en el
           acto. El precio es que si despues la carga falla, queda un registro
           afirmando que la plata se movio.

           MEDIDO EL 18/09/2026, con los depositos rotos por el dominio: las
           acciones #157 y #158 (holacapo491, 5.000 cada una) terminaron en
           `error` y las dos dejaron su movimiento. El CRM mostraba 10.000
           cargados que nunca entraron a ganamos.

           Y no es solo cosmetico. `rl_es_primera_carga()` cuenta como "ya
           cargo" cualquier `movimientos` de tipo 'saldo' con monto > 0 y
           origen 'crm'. O sea que una carga FALLIDA le quema al jugador el
           bono de bienvenida: cuando despues carga de verdad, ya no es su
           primera.

           SOLO SE BORRA CON `error`, NUNCA CON `revisar`. Es la misma regla
           que gobierna todo este archivo: `error` es "se confirmo que NO
           paso"; `revisar` es "no se pudo confirmar", y ahi la plata puede
           haber entrado igual -- borrar el registro seria esconderla. Un
           `revisar` ya queda marcado para que lo mire una persona.

           Se acota por usuario, monto, origen y ventana de 10 minutos, y se
           borra UNA sola fila (LIMIT 1): si el operador cargo dos veces lo
           mismo y solo una fallo, tiene que sobrevivir la otra. */
        if ($estado === 'error') {
            try {
                $q = $pdo->prepare(
                    "SELECT usuario, tipo, monto, creada_en, origen
                       FROM acciones_saldo WHERE id = ?"
                );
                $q->execute([$id]);
                $a = $q->fetch(PDO::FETCH_ASSOC) ?: [];
                if (($a['origen'] ?? '') === 'crm' && ($a['tipo'] ?? '') === 'cargar') {
                    $signo = (int) round((float) $a['monto']);
                    $del = $pdo->prepare(
                        "DELETE FROM movimientos
                          WHERE usuario = ? AND tipo = 'saldo' AND origen = 'crm'
                            AND monto = ?
                            AND creado_en BETWEEN DATE_SUB(?, INTERVAL 1 MINUTE)
                                              AND DATE_ADD(?, INTERVAL 10 MINUTE)
                          ORDER BY id DESC LIMIT 1"
                    );
                    $del->execute([$a['usuario'], $signo, $a['creada_en'], $a['creada_en']]);
                    if ($del->rowCount() > 0) {
                        error_log("acciones_cola: accion $id en error -> borrado el movimiento "
                                . "de {$a['usuario']} por {$signo} (la carga no entro)");
                    }
                }
            } catch (Throwable $e) {
                // Que no se pueda limpiar el registro NO puede impedir que la
                // accion quede marcada como fallida: eso es lo que frena el
                // reintento y lo que ve el operador.
                error_log('acciones_cola/limpiar movimiento: ' . $e->getMessage());
            }
        }

        // El saldo que el bot acaba de LEER EN EL PANEL es la mejor verdad que
        // vamos a tener: sale de la plataforma, no del navegador del jugador.
        // Guardarlo aca hace que el CRM y el chatbot dejen de mostrar un numero
        // viejo despues de cada operacion, sin esperar la pasada del sync.
        //
        // Va DESPUES del rowCount: el bot reintenta los POST, y un 'marcar'
        // repetido no tiene por que volver a pisar el saldo con una lectura
        // que a esa altura ya puede ser vieja.
        if ($sDespues !== null) {
            $u = $pdo->prepare("SELECT usuario FROM acciones_saldo WHERE id = ?");
            $u->execute([$id]);
            if ($quien = $u->fetchColumn()) {
                /* `saldo_visto_en` (migracion 68) marca la EDAD del dato, que es
                   lo que el CRM muestra al lado del saldo. Este caso es el mejor
                   de todos: el numero lo acaba de leer el bot EN EL PANEL. Si la
                   migracion no corrio se cae al UPDATE de siempre. */
                try {
                    $pdo->prepare("UPDATE usuarios SET balance = ?, saldo_visto_en = NOW() WHERE username = ?")
                        ->execute([$sDespues, $quien]);
                } catch (Throwable $e) {
                    $pdo->prepare("UPDATE usuarios SET balance = ? WHERE username = ?")
                        ->execute([$sDespues, $quien]);
                }
            }
        } elseif ($estado === 'hecha') {
            /* EL BOT NO MANDA `saldo_despues`, asi que lo de arriba casi nunca
               corre: el espejo `usuarios.balance` quedaba viejo hasta la
               proxima pasada de sync_usuarios, que es cada 5 MINUTOS.
               Y eso no es cosmetico: el chatbot lee de ahi. Nahuel lo vio
               probando como jugador -- le cargo 100 fichas para llegar al
               minimo de retiro y el bot le seguia diciendo que tenia 99, asi
               que no lo dejaba retirar. El jugador tiene la plata y el sistema
               le dice que no.
               Como la operacion ACABA de confirmarse y sabemos el monto exacto,
               se ajusta el espejo en el acto. Si el numero real difiere por
               algo que paso en paralelo (el jugador jugando), el sync lo
               corrige en la proxima pasada: esto adelanta el dato, no lo
               reemplaza.
               Va despues del rowCount, asi que un 'marcar' repetido -- el bot
               reintenta los POST -- no lo suma dos veces. */
            try {
                $a2 = $pdo->prepare("SELECT usuario, tipo, monto FROM acciones_saldo WHERE id = ?");
                $a2->execute([$id]);
                if ($fa = $a2->fetch(PDO::FETCH_ASSOC)) {
                    $delta = (float)$fa['monto'] * ($fa['tipo'] === 'retirar' ? -1 : 1);
                    /* Aca el saldo no se LEE, se calcula -- asi que marcarlo como
                       recien visto es una licencia. Se hace igual porque la
                       alternativa es peor: el CRM diria "saldo de hace 40 min"
                       justo despues de una operacion que acabamos de hacer
                       nosotros, y el operador dejaria de creerle al indicador.
                       El margen de error es lo que el jugador haya jugado en los
                       segundos que tardo la operacion. */
                    try {
                        $pdo->prepare(
                            "UPDATE usuarios SET balance = GREATEST(0, COALESCE(balance,0) + ?),
                                    saldo_visto_en = NOW()
                              WHERE username = ?"
                        )->execute([$delta, $fa['usuario']]);
                    } catch (Throwable $e) {
                        $pdo->prepare(
                            "UPDATE usuarios SET balance = GREATEST(0, COALESCE(balance,0) + ?)
                              WHERE username = ?"
                        )->execute([$delta, $fa['usuario']]);
                    }
                }
            } catch (Throwable $e) {
                // El espejo es una comodidad: si falla, el sync lo arregla.
                error_log('acciones_cola/espejo saldo: ' . $e->getMessage());
            }
        }

        // Fallo confirmado: el jugador recupera sus fichas.
        $devuelto = 0;
        if ($estado === 'error') {
            $devuelto = fichas_devolver($pdo, $id);
        }

        /* UN DEPOSITO QUE FALLA TIENE QUE SONAR. Devolverle las fichas al
           jugador no alcanza: el sigue sin poder cargar, y el proximo intento
           va a fallar por lo mismo.

           El caso real (12-13/9/2026): la cuenta de agente se quedo SIN FICHAS
           y la plataforma empezo a contestar {"status":501}. Nadie se entero
           hasta que los jugadores reclamaron -- hubo que cargarles a mano y
           recien ahi comprarle mas al proveedor. Es una condicion operativa
           normal y previsible, no una rareza: se acaba el stock y hay que
           reponerlo. Lo unico que faltaba era que avisara.

           Con clave de dedupe GLOBAL, no por accion: cuando no hay fichas
           fallan TODOS los depositos, y treinta mensajes seguidos terminan con
           el operador silenciando el bot. Uno por ventana alcanza -- el resto
           se ve en la cola de Cargas del CRM, con su badge. */
        if (in_array($estado, ['error', 'revisar'], true) && function_exists('tg_evento')) {
            try {
                $q = $pdo->prepare(
                    "SELECT usuario, tipo, monto FROM acciones_saldo WHERE id = ?");
                $q->execute([$id]);
                $a = $q->fetch(PDO::FETCH_ASSOC) ?: [];
                if (($a['tipo'] ?? '') === 'cargar') {
                    tg_evento($pdo, 'salud', '⚠️ No se pudo depositar en el juego', [
                        'Jugador'  => (string)($a['usuario'] ?? '-'),
                        'Fichas'   => number_format((float)($a['monto'] ?? 0), 0, ',', '.'),
                        'Respuesta'=> $mensaje !== '' ? $mensaje : '(sin detalle)',
                        'Ojo'      => $estado === 'error'
                            ? 'Se le devolvieron las fichas, pero NO puede cargar hasta que se arregle. '
                            . 'Si dice que no hay saldo, comprale fichas al proveedor.'
                            : 'No se sabe si entro: NO se devolvieron fichas. Miralo en Cargas.',
                        'Donde'    => 'CRM > Cargas',
                    ], 'deposito_fallo');
                }
            } catch (Throwable $e) {
                error_log('acciones_cola/aviso: ' . $e->getMessage());
            }
        }

        // Recien ACA se avisa. No cuando el chatbot encola ni cuando el bot
        // aprieta el boton: cuando el saldo del panel se movio de verdad. Es el
        // unico momento en que decirle "ya lo tenes" no puede ser mentira.
        if ($estado === 'hecha' && function_exists('notif_crear')) {
            // bono_debitado es de la migracion 56: sin ella, 0 y todo sigue
            // (con $sinMig56 prendido para que el bono se rescate del motivo).
            $sinMig56 = false;
            try {
                $acc = $pdo->prepare(
                    "SELECT usuario, tipo, monto, motivo, origen, coins_debitados, bono_debitado
                       FROM acciones_saldo WHERE id = ?");
                $acc->execute([$id]);
            } catch (PDOException $e) {
                $sinMig56 = true;
                $acc = $pdo->prepare(
                    "SELECT usuario, tipo, monto, motivo, origen, coins_debitados, 0 AS bono_debitado
                       FROM acciones_saldo WHERE id = ?");
                $acc->execute([$id]);
            }
            if ($a = $acc->fetch()) {
                /* Purchase para Meta: ACA, no cuando el chatbot encolo. Este
                   es el mismo punto donde se le avisa al jugador "ya lo tenes"
                   -- el unico momento en que decirlo no es mentira. Reportarlo
                   antes haria que la campaña optimice contra pedidos y no
                   contra plata acreditada.

                   Solo las cargas: un retiro no es una compra.

                   Y NO las que vienen de una recarga por transferencia. Esa
                   plata YA la reporto rl_reportar_purchase() cuando se acredito
                   el pago; esta accion es solo el segundo tramo interno (pasar
                   las fichas al juego), no una compra nueva.

                   Sin este filtro, cada transferencia generaba DOS Purchase con
                   refs distintas -- 'recarga:M' y 'carga:N' -- que Meta contaba
                   por separado: los ingresos reportados salian al doble y la
                   optimizacion por valor aprendia sobre numeros falsos. Con el
                   bono de carga activo ni siquiera coincidian los importes, asi
                   que el error era irregular y mas dificil de ver. */
                $deRecarga = ($a['origen'] ?? '') === 'recarga';
                /* Cuanto del deposito era BONO. Normalmente sale de la
                   columna (migracion 56); sin la migracion se rescata del
                   MOTIVO, que fichas_pedir_carga escribe con el bono adentro
                   ('Bonos al juego' = todo bono; 'Canje de fichas + bono N').
                   OJO: no sirve mirar origen='crm' + coins 0 -- ese es el
                   DEFAULT del esquema y lo comparte la carga de saldo manual. */
                $bonoDep = (int)($a['bono_debitado'] ?? 0);
                if ($sinMig56 && $bonoDep === 0 && ($a['tipo'] ?? '') === 'cargar') {
                    $mot = (string)($a['motivo'] ?? '');
                    if (strpos($mot, 'Bonos al juego') === 0) {
                        $bonoDep = (int)round((float)($a['monto'] ?? 0));
                    } elseif (preg_match('/\+ bono (\d+)/', $mot, $m)) {
                        $bonoDep = (int)$m[1];
                    }
                }
                /* Un deposito que es TODO bono es un REGALO de la casa, no
                   ingresos: reportarlo como Purchase inflaria los numeros de
                   la campaña con plata que nunca entro. */
                $esRegalo = $bonoDep > 0
                         && (int)($a['coins_debitados'] ?? 0) === 0
                         && (float)($a['monto'] ?? 0) <= $bonoDep;

                /* UNA CARGA A MANO NO ES UNA COMPRA, y reportarla como tal es
                   peor que no reportar nada.

                   MEDIDO EL 18/09/2026, antes de prender la publicidad: en 14
                   dias se le mandaron a Meta 14 `Purchase` por cargas con
                   origen 'crm', $106.007 en total. Ninguna la pago el jugador
                   -- son cargas de prueba, correcciones, regalos, y las que el
                   operador cubre a mano cuando el deposito automatico falla.
                   Las compras de verdad (origen 'recarga', $364.120 en el
                   mismo periodo) salen por rl_notificar_acreditada() y esas si
                   estan bien.

                   El daño no es un numero feo en un informe: Meta OPTIMIZA la
                   pauta con estos eventos. Decirle que 14 personas compraron
                   sin haber comprado le enseña a buscar gente parecida a
                   alguien que recibe fichas gratis -- y eso se paga en cada
                   impresion.

                   EL CRITERIO, y es el mismo que ya se aplico hoy al watchdog
                   de cargas: no se afirma lo que no se puede probar. Una carga
                   'crm' no tiene fila en `recargas` ni en `pagos`: no hay
                   ninguna evidencia de que haya entrado plata.

                   SE PIERDE UN CASO LEGITIMO y vale decirlo: el operador que
                   carga a mano porque el matcher no encontro la transferencia.
                   Esa SI es una compra. El camino correcto para esa es
                   acreditar la recarga desde Comprobantes --ahi el Purchase
                   sale solo y bien-- y no la carga suelta desde la ficha. Un
                   evento de menos sub-reporta; uno de mas desvia el algoritmo.
                   Entre los dos errores, este es el barato. */
                $esManual = ($a['origen'] ?? '') === 'crm';

                if (($a['tipo'] ?? '') === 'cargar' && !$deRecarga && !$esRegalo && !$esManual) {
                    try {
                        require_once __DIR__ . '/meta_lib.php';
                        require_once __DIR__ . '/publicidad_lib.php';
                        $atrib = publicidad_atribucion_por_usuario($pdo, (string)$a['usuario']);
                        meta_evento($pdo, 'Purchase', [
                            'usuario' => (string)$a['usuario'],
                            'valor'   => (float)$a['monto'],
                            'ref'     => 'carga:' . $id,
                            'fbp'     => $atrib['fbp'],
                            'fbc'     => $atrib['fbc'],
                            // Del jugador, no del bot que dispara esto.
                            'ip'      => $atrib['ip'] ?? '',
                            'ua'      => $atrib['ua'] ?? '',
                            'url'     => $atrib['url'] ?? '',
                            'pixel'   => publicidad_pixel_propio($atrib['publicista']),
                        ]);
                    } catch (Throwable $e) {
                        error_log('meta Purchase: ' . $e->getMessage());
                    }
                }
                $cuanto = number_format((float)$a['monto'], 0, ',', '.');
                $saldo  = $sDespues !== null
                        ? ' Tu saldo quedó en ' . number_format($sDespues, 0, ',', '.') . '.'
                        : '';
                /* La notificacion dice lo que FUE: un deposito que es todo
                   bono no es "te cargamos fichas" (suena a que pago y no
                   pago), es un regalo acreditado. */
                /* UN RETIRO NO ES UNA CARGA, y hasta ahora caia en la misma
                   rama: al jugador al que le acabamos de SACAR las fichas le
                   llegaba "Ya te cargamos 199 fichas". Le decia exactamente lo
                   contrario de lo que paso. Paso apenas el retiro empezo a
                   ejecutarse de verdad (13/9/2026), porque antes ninguna accion
                   de tipo 'retirar' llegaba a 'hecha'. */
                if (($a['tipo'] ?? '') === 'retirar') {
                    notif_crear(
                        $pdo,
                        $a['usuario'],
                        '✅ Retiro aprobado',
                        'Te descontamos ' . $cuanto . ' fichas del juego.' . $saldo
                            . ' La transferencia sale en breve.',
                        'fichas',
                        null,
                        'retiro'
                    );
                    /* Y por el CHAT, que es donde lo pidio y donde esta
                       mirando. La notificacion del celular se pierde entre
                       otras; el chat es el hilo de ESTA conversacion. */
                    try {
                        require_once __DIR__ . '/crm_lib.php';
                        if (function_exists('crm_difusion_chat_aplicar')) {
                            crm_difusion_chat_aplicar($pdo, (string)$a['usuario'],
                                '✅ Listo, se aprobó tu retiro de ' . $cuanto
                                . ' fichas. Ya te las descontamos del juego y la '
                                . 'transferencia sale en breve.',
                                ['efimero' => 600]);
                        }
                    } catch (Throwable $e) {
                        error_log('aviso de retiro por chat: ' . $e->getMessage());
                    }
                } elseif ($esRegalo) {
                    notif_crear(
                        $pdo,
                        $a['usuario'],
                        '🎁 ¡Bonos acreditados!',
                        'Te sumamos ' . number_format($bonoDep, 0, ',', '.')
                            . ' en bonos a tu saldo del juego.' . $saldo . ' ¡A jugarlos!',
                        'bono',
                        null,
                        'carga'
                    );
                } else {
                    notif_crear(
                        $pdo,
                        $a['usuario'],
                        '¡Fichas acreditadas!',
                        'Ya te cargamos ' . $cuanto . ' fichas.' . $saldo . ' A jugar.',
                        'fichas',
                        null,
                        'carga'
                    );
                }

                /* Y EL MENSAJE EN EL CHAT, ademas de la notificacion: cuando
                   el deposito incluyo BONOS, el jugador recibe la confirmacion
                   por donde esta mirando -- el chat -- con la cantidad exacta.
                   Va por crm_difusion_chat_aplicar, que inserta con el rol que
                   mis_mensajes SI entrega y solo si la conversacion existe
                   (a quien nunca chateo le queda la notificacion de arriba).
                   Best-effort: un fallo aca no puede voltear el 'marcar'. */
                if (($a['tipo'] ?? '') === 'cargar' && $bonoDep > 0) {
                    try {
                        require_once __DIR__ . '/crm_lib.php';
                        if ($esRegalo) {
                            $txt = '🎁 ¡Te acreditamos '
                                 . number_format($bonoDep, 0, ',', '.')
                                 . ' en bonos! Ya están en tu saldo del juego. ¡A jugarlos!';
                        } else {
                            $base = (float)$a['monto'] - $bonoDep;
                            $txt = '🎁 ¡Bono acreditado! A tu carga de '
                                 . number_format($base, 0, ',', '.')
                                 . ' le sumamos ' . number_format($bonoDep, 0, ',', '.')
                                 . ' en bonos: ya tenés '
                                 . $cuanto . ' en tu saldo del juego.';
                        }
                        if (function_exists('crm_difusion_chat_aplicar')) {
                            /* efimero: el aviso vive 300s despues de que el
                               jugador lo VIO (visto_en). El widget lo saca de
                               la pantalla y mis_mensajes.php lo borra de la
                               base: es una confirmacion, no historial -- el
                               registro contable queda en `movimientos`. */
                            crm_difusion_chat_aplicar($pdo, (string)$a['usuario'], $txt,
                                                      ['efimero' => 300]);
                        }
                    } catch (Throwable $e) {
                        error_log('acciones_cola: no pude avisar el bono por chat: ' . $e->getMessage());
                    }
                }
            }
        }

        echo json_encode(['ok' => true, 'fichas_devueltas' => $devuelto]);
        exit;
    }

    // --------------------- panel_credenciales ---------------------
    // Las credenciales del panel de agentes que cargo el cliente en el CRM
    // (Configuracion -> Panel de agentes). El bot las pide al arrancar y
    // antes de cada re-login: asi un cambio de contraseña del panel se
    // resuelve desde el CRM, sin tocar el .env del contenedor. Vacias =
    // "usa las de tu .env", el comportamiento de siempre.
    // Detras de exigir_api_key() como todo este archivo -- que ya entrega
    // cosas igual de sensibles (mueve plata). Nunca exponer esto sin auth.
    if ($accion === 'panel_credenciales') {
        require_once __DIR__ . '/config_crm.php';
        $pu = trim((string)cfg_crm($pdo, 'panel_user'));
        $pp = (string)cfg_crm($pdo, 'panel_pass');

        /* UNA SOLA FUENTE (22/09/2026). Hasta hoy el CRM pedía estas
           credenciales en DOS pantallas: acá (config_crm) y en «Integración
           con ganamos» (goldpaw_control.clientes), que es la que de verdad
           hace falta -- provisionar.php levanta el contenedor del bot con
           esa. El cliente cargaba una, dejaba la otra vacía, y según cuál
           fuera quedaba con bot y sin re-login, o sin bot.

           Se sacó la de Configuración y queda la de Integración. Esto lee de
           ahí cuando config_crm está vacío, así el bot conserva su mecanismo
           --pedir las credenciales en cada re-login, sin tocar el .env-- con
           un solo lugar donde cargarlas. */
        if ($pu === '' || $pp === '') {
            try {
                $ctl = new PDO(
                    'mysql:host=' . cfg('DB_HOST', 'localhost')
                        . ';dbname=' . cfg('CONTROL_DB_NAME', 'goldpaw_control') . ';charset=utf8mb4',
                    cfg('DB_USER'), cfg('DB_PASS'),
                    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
                );
                $q = $ctl->prepare(
                    'SELECT agente_usuario, agente_password FROM clientes WHERE db_nombre = ? LIMIT 1'
                );
                $q->execute([(string)($GLOBALS['TENANT_DB'] ?? '')]);
                if ($f = $q->fetch()) {
                    if ($pu === '') { $pu = trim((string)($f['agente_usuario'] ?? '')); }
                    if ($pp === '') { $pp = (string)($f['agente_password'] ?? ''); }
                }
            } catch (Throwable $e) {
                // Sin control: el bot se queda con las de su .env, que es el
                // comportamiento de siempre.
                error_log('panel_credenciales (control): ' . $e->getMessage());
            }
        }

        echo json_encode(['ok' => true, 'user' => $pu, 'pass' => $pp], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // ------------------------- liberar ----------------------------
    // Vuelve a 'pendiente' lo que quedo en 'procesando'.
    //
    // CON ids en el body: libera SOLO esos -- es lo que usa el bot cuando el
    // techo de la pasada de depositos (FICHAS_PASADA_MAX_SEG) lo hace parar
    // con acciones reclamadas que NO llego a intentar. Es seguro justamente
    // por eso: el bot sabe que no les mando ningun POST al panel, asi que
    // re-entregarlas no puede depositar dos veces. Mismo criterio que el
    // liberar por ids de altas_cola.
    //
    // SIN ids: libera todo lo 'procesando'. Eso sigue siendo a mano y a
    // proposito: quien lo corra tiene que haber mirado el panel antes.
    /* CONCILIAR: cerrar las acciones que quedaron trabadas pero que el LIBRO
       del panel dice que SI se ejecutaron.

       Cuando el WAF corta un deposito, el worker recibe 200 con el HTML del
       challenge, no puede confirmar y marca 'revisar' -- el lado seguro. Pero
       "no pude confirmar" no es "no paso": la request pudo llegar igual, y
       llega. Esas filas no se cierran nunca.

       Va ACA y no en el worker por lo mismo que el resto de las decisiones del
       proyecto: cuando esto se decide en Python terminan habiendo dos criterios
       que se separan (paso con el matcher). El colector solo pregunta.

       SOLO CIERRA. Que algo no este en el libro no marca ningun error: el libro
       tiene ventana movil y backfill, y una ausencia puede ser "todavia no
       sincronizo". Cerrar de mas se nota; marcar un fracaso falso le saca la
       plata a alguien que la tiene. */
    if ($accion === 'conciliar' && $metodo === 'POST') {
        require_once __DIR__ . '/conciliar_lib.php';
        $cuerpo = json_decode(file_get_contents('php://input'), true) ?: [];
        $dias = (int)($cuerpo['dias'] ?? CONC_DIAS);
        echo json_encode(conc_conciliar($pdo, $dias), JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($accion === 'liberar' && $metodo === 'POST') {
        $body = json_decode(file_get_contents('php://input'), true) ?: [];
        $ids  = array_values(array_filter(array_map('intval', (array)($body['ids'] ?? []))));
        if ($ids) {
            $marcas = implode(',', array_fill(0, count($ids), '?'));
            $st = $pdo->prepare(
                "UPDATE acciones_saldo
                    SET estado = 'pendiente', tomada_en = NULL
                  WHERE estado = 'procesando' AND id IN ($marcas)"
            );
            $st->execute($ids);
            echo json_encode(['ok' => true, 'liberadas' => (int)$st->rowCount()]);
            exit;
        }
        $n = $pdo->exec(
            "UPDATE acciones_saldo
                SET estado = 'pendiente', tomada_en = NULL
              WHERE estado = 'procesando'"
        );
        echo json_encode(['ok' => true, 'liberadas' => (int)$n]);
        exit;
    }

    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'accion desconocida']);

} catch (Throwable $e) {
    if ($pdo->inTransaction()) { $pdo->rollBack(); }
    error_log('acciones_cola: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Error', 'detalle' => $e->getMessage()]);
}
