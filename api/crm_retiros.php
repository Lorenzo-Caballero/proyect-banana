<?php
/**
 * crm_retiros.php — Backend del módulo "Retiros pendientes".
 *
 * Cola de acciones_saldo (tipo='retirar') que ejecuta bot/bot_cargar_fichas.py
 * en FICHAS_MODE=LIVE. Un retiro pedido por el jugador queda con aprobado=0; el
 * agente lo aprueba acá (?accion=aprobar) y recién ahí el bot lo ejecuta en el
 * panel. Este endpoint da visibilidad de la cola completa y las acciones de
 * mantenimiento (liberar/reintentar/cancelar).
 *
 * 'revisar' es SOLO LECTURA a propósito: es el estado en el que el bot no
 * pudo confirmar si el retiro entró o no (ver acciones_cola.php). Reintentar
 * eso a ciegas podría pagar dos veces — se resuelve mirando el panel de
 * ganamos a mano, no desde acá.
 *
 * "liberar" hace su propio UPDATE directo (no le pega por HTTP a
 * acciones_cola.php?accion=liberar): mismo servidor, mismo $pdo, y así queda
 * acotado a tipo='retirar' sin tocar el archivo que el bot sondea en vivo.
 *
 * "cancelar" requiere migracion sql/23_acciones_saldo_cancelada.sql (Fase A):
 * agrega 'cancelada' al ENUM de estado. Solo se puede cancelar desde
 * 'pendiente' o 'error' -- nunca desde 'procesando', porque el bot podria
 * estar ejecutandolo en ese instante.
 *
 * Fase A, Módulo 2 (ver CRM_DESIGN.md).
 *
 * GET  ?accion=badge                    -> { ok, cantidad } — SOLO
 *                                           'procesando' trabado hace mas de
 *                                           30 min (la unica señal realmente
 *                                           urgente; un pendiente normal no
 *                                           alerta)
 * GET  ?accion=listar&estado=&q=        -> { ok, items:[...] }. Sin estado
 *                                           explicito, excluye 'cancelada'
 *                                           (no genera ruido en "Todos").
 * POST { accion:"liberar" }             -> 'procesando' -> 'pendiente'
 *                                           (masivo, solo tipo='retirar')
 * POST { accion:"reintentar", id }      -> 'error' -> 'pendiente' (puntual)
 * POST { accion:"cancelar", id, nota }  -> 'pendiente'|'error' -> 'cancelada'
 */

declare(strict_types=1);
require __DIR__ . '/config.php';
require __DIR__ . '/db.php';
require __DIR__ . '/crm_lib.php';
require __DIR__ . '/notificaciones_lib.php';
require __DIR__ . '/crm_auth.php';

header('Content-Type: application/json; charset=utf-8');
$operador = exigir_operador();

function salir($data, int $code = 200): void
{
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

// ============================== GET =========================================
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $accion = (string)($_GET['accion'] ?? 'listar');

    try {
        /* EL BADGE CUENTA LO QUE ESPERA A UNA PERSONA, Y ANTES NO.
           Contaba solo los `procesando` trabados hace mas de 30 minutos, o sea
           que era una alarma de worker colgado disfrazada de contador. Los
           retiros que de verdad esperan --los `pendiente`, que necesitan que
           alguien apruebe-- no lo prendian nunca.

           El costo real, medido el 16/09/2026: habia CUATRO pendientes, el mas
           viejo de 18 horas, y el rail del CRM no mostraba nada. Nahuel lo
           pidio asi: *"me gustaria que se vea un icono en rojo a la izquierda
           como con conversaciones para saber que hay un retiro pendiente"*.

           La cuenta es la MISMA que el default de `listar` (estado NOT IN
           ('hecha','cancelada')), y eso no es casualidad: el numero del badge
           tiene que ser el numero de filas que vas a encontrar al hacer click.
           Si divergen, el badge deja de creerse -- que es como termino el
           anterior.

           Entra `revisar` y entra `error`: los dos significan "no se pudo
           confirmar" y los dos los resuelve una persona mirando el libro del
           panel. Un retiro que nadie mira no se arregla solo. */
        if ($accion === 'badge') {
            $n = (int)$pdo->query(
                "SELECT COUNT(*) FROM acciones_saldo
                  WHERE tipo='retirar' AND estado NOT IN ('hecha','cancelada')"
            )->fetchColumn();
            salir(['ok' => true, 'cantidad' => $n]);
        }

        if ($accion === 'listar') {
            $estado = (string)($_GET['estado'] ?? '');
            $q      = trim((string)($_GET['q'] ?? ''));

            $where  = ["tipo = 'retirar'"];
            $params = [];
            if (in_array($estado, ['pendiente', 'procesando', 'hecha', 'error', 'revisar', 'cancelada'], true)) {
                $where[]  = 'estado = ?';
                $params[] = $estado;
            } elseif ($estado === 'todas') {
                // El historial completo, canceladas incluidas. Es una pestaña
                // aparte porque no es lo que se viene a hacer a esta pantalla.
                // (sin condicion de estado)
            } else {
                /* EL DEFAULT ES "LO QUE FALTA RESOLVER", no "todo".
                   Antes solo escondia las canceladas, asi que la pantalla que
                   se llama RETIROS PENDIENTES listaba tambien las ya pagadas y
                   los ajustes viejos. El contador de arriba decia "4 retiros"
                   con dos ya hechos, y la sensacion era de tener siempre algo
                   sin resolver -- reportado por Nahuel.
                   Un retiro resuelto ya no pide nada: sale de la bandeja y vive
                   en su pestaña, igual que un chat archivado. */
                $where[] = "estado NOT IN ('hecha','cancelada')";
            }
            if ($q !== '') {
                $where[]  = '(usuario LIKE ? OR id = ?)';
                $params[] = '%' . $q . '%';
                $params[] = (int)(ctype_digit($q) ? $q : 0);
            }

            /* destino/hg_estado con subconsulta condicionada: si la migracion 43
               no corrio, las columnas no existen y el SELECT plano tumbaria la
               lista entera. Probar una vez y elegir el SQL es mas barato que
               capturar la excepcion en cada request. */
            $hayHg = true;
            try { $pdo->query("SELECT destino FROM acciones_saldo LIMIT 0"); }
            catch (Throwable $e) { $hayHg = false; }
            /* El nombre y el CUIT del destino son de la misma migracion que
               `destino`, y son EL dato que se necesita para pagarle: el CBU
               solo no alcanza -- hay que confirmar que la cuenta sea suya. */
            $colsHg = $hayHg
                ? ", COALESCE(destino,'') destino, COALESCE(hg_estado,'') hg_estado,
                     COALESCE(destino_nombre,'') destino_nombre,
                     COALESCE(destino_cuit,'') destino_cuit"
                : ", '' destino, '' hg_estado, '' destino_nombre, '' destino_cuit";

            $st = $pdo->prepare(
                "SELECT id, usuario, monto, motivo, estado, aprobado, tomada_en, mensaje,
                        saldo_antes, saldo_despues, creada_en, ejecutada_en,
                        TIMESTAMPDIFF(MINUTE, creada_en, NOW()) AS espera_min $colsHg
                   FROM acciones_saldo
                  WHERE " . implode(' AND ', $where) . "
                  ORDER BY creada_en DESC
                  LIMIT 200"
            );
            $st->execute($params);
            $items = array_map(function ($r) {
                $r['id']            = (int)$r['id'];
                $r['monto']         = (float)$r['monto'];
                $r['aprobado']      = (int)($r['aprobado'] ?? 0);
                $r['espera_min']    = $r['espera_min'] !== null ? (int)$r['espera_min'] : null;
                $r['saldo_antes']   = $r['saldo_antes']   !== null ? (float)$r['saldo_antes']   : null;
                $r['saldo_despues'] = $r['saldo_despues'] !== null ? (float)$r['saldo_despues'] : null;
                return $r;
            }, $st->fetchAll(PDO::FETCH_ASSOC));

            /* ¿ESTE RETIRO YA SE HIZO A MANO EN EL PANEL?
               EL CASO (16/09/2026): había cuatro pedidos esperando aprobación y
               TRES ya estaban resueltos -- el operador los había hecho a mano en
               el panel y el pedido quedó abierto acá. Aprobar cualquiera de esos
               le sacaba las fichas por SEGUNDA vez al jugador.

               Es la misma falla que costó 35.000 de más en un depósito esa misma
               madrugada, con el signo cambiado: nuestras tablas dicen lo que
               quisimos hacer, y `operaciones_panel` --el libro de la PLATAFORMA--
               dice lo que pasó. La pantalla que decide mostraba solo la primera.

               Se busca por jugador y monto en una ventana de ±2 h alrededor del
               pedido. La ventana es ancha a propósito: el operador resuelve
               cuando puede, y el panel da la hora al minuto. Un falso aviso
               cuesta que mires; un aviso que falta cuesta plata.

               `hecho_en_panel` es un AVISO, no un bloqueo: puede ser que el
               jugador pidiera dos retiros iguales de verdad. Lo decide una
               persona, igual que todo lo que saca plata.

               Best-effort: sin migración 67 no hay libro y la lista sale igual. */
            try {
                $conLibro = [];
                foreach ($items as $it) {
                    if (!empty($it['del_juego'])) { continue; }
                    if (!in_array((string)$it['estado'], ['pendiente','revisar','error'], true)) { continue; }
                    $conLibro[] = $it;
                }
                if ($conLibro) {
                    $qL = $pdo->prepare(
                        "SELECT payment_id, monto, cuando, comentario
                           FROM operaciones_panel
                          WHERE tipo = 1
                            AND username = ?
                            AND ROUND(monto * 100) = ?
                            AND cuando BETWEEN (? - INTERVAL 2 HOUR) AND (? + INTERVAL 2 HOUR)
                          ORDER BY cuando ASC LIMIT 1"
                    );
                    foreach ($items as $i => $it) {
                        if (!empty($it['del_juego'])) { continue; }
                        if (!in_array((string)$it['estado'], ['pendiente','revisar','error'], true)) { continue; }
                        $qL->execute([
                            (string)$it['usuario'],
                            (int)round(((float)$it['monto']) * 100),
                            $it['creada_en'], $it['creada_en'],
                        ]);
                        if ($y = $qL->fetch(PDO::FETCH_ASSOC)) {
                            $items[$i]['hecho_en_panel'] = [
                                'cuando'     => $y['cuando'],
                                'payment_id' => (int)$y['payment_id'],
                                'monto'      => (float)$y['monto'],
                            ];
                        }
                    }
                }
            } catch (Throwable $e) {
                error_log('crm_retiros: sin libro para cruzar: ' . $e->getMessage());
            }

            /* LOS PEDIDOS DESDE EL JUEGO, en la misma lista. Viven en otra
               tabla (`retiros_panel`, migracion 64) y NO en `acciones_saldo`,
               porque esa es la cola que ejecuta nuestro worker: uno del panel
               metido ahi se pagaria dos veces. Pero para el operador son lo
               mismo -- gente esperando cobrar -- y tenerlos en dos pantallas
               distintas es como no tenerlos.
               Vienen marcados `del_juego` para que el front no ofrezca acciones
               que no corresponden: estos se resuelven en el panel y el espejo
               se entera solo. */
            if ($estado === '' || $estado === 'todas' || $estado === 'pendiente') {
                try {
                    /* OJO: `$q` de mas arriba es el TEXTO de busqueda. El
                       statement va en otra variable -- pisarlo dejaria la
                       busqueda rota de ahi en adelante. */
                    $stRP = $pdo->prepare(
                        "SELECT request_id, username, titular, monto, destino,
                                primera_vez, estado,
                                TIMESTAMPDIFF(MINUTE, primera_vez, NOW()) AS espera_min
                           FROM retiros_panel
                          WHERE estado = 'abierto'
                            AND (? = '' OR username LIKE ?)
                          ORDER BY primera_vez DESC LIMIT 100"
                    );
                    $stRP->execute([$q, '%' . $q . '%']);
                    foreach ($stRP->fetchAll(PDO::FETCH_ASSOC) as $r) {
                        $items[] = [
                            'id'             => (int)$r['request_id'],
                            'usuario'        => (string)$r['username'],
                            'monto'          => (float)$r['monto'],
                            'motivo'         => 'pedido desde el juego',
                            'estado'         => 'pendiente',
                            'aprobado'       => 1,     // no espera aprobacion nuestra
                            'mensaje'        => null,
                            'destino'        => (string)($r['destino'] ?? ''),
                            'destino_nombre' => (string)($r['titular'] ?? ''),
                            'destino_cuit'   => '',
                            'hg_estado'      => '',
                            'saldo_antes'    => null,
                            'saldo_despues'  => null,
                            'creada_en'      => $r['primera_vez'],
                            'ejecutada_en'   => null,
                            'espera_min'     => (int)$r['espera_min'],
                            'del_juego'      => true,
                        ];
                    }
                    // Los mas nuevos arriba, mezclados con los otros.
                    usort($items, static fn($a, $b) => strcmp((string)$b['creada_en'], (string)$a['creada_en']));
                } catch (Throwable $e) {
                    // Sin la migracion 64 no hay espejo: se sirve lo de siempre.
                    error_log('crm_retiros: sin retiros_panel: ' . $e->getMessage());
                }
            }

            /* EL MISMO JUGADOR CON MAS DE UN PEDIDO ABIERTO.
               Es la forma normal de pagar dos veces: uno pedido por el chat y
               otro adentro del juego son dos filas en dos tablas distintas, y
               en esta pantalla se ven como dos pedidos independientes. Paso el
               15/09/2026 -- 4.280 por el chat y 4.000 en el juego -- y termino
               con el jugador cobrando 4.280 del banco y quedandose 280 fichas.
               `fichas_pedir_retiro` ya no deja que se abran los dos por el
               chat; esto es para los que ya existen y para los que carga el
               operador a mano desde la ficha, que no pasa por esa validacion.

               Se cuenta con SU PROPIA consulta y no sobre $items: la lista
               esta filtrada por estado y cortada a 200 filas, y un duplicado
               que no entro en la pagina es justo el que hay que avisar. */
            $dobles = [];
            try {
                $sd = $pdo->query(
                    "SELECT usuario, COUNT(*) n FROM acciones_saldo
                      WHERE tipo = 'retirar'
                        AND estado IN ('pendiente','procesando','revisar','error')
                      GROUP BY usuario"
                );
                foreach ($sd->fetchAll(PDO::FETCH_ASSOC) as $f) {
                    $dobles[mb_strtolower((string)$f['usuario'])] = (int)$f['n'];
                }
                $sp = $pdo->query(
                    "SELECT username, COUNT(*) n FROM retiros_panel
                      WHERE estado = 'abierto' GROUP BY username"
                );
                foreach ($sp->fetchAll(PDO::FETCH_ASSOC) as $f) {
                    $k = mb_strtolower((string)$f['username']);
                    $dobles[$k] = ($dobles[$k] ?? 0) + (int)$f['n'];
                }
            } catch (Throwable $e) {
                // Sin migracion 64 no hay espejo del juego: se avisa lo que se pueda.
                error_log('crm_retiros/duplicados: ' . $e->getMessage());
            }
            $ABIERTOS = ['pendiente', 'procesando', 'revisar', 'error'];
            foreach ($items as &$it) {
                $it['abiertos_del_jugador'] =
                    in_array((string)$it['estado'], $ABIERTOS, true)
                    ? (int)($dobles[mb_strtolower((string)$it['usuario'])] ?? 1)
                    : 1;
            }
            unset($it);

            salir(['ok' => true, 'items' => $items]);
        }

        salir(['ok' => false, 'error' => 'Acción desconocida'], 400);
    } catch (Throwable $e) {
        error_log('crm_retiros GET: ' . $e->getMessage());
        salir(['ok' => false, 'error' => 'Error al consultar'], 500);
    }
}

/**
 * A QUIEN Y POR CUANTO, para la bitácora.
 *
 * Auditoría mostraba «aprobar retiro — id 188». El id es la referencia, no el
 * hecho: para saber a quién se le aprobó un retiro de cuánto había que ir a
 * buscar esa fila a otra pantalla, que es exactamente lo que una auditoría
 * existe para evitar. Ahora dice «retiro #188 de @holajuan por $4.000».
 *
 * El `@` no es adorno: es de donde crm_auditoria.php saca el nombre del
 * jugador para ponerlo en su columna.
 *
 * Si la fila no está (se borró, falta una migración) devuelve el id solo: una
 * acción registrada a medias es mejor que una acción sin registrar.
 */
function ret_referencia(PDO $pdo, int $id): string
{
    try {
        $q = $pdo->prepare("SELECT usuario, monto FROM acciones_saldo WHERE id = ? LIMIT 1");
        $q->execute([$id]);
        $f = $q->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) { $f = null; }
    if (!$f) { return 'retiro #' . $id; }
    return 'retiro #' . $id . ' de @' . $f['usuario']
         . ' por $' . number_format((float)$f['monto'], 0, ',', '.');
}

// ============================== POST ========================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $body   = json_decode(file_get_contents('php://input'), true) ?: [];
    $accion = (string)($body['accion'] ?? '');

    try {
        // ---- aprobar (puntual): habilita al bot a ejecutar el retiro ----
        // Un retiro pedido por el jugador queda con aprobado=0 y el bot NO lo
        // toca (ver acciones_cola.php). Recién cuando un agente lo aprueba acá,
        // entra a la cola real y el bot lo ejecuta en el panel.
        if ($accion === 'aprobar') {
            $id = (int)($body['id'] ?? 0);
            if (!$id) { salir(['ok' => false, 'error' => 'Falta id'], 400); }

            $st = $pdo->prepare(
                "UPDATE acciones_saldo
                    SET aprobado = 1
                  WHERE id = ? AND tipo = 'retirar' AND aprobado = 0 AND estado = 'pendiente'"
            );
            $st->execute([$id]);
            if ($st->rowCount() === 0) {
                salir(['ok' => false,
                       'error' => 'Ese retiro ya no está esperando aprobación (quizás otro operador lo aprobó o cambió de estado).'], 409);
            }
            crm_bitacora($pdo, $operador, 'aprobar_retiro', ret_referencia($pdo, $id));

            /* ---- HG Cash: aprobar TAMBIEN dispara el pago de la plata ----
               El worker de Python sigue haciendo SU mitad (descontar el saldo
               en el panel de ganamos); esta es la OTRA mitad, la que antes
               era una transferencia manual del agente: pagarle al jugador.

               Solo si HG esta prendido Y el retiro tiene destino. Sin
               destino, el flujo queda como siempre (el agente transfiere a
               mano) -- aprobar nunca falla por culpa de la pasarela. */
            $hgPago = null;
            if (is_file(__DIR__ . '/hgcash_lib.php')) {
                require_once __DIR__ . '/hgcash_lib.php';
                if (function_exists('hg_activo') && hg_activo()) {
                    try {
                        $q = $pdo->prepare(
                            "SELECT a.usuario, a.monto, a.destino, a.destino_nombre, a.destino_cuit,
                                    a.hg_request_id
                               FROM acciones_saldo a WHERE a.id = ? LIMIT 1");
                        $q->execute([$id]);
                        $ret = $q->fetch(PDO::FETCH_ASSOC);
                    } catch (Throwable $e) { $ret = null; /* sin migracion 43 */ }

                    if ($ret && (string)($ret['destino'] ?? '') !== ''
                             && (string)($ret['hg_request_id'] ?? '') === '') {
                        $cli = hg_cliente_actual();
                        $externalId = ($cli ? $cli['db_nombre'] : 'gp') . ':retiro:' . $id;
                        $r = hg_cashout_crear(
                            (float)$ret['monto'], (string)$ret['destino'],
                            (string)($ret['destino_nombre'] ?? ''),
                            (string)($ret['destino_cuit'] ?? ''),
                            $externalId,
                            'Retiro ' . $ret['usuario']
                        );
                        if (!empty($r['ok'])) {
                            $pdo->prepare(
                                "UPDATE acciones_saldo SET hg_request_id=?, hg_estado=? WHERE id=?"
                            )->execute([$r['id'], $r['estado'], $id]);
                            if ($cli) {
                                hg_ledger_alta('retiro', $cli, (string)$ret['usuario'],
                                    (string)$id, $r['id'], (float)$ret['monto']);
                            }
                            crm_bitacora($pdo, $operador, 'hg_pago_retiro',
                                "id $id -> {$r['id']} ({$r['estado']})");
                            $hgPago = ['id' => $r['id'], 'estado' => $r['estado']];
                        } else {
                            /* El pago NO salio pero el retiro quedo aprobado:
                               se anota el motivo para que el agente lo vea y
                               pague a mano. Nunca se reintenta solo: es plata. */
                            $pdo->prepare(
                                "UPDATE acciones_saldo SET hg_estado='ERROR', mensaje=? WHERE id=?"
                            )->execute(['HG: ' . ($r['error'] ?? 'sin detalle'), $id]);
                            crm_bitacora($pdo, $operador, 'hg_pago_fallo',
                                "id $id: " . ($r['error'] ?? ''));
                            $hgPago = ['error' => $r['error'] ?? 'HG no pudo pagar'];
                        }
                    }
                }
            }
            salir(['ok' => true, 'id' => $id, 'hg' => $hgPago]);
        }

        // ---- liberar (masivo, acotado a retiros) ----
        if ($accion === 'liberar') {
            /* MINUTOS DE GRACIA: este boton dice "trabados", y uno que el bot
               tomo hace diez segundos no esta trabado, esta EJECUTANDOSE. Sin
               este freno, apretarlo mientras el bot corre devolvia esa accion a
               'pendiente' y el bot la podia tomar de nuevo: un retiro pagado
               dos veces, con un solo click y sin aviso.
               Una pasada del bot son segundos; 10 minutos es holgado de sobra
               para lo que de verdad quedo colgado. `tomada_en` NULL en
               'procesando' ya es una fila inconsistente: esa se libera igual. */
            $GRACIA_MIN = 10;
            $st = $pdo->prepare(
                "UPDATE acciones_saldo
                    SET estado = 'pendiente', tomada_en = NULL
                  WHERE estado = 'procesando' AND tipo = 'retirar'
                    AND (tomada_en IS NULL OR tomada_en <= NOW() - INTERVAL ? MINUTE)"
            );
            $st->execute([$GRACIA_MIN]);
            $liberadas = $st->rowCount();

            // Los que se saltearon por recientes: decirlo, si no el operador
            // ve "0 liberados" y cree que el boton no anda.
            $recientes = (int)$pdo->query(
                "SELECT COUNT(*) FROM acciones_saldo
                  WHERE estado = 'procesando' AND tipo = 'retirar'"
            )->fetchColumn();

            crm_bitacora($pdo, $operador, 'liberar_retiros_trabados',
                "$liberadas liberados" . ($recientes ? ", $recientes en curso sin tocar" : ''));
            salir(['ok' => true, 'liberadas' => $liberadas, 'en_curso' => $recientes]);
        }

        // ---- reintentar (puntual, solo desde error) ----
        if ($accion === 'reintentar') {
            $id = (int)($body['id'] ?? 0);
            if (!$id) { salir(['ok' => false, 'error' => 'Falta id'], 400); }

            $st = $pdo->prepare(
                "UPDATE acciones_saldo
                    SET estado = 'pendiente', tomada_en = NULL
                  WHERE id = ? AND tipo = 'retirar' AND estado = 'error'"
            );
            $st->execute([$id]);
            if ($st->rowCount() === 0) {
                salir(['ok' => false, 'error' => 'Ese retiro ya no está en error (puede que otro operador ya lo haya tocado).'], 409);
            }
            crm_bitacora($pdo, $operador, 'reintentar_retiro', ret_referencia($pdo, $id));
            salir(['ok' => true]);
        }

        /* ---- marcar como PAGADO A MANO ----
           El agujero mas concreto de esta pantalla: un retiro en 'revisar'
           (p. ej. "retiro: lo resuelve un agente") no tenia forma de cerrarse.
           La unica accion disponible era CANCELAR, que le devuelve el saldo --
           o sea que despues de pagarle por transferencia, la unica opcion del
           CRM era regalarle la plata otra vez. Por eso quedaban ahi para
           siempre: en la captura de Nahuel habia uno de hace 26 dias.

           Esto cierra el pedido SIN tocar saldo: la plata ya salio del banco,
           el jugador ya la tiene, lo unico que falta es dejarlo registrado.
           Pide nota obligatoria porque es la unica prueba de que se pago: no
           hay comprobante automatico del otro lado. */
        if ($accion === 'marcar_pagado') {
            $id   = (int)($body['id'] ?? 0);
            $nota = trim((string)($body['nota'] ?? ''));
            $NOTA_MAX = 200;

            if (!$id) { salir(['ok' => false, 'error' => 'Falta id'], 400); }
            if (mb_strlen($nota) < 8) {
                salir(['ok' => false, 'error' =>
                    'Contá cómo lo pagaste (mínimo 8 caracteres). Es la única constancia.'], 400);
            }
            if (mb_strlen($nota) > $NOTA_MAX) {
                salir(['ok' => false, 'error' => "La nota es muy larga (máximo $NOTA_MAX caracteres)"], 400);
            }
            if (!crm_rate_limite("pagado_retiro_$operador", 30, 3600)) {
                salir(['ok' => false, 'error' => 'Demasiados en poco tiempo. Esperá un rato.'], 429);
            }

            /* Estados desde los que se puede: los que estan ESPERANDO que pase
               algo. Desde 'hecha' no (ya esta), desde 'cancelada' tampoco (se
               decidio no pagarlo, y revivirlo a mano esconderia esa decision),
               y desde 'procesando' menos: el bot puede estar ejecutandolo justo
               ahora y quedarian dos pagos. */
            $st = $pdo->prepare(
                "SELECT id, usuario, monto, estado, mensaje FROM acciones_saldo
                  WHERE id = ? AND tipo = 'retirar' LIMIT 1"
            );
            $st->execute([$id]);
            $fila = $st->fetch(PDO::FETCH_ASSOC);
            if (!$fila) { salir(['ok' => false, 'error' => 'Ese retiro no existe'], 404); }
            if (!in_array($fila['estado'], ['pendiente', 'revisar', 'error'], true)) {
                salir(['ok' => false, 'error' =>
                    'No se puede marcar como pagado un retiro en estado: ' . $fila['estado']], 409);
            }

            $msg = mb_substr("pagado a mano por $operador: $nota", 0, 300);
            $upd = $pdo->prepare(
                "UPDATE acciones_saldo
                    SET estado = 'hecha', mensaje = ?, ejecutada_en = NOW()
                  WHERE id = ? AND tipo = 'retirar'
                    AND estado IN ('pendiente','revisar','error')"
            );
            $upd->execute([$msg, $id]);
            if ($upd->rowCount() === 0) {
                salir(['ok' => false, 'error' =>
                    'Otro operador lo tocó recién. Recargá y fijate cómo quedó.'], 409);
            }

            crm_bitacora($pdo, $operador, 'retiro_pagado_a_mano', json_encode([
                'id' => $id, 'usuario' => $fila['usuario'],
                'monto' => (float)$fila['monto'], 'desde' => $fila['estado'], 'nota' => $nota,
            ], JSON_UNESCAPED_UNICODE));

            salir(['ok' => true, 'mensaje' => 'Retiro marcado como pagado']);
        }

        /* ---- deshacer un "pagado a mano" ----
           Para los testeos, que era el pedido, pero tambien para el error
           honesto: marcaste pagado el retiro equivocado.

           SOLO se puede deshacer lo que marco UNA PERSONA. Si el estado
           'hecha' lo puso el bot, la plata SALIO de verdad del saldo en
           ganamos, y volver la fila a 'pendiente' seria mentir: el CRM diria
           que se debe algo que ya se pago, y alguien lo pagaria dos veces.
           Se distingue por el mensaje, que es la misma marca que usa la
           auditoria para saber quien actuo: 'pagado a mano por X:'.

           Vuelve a 'pendiente' y NO a 'cancelada': deshacer es volver al
           estado anterior, no decidir que no se paga. Si ademas hay que
           cancelarlo, esta el boton de cancelar. */
        if ($accion === 'deshacer_pagado') {
            $id = (int)($body['id'] ?? 0);
            if (!$id) { salir(['ok' => false, 'error' => 'Falta id'], 400); }
            if (!crm_rate_limite("deshacer_retiro_$operador", 20, 3600)) {
                salir(['ok' => false, 'error' => 'Demasiados en poco tiempo. Esperá un rato.'], 429);
            }

            $st = $pdo->prepare(
                "SELECT id, usuario, monto, estado, mensaje FROM acciones_saldo
                  WHERE id = ? AND tipo = 'retirar' LIMIT 1"
            );
            $st->execute([$id]);
            $fila = $st->fetch(PDO::FETCH_ASSOC);
            if (!$fila) { salir(['ok' => false, 'error' => 'Ese retiro no existe'], 404); }
            if ($fila['estado'] !== 'hecha') {
                salir(['ok' => false, 'error' =>
                    'Solo se deshace un retiro marcado como pagado. Este está en: '
                    . $fila['estado']], 409);
            }
            if (!preg_match('/pagado a mano por [^:]+:/u', (string)$fila['mensaje'])) {
                salir(['ok' => false, 'error' =>
                    'Este retiro lo ejecutó el sistema: la plata ya salió del saldo en ganamos. '
                    . 'Deshacerlo acá diría que se le debe algo que ya cobró. Si hay que corregirlo, '
                    . 'hacelo en el panel.'], 409);
            }

            $msg = mb_substr("marcado pagado y DESHECHO por $operador", 0, 300);
            $upd = $pdo->prepare(
                "UPDATE acciones_saldo
                    SET estado = 'pendiente', ejecutada_en = NULL, mensaje = ?
                  WHERE id = ? AND tipo = 'retirar' AND estado = 'hecha'"
            );
            $upd->execute([$msg, $id]);
            if ($upd->rowCount() === 0) {
                salir(['ok' => false, 'error' => 'Otro operador lo tocó recién. Recargá.'], 409);
            }

            crm_bitacora($pdo, $operador, 'retiro_pagado_deshecho', json_encode([
                'id' => $id, 'usuario' => $fila['usuario'], 'monto' => (float)$fila['monto'],
            ], JSON_UNESCAPED_UNICODE));

            salir(['ok' => true, 'mensaje' => 'Volvió a quedar pendiente']);
        }

        // ---- cancelar (puntual, solo desde pendiente o error) ----
        if ($accion === 'cancelar') {
            $id   = (int)($body['id'] ?? 0);
            $nota = trim((string)($body['nota'] ?? ''));

            // MENSAJE_MAX tiene que coincidir con acciones_saldo.mensaje
            // (VARCHAR(300) desde 09_crm_pro.sql, nunca alterado). NOTA_MAX
            // deja margen real para el prefijo "cancelada por <operador>: "
            // (hasta 76 caracteres en el peor caso, operador de 60) mas el
            // mensaje viejo si lo hay.
            $NOTA_MAX = 200;
            $MENSAJE_MAX = 300;

            if (!$id) { salir(['ok' => false, 'error' => 'Falta id'], 400); }
            if (mb_strlen($nota) < 8) {
                salir(['ok' => false, 'error' => 'La nota es obligatoria (mínimo 8 caracteres)'], 400);
            }
            if (mb_strlen($nota) > $NOTA_MAX) {
                salir(['ok' => false, 'error' => "La nota es muy larga (máximo $NOTA_MAX caracteres)"], 400);
            }
            if (!crm_rate_limite("cancelar_retiro_$operador", 10, 3600)) {
                salir(['ok' => false, 'error' => 'Demasiadas cancelaciones en poco tiempo. Esperá un rato.'], 429);
            }

            $pdo->beginTransaction();
            try {
                $st = $pdo->prepare(
                    "SELECT id, usuario, monto, estado, mensaje FROM acciones_saldo
                      WHERE id = ? AND tipo = 'retirar' FOR UPDATE"
                );
                $st->execute([$id]);
                $fila = $st->fetch(PDO::FETCH_ASSOC);

                if (!$fila) {
                    $pdo->rollBack();
                    salir(['ok' => false, 'error' => 'Ese retiro no existe'], 404);
                }
                /* 'revisar' ENTRA, y es el agujero que faltaba cerrar: el
                   bot no supo como termino, asi que la unica salida que habia
                   era «Pagado» -- o sea que para cerrar uno que decidiste NO
                   pagar tenias que declarar que lo pagaste. Quedaban abiertos
                   para siempre (habia uno de 26 dias).
                   Es seguro: 'revisar' significa que el bot YA solto la accion,
                   no que la este ejecutando -- ese es 'procesando', que sigue
                   afuera. Y la nota obligatoria deja dicho por que.
                   OJO con lo que significa: si el retiro SI habia entrado, el
                   jugador ya cobro y cancelar solo cierra la fila; si no habia
                   entrado, no cobra. Por eso lo decide una persona. */
                if (!in_array($fila['estado'], ['pendiente', 'error', 'revisar'], true)) {
                    $pdo->rollBack();
                    salir(['ok' => false, 'error' => 'No se puede cancelar un retiro en estado: ' . $fila['estado']], 409);
                }

                // Se arma en PHP, no con CONCAT_WS en SQL: el mensaje viejo
                // puede venir ya cerca del tope de 300 por si solo (un error
                // real del bot), y ahi ningun limite a la nota nueva alcanza
                // para garantizar que entre. Si no entra todo, se recorta el
                // mensaje VIEJO (contexto historico) y se preserva completa
                // la nota de cancelacion (lo mas reciente y accionable) --
                // en vez de dejar el truncamiento en manos del sql_mode del
                // servidor, que no puedo verificar sin acceso a la base.
                $colaNueva = "cancelada por $operador: $nota";
                $mensajeViejo = trim((string)($fila['mensaje'] ?? ''));
                if ($mensajeViejo === '') {
                    $mensajeFinal = $colaNueva;
                } else {
                    $espacio = $MENSAJE_MAX - mb_strlen($colaNueva) - 3; // 3 = " | "
                    $mensajeFinal = $espacio > 0
                        ? mb_substr($mensajeViejo, 0, $espacio) . ' | ' . $colaNueva
                        : $colaNueva; // no queda lugar ni para un fragmento del viejo
                }

                $pdo->prepare(
                    "UPDATE acciones_saldo SET estado = 'cancelada', mensaje = ?, tomada_en = NOW() WHERE id = ?"
                )->execute([$mensajeFinal, $id]);

                $pdo->commit();
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) { $pdo->rollBack(); }
                error_log('crm_retiros cancelar: ' . $e->getMessage());
                salir(['ok' => false, 'error' => 'No se pudo cancelar'], 500);
            }

            // Recien despues del commit, mismo criterio que el resto del CRM:
            // si el aviso fallara adentro de la transaccion, quedaria
            // avisado un jugador de algo que en realidad no se aplico.
            // Sin monto a proposito: no exponer detalle interno al jugador.
            if (function_exists('notif_crear')) {
                notif_crear(
                    $pdo,
                    (string)$fila['usuario'],
                    'Retiro cancelado',
                    'Tu retiro fue cancelado por el equipo. Consultá por chat si necesitás más info.',
                    'aviso',
                    null,
                    'crm'
                );
            }

            /* EN CASTELLANO Y NO EN JSON. Esta línea se lee en Auditoría, y
               ahí `{"id":169,"usuario":"holagustavo861","monto":2000,...}` es
               ruido: el operador tiene que decodificar a ojo lo que podría
               estar escrito. Los mismos datos, en el orden en que se
               preguntan: a quién, cuánto, y por qué -- que es la nota que el
               CRM ya obliga a escribir.
               El `@` adelante no es adorno: es lo que le permite a la
               auditoría sacar el nombre del jugador y ponerlo en su columna
               (ver la rama crm_bitacora de crm_auditoria.php). */
            crm_bitacora($pdo, $operador, 'cancelar_retiro',
                'retiro #' . $id . ' de @' . $fila['usuario']
                . ' por $' . number_format((float)$fila['monto'], 0, ',', '.')
                . ($nota !== '' ? ' · ' . $nota : ''));

            salir(['ok' => true, 'id' => $id, 'estado' => 'cancelada']);
        }

        salir(['ok' => false, 'error' => 'Acción desconocida'], 400);
    } catch (Throwable $e) {
        error_log('crm_retiros POST: ' . $e->getMessage());
        salir(['ok' => false, 'error' => 'Error'], 500);
    }
}

salir(['ok' => false, 'error' => 'Método no permitido'], 405);
