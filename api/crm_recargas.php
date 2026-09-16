<?php
/**
 * crm_recargas.php — Backend del módulo "Cargas" (recargas por transferencia).
 *
 * recargas.estado NO transiciona sola: rl_vencer() (recargas_lib.php) es
 * perezoso y solo corre en los puntos de entrada existentes (crear, consultar,
 * matchear). Por eso "vencida" acá es, en parte, un estado CALCULADO
 * (estado_efectivo): una fila puede seguir en 'pendiente' en la columna y
 * estar objetivamente vencida por vence_en. La condicion SQL de abajo
 * (RC_VENCIDA_SQL) es la version equivalente de rl_estado_efectivo()
 * (recargas_lib.php) para poder filtrar/contar con el indice
 * ix_estado_vence (sql/24_recargas_ix_estado_vence.sql) sin traer todo a
 * PHP. Si cambia una, cambia la otra.
 *
 * "cancelar" solo procede si estado='pendiente' AND pago_id IS NULL. Un
 * pago_id NO NULL implica SIEMPRE estado='acreditada' (rl_acreditar() los
 * escribe juntos, ver recargas_lib.php) -- en teoria nunca deberia darse la
 * combinacion pendiente+pago_id, pero el chequeo queda explicito igual, con
 * un mensaje claro en vez de un 409 generico, por si el dato real sorprende.
 *
 * Fase A, Módulo 3 (ver CRM_DESIGN.md).
 *
 * GET  ?accion=listar&estado=&q=&limit=100  -> { ok, items:[...] }
 * GET  ?accion=contar                       -> { ok, contadores:{...} }
 * GET  ?accion=detalle&id=                  -> { ok, recarga:{...}, pago:{...}|null }
 * POST { accion:"cancelar", id, nota }      -> 'pendiente' -> 'cancelada'
 */

declare(strict_types=1);
require __DIR__ . '/config.php';
require __DIR__ . '/db.php';
require __DIR__ . '/crm_lib.php';
require __DIR__ . '/recargas_lib.php';
require __DIR__ . '/crm_auth.php';

header('Content-Type: application/json; charset=utf-8');
$operador = exigir_operador();

function salir($data, int $code = 200): void
{
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

// Version SQL de rl_estado_efectivo() (recargas_lib.php) -- ver docblock arriba.
const RC_VENCIDA_SQL = "(estado = 'pendiente' AND vence_en < NOW())";
/* La misma condicion con el alias de la tabla, para la consulta con JOIN. Van
   como dos constantes y no como un str_replace sobre la primera: un replace de
   "estado" pisaria tambien el `estado` de `pagos` o de `acciones_saldo` el dia
   que alguien lo toque, y el bug seria silencioso. */
const RC_VENCIDA_SQL_R = "(r.estado = 'pendiente' AND r.vence_en < NOW())";

// ============================== GET =========================================
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $accion = (string)($_GET['accion'] ?? 'listar');

    try {
        if ($accion === 'listar') {
            rl_vencer($pdo); // refresca lo que ya se puede confirmar en la columna real

            $estado = (string)($_GET['estado'] ?? '');
            $q      = trim((string)($_GET['q'] ?? ''));
            $limit  = min(max((int)($_GET['limit'] ?? 100), 1), 200);

            /* Dos juegos de condiciones: `$where` sin alias (la consulta de
               respaldo, sin JOIN) y `$whereR` con `r.` (la principal). Se
               arman juntos para que no puedan divergir. */
            $where  = [];
            $whereR = [];
            $params = [];
            $cond = function (string $sin, ?string $con = null) use (&$where, &$whereR) {
                $where[]  = $sin;
                $whereR[] = $con ?? $sin;
            };
            if ($estado === 'pendiente') {
                $cond("estado = 'pendiente' AND vence_en >= NOW()",
                      "r.estado = 'pendiente' AND r.vence_en >= NOW()");
            } elseif ($estado === 'vencida') {
                $cond("(estado = 'vencida' OR " . RC_VENCIDA_SQL . ")",
                      "(r.estado = 'vencida' OR " . RC_VENCIDA_SQL_R . ")");
            } elseif (in_array($estado, ['acreditada', 'cancelada'], true)) {
                $cond('estado = ?', 'r.estado = ?');
                $params[] = $estado;
            } elseif ($estado === 'todas') {
                // El historial completo, vencidas incluidas. Pestaña aparte
                // porque no es lo que se viene a mirar a esta pantalla.
                // (sin condicion de estado)
            } else {
                /* El default ("Activas") deja afuera las vencidas: son
                   transferencias que nunca llegaron y no piden nada. Se
                   llamaba "Todos", y con 21 ahi y 52 en "Vencida" el numero no
                   cerraba ni habia forma de entender por que. Mismo criterio
                   que Retiros con las resueltas: el default es lo que importa
                   hoy, el historial esta a un clic. */
                $cond("NOT (estado = 'vencida' OR " . RC_VENCIDA_SQL . ")",
                      "NOT (r.estado = 'vencida' OR " . RC_VENCIDA_SQL_R . ")");
            }
            if ($q !== '') {
                $cond('(usuario LIKE ? OR referencia = ? OR id = ?)',
                      '(r.usuario LIKE ? OR r.referencia = ? OR r.id = ?)');
                $params[] = '%' . $q . '%';
                $params[] = strtoupper($q);
                $params[] = (int)(ctype_digit($q) ? $q : 0);
            }
            // Sin condiciones (pestaña "Todas"): un WHERE vacio es sintaxis invalida.
            if (!$where)  { $where[]  = '1'; }
            if (!$whereR) { $whereR[] = '1'; }

            /* ¿LAS FICHAS LLEGARON AL JUEGO? Es OTRA pregunta que "¿llego la
               plata?", y es la que de verdad le importa al jugador.
               `recargas.estado='acreditada'` solo dice que el pago del banco
               caso. El deposito en el juego es un paso aparte, en
               `acciones_saldo`, y puede fallar por su cuenta: el 13/9/2026
               holamiliii550 figuraba ACREDITADA y nunca vio sus fichas -- el
               WAF habia cortado el deposito. Esta pantalla no tenia forma de
               mostrarlo, asi que el operador no podia enterarse mirando.
               Se trae la accion de carga de ese jugador MAS CERCANA en el
               tiempo a la acreditacion: es la que se creo por esta recarga.
               LEFT JOIN + subconsulta para no romper si la tabla no existe en
               una instalacion vieja (se degrada abajo, en el catch).

               EL ANCLA ES `acreditada_en`, NO `creada_en`, y con una ventana de
               10 minutos. Primero lo ate a `creada_en` y emparejaba mal: a una
               recarga PENDIENTE -- que por definicion todavia no genero ningun
               deposito, porque la accion se crea recien al acreditar -- le
               enganchaba el deposito de OTRA recarga anterior del mismo
               jugador. En pantalla eso se veia como "Esperando pago" +
               "Fichas en el juego" a la vez, que es imposible y peor que no
               mostrar nada: informa mal sobre plata. Visto con holapablo757.
               rl_cargar_al_juego_auto() se llama inmediatamente despues de
               rl_acreditar(), asi que la accion buena nace a segundos de
               `acreditada_en`; 10 minutos es margen de sobra y a la vez
               demasiado poco para agarrar la carga siguiente del jugador.

               El COLLATE del JOIN es obligatorio: `recargas.usuario` quedo en
               utf8mb4_general_ci y `acciones_saldo.usuario` en
               utf8mb4_unicode_ci, y sin el MySQL corta con el error de mezcla
               de collations. Es el choque que avisa CLAUDE.md y muerde en cada
               JOIN nuevo entre estas tablas. */
            $sqlBase =
                "SELECT r.id, r.referencia, r.usuario, r.coins, r.monto_base, r.monto_pedido,
                        r.estado,
                        CASE WHEN " . RC_VENCIDA_SQL_R . " THEN 'vencida' ELSE r.estado END AS estado_efectivo,
                        r.pago_id, r.mensaje, r.creada_en, r.vence_en, r.acreditada_en,
                        TIMESTAMPDIFF(SECOND, NOW(), r.vence_en) AS segundos_para_vencer,
                        p.remitente AS pago_remitente, p.cuit AS pago_cuit, p.monto AS pago_monto,
                        a.id AS accion_id, a.estado AS accion_estado, a.monto AS accion_monto,
                        a.bono_debitado AS accion_bono, a.mensaje AS accion_mensaje,
                        a.ejecutada_en AS accion_ejecutada_en
                   FROM recargas r
                   LEFT JOIN pagos p ON p.id = r.pago_id
                   LEFT JOIN acciones_saldo a
                          ON a.id = (SELECT a2.id FROM acciones_saldo a2
                                      WHERE a2.usuario = r.usuario COLLATE utf8mb4_unicode_ci
                                        AND a2.tipo = 'cargar'
                                        AND r.acreditada_en IS NOT NULL
                                        AND a2.creada_en >= r.acreditada_en
                                        AND a2.creada_en < r.acreditada_en + INTERVAL 10 MINUTE
                                      ORDER BY a2.creada_en ASC LIMIT 1)
                  WHERE " . implode(' AND ', $whereR) . "
                  ORDER BY r.creada_en DESC
                  LIMIT $limit";
            try {
                $st = $pdo->prepare($sqlBase);
                $st->execute($params);
            } catch (Throwable $e) {
                /* Sin `pagos` o sin `acciones_saldo` (instalacion vieja): se
                   sirve lo de siempre. Perder el dato nuevo es aceptable;
                   dejar la pantalla en blanco, no. */
                error_log('crm_recargas/listar: sin join, degrado: ' . $e->getMessage());
                $st = $pdo->prepare(
                    "SELECT id, referencia, usuario, coins, monto_base, monto_pedido,
                            estado,
                            CASE WHEN " . RC_VENCIDA_SQL . " THEN 'vencida' ELSE estado END AS estado_efectivo,
                            pago_id, mensaje, creada_en, vence_en, acreditada_en,
                            TIMESTAMPDIFF(SECOND, NOW(), vence_en) AS segundos_para_vencer
                       FROM recargas
                      WHERE " . implode(' AND ', $where) . "
                      ORDER BY creada_en DESC
                      LIMIT $limit"
                );
                $st->execute($params);
            }
            $items = array_map(function ($r) {
                $r['id']                   = (int)$r['id'];
                $r['coins']                = (int)$r['coins'];
                $r['monto_base']           = (float)$r['monto_base'];
                $r['monto_pedido']         = (float)$r['monto_pedido'];
                $r['segundos_para_vencer'] = $r['segundos_para_vencer'] !== null ? (int)$r['segundos_para_vencer'] : null;
                $r['accion_id']            = isset($r['accion_id']) ? (int)$r['accion_id'] : null;
                $r['accion_monto']         = isset($r['accion_monto']) ? (float)$r['accion_monto'] : null;
                $r['accion_bono']          = isset($r['accion_bono']) ? (int)$r['accion_bono'] : null;
                $r['pago_monto']           = isset($r['pago_monto']) ? (float)$r['pago_monto'] : null;
                /* El resumen que mira el operador de un vistazo:
                     'ok'       las fichas estan en el juego
                     'en_curso' encolado, todavia no salio
                     'trabado'  fallo o necesita a una persona  <- lo importante
                     null       no aplica (todavia no se acredito el pago) */
                $est = $r['accion_estado'] ?? null;
                $r['fichas'] = $est === null ? null
                    : ($est === 'hecha' ? 'ok'
                    : (in_array($est, ['pendiente', 'procesando'], true) ? 'en_curso' : 'trabado'));
                return $r;
            }, $st->fetchAll(PDO::FETCH_ASSOC));

            salir(['ok' => true, 'items' => $items]);
        }

        if ($accion === 'contar') {
            rl_vencer($pdo);
            $row = $pdo->query(
                "SELECT
                    SUM(estado = 'pendiente' AND vence_en >= NOW()) AS pendiente,
                    SUM(estado = 'acreditada') AS acreditada,
                    SUM(estado = 'vencida' OR " . RC_VENCIDA_SQL . ") AS vencida,
                    SUM(estado = 'cancelada') AS cancelada
                 FROM recargas"
            )->fetch(PDO::FETCH_ASSOC);

            $pendiente = (int)($row['pendiente'] ?? 0);
            $acreditada = (int)($row['acreditada'] ?? 0);
            $vencida = (int)($row['vencida'] ?? 0);
            $cancelada = (int)($row['cancelada'] ?? 0);

            salir(['ok' => true, 'contadores' => [
                'todos'      => $pendiente + $acreditada + $cancelada,
                'pendiente'  => $pendiente,
                'acreditada' => $acreditada,
                'vencida'    => $vencida,
                'cancelada'  => $cancelada,
            ]]);
        }

        if ($accion === 'detalle') {
            $id = (int)($_GET['id'] ?? 0);
            if (!$id) { salir(['ok' => false, 'error' => 'Falta id'], 400); }

            $st = $pdo->prepare("SELECT * FROM recargas WHERE id = ? LIMIT 1");
            $st->execute([$id]);
            $r = $st->fetch(PDO::FETCH_ASSOC);
            if (!$r) { salir(['ok' => false, 'error' => 'No existe'], 404); }

            $r['id']            = (int)$r['id'];
            $r['coins']         = (int)$r['coins'];
            $r['monto_base']    = (float)$r['monto_base'];
            $r['monto_pedido']  = (float)$r['monto_pedido'];
            $r['centavos']      = $r['centavos'] !== null ? (int)$r['centavos'] : null;
            $r['estado_efectivo'] = rl_estado_efectivo((string)$r['estado'], $r['vence_en']);

            $pago = null;
            if ($r['pago_id'] !== null) {
                $stp = $pdo->prepare(
                    "SELECT id, id_unico, monto, remitente, cuit, estado, capturado_en
                       FROM pagos WHERE id_unico = ? LIMIT 1"
                );
                $stp->execute([$r['pago_id']]);
                $pago = $stp->fetch(PDO::FETCH_ASSOC) ?: null;
                if ($pago) {
                    $pago['id']    = (int)$pago['id'];
                    $pago['monto'] = (float)$pago['monto'];
                }
            }

            salir(['ok' => true, 'recarga' => $r, 'pago' => $pago]);
        }

        salir(['ok' => false, 'error' => 'Acción desconocida'], 400);
    } catch (Throwable $e) {
        error_log('crm_recargas GET: ' . $e->getMessage());
        salir(['ok' => false, 'error' => 'Error al consultar'], 500);
    }
}

// ============================== POST ========================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $body   = json_decode(file_get_contents('php://input'), true) ?: [];
    $accion = (string)($body['accion'] ?? '');

    try {
        /* ---- destrabar: volver a encolar un deposito que quedo en revisar ----
           Es la accion que faltaba. Hasta ahora una recarga podia figurar
           ACREDITADA -- el pago del banco caso bien -- y las fichas no haber
           llegado nunca al juego, porque el deposito es un paso aparte que
           puede fallar solo. Pasó el 13/9/2026 con holamiliii550: el WAF corto
           el deposito y no habia forma de reintentarlo desde el CRM. Habia que
           cargarle a mano desde el panel.

           SOLO desde 'revisar'. Los otros estados no corresponden:
             'hecha'                   ya se deposito; reintentar seria pagar dos veces;
             'error'                   las fichas ya se le devolvieron al jugador, puede
                                       volver a pedir la carga por el chat;
             'pendiente'/'procesando'  ya esta en la cola, no hace falta tocar nada.

           Y 'revisar' significa literalmente "no sabemos si entro": por eso la
           confirmacion del CRM le pide al operador que mire el panel ANTES.
           Esa decision es suya, no del sistema -- el sistema no reintenta solo
           en este estado justamente porque no puede saberlo. */
        if ($accion === 'destrabar') {
            $id = (int)($body['id'] ?? 0);
            if (!$id) { salir(['ok' => false, 'error' => 'Falta id'], 400); }
            if (!crm_rate_limite("destrabar_carga_$operador", 20, 3600)) {
                salir(['ok' => false, 'error' => 'Demasiados reintentos en poco tiempo. Esperá un rato.'], 429);
            }

            $st = $pdo->prepare("SELECT id, usuario, creada_en FROM recargas WHERE id = ? LIMIT 1");
            $st->execute([$id]);
            $rec = $st->fetch(PDO::FETCH_ASSOC);
            if (!$rec) { salir(['ok' => false, 'error' => 'Esa recarga no existe'], 404); }

            // La misma accion que muestra el listado: la primera carga de ese
            // jugador creada despues de la recarga.
            $sa = $pdo->prepare(
                "SELECT id, estado, monto FROM acciones_saldo
                  WHERE usuario = ? COLLATE utf8mb4_unicode_ci AND tipo = 'cargar'
                    AND creada_en >= ?
                  ORDER BY creada_en ASC LIMIT 1"
            );
            $sa->execute([$rec['usuario'], $rec['creada_en']]);
            $acc = $sa->fetch(PDO::FETCH_ASSOC);
            if (!$acc) {
                salir(['ok' => false, 'error' => 'Esta recarga no tiene un depósito encolado.'], 404);
            }
            if ($acc['estado'] !== 'revisar') {
                salir(['ok' => false, 'error' =>
                    'Solo se puede reintentar un depósito que quedó en revisar. Este está en: '
                    . $acc['estado']], 409);
            }

            $upd = $pdo->prepare(
                "UPDATE acciones_saldo
                    SET estado = 'pendiente', tomada_en = NULL, intentos = 0,
                        mensaje = ?
                  WHERE id = ? AND estado = 'revisar'"
            );
            $msg = mb_substr("reintento pedido por $operador desde el CRM", 0, 300);
            try {
                $upd->execute([$msg, (int)$acc['id']]);
            } catch (Throwable $e) {
                // Sin la migracion 62 no existe `intentos`.
                $pdo->prepare(
                    "UPDATE acciones_saldo SET estado='pendiente', tomada_en=NULL, mensaje=?
                      WHERE id = ? AND estado = 'revisar'"
                )->execute([$msg, (int)$acc['id']]);
            }

            /* Queda en la bitacora: mueve fichas y lo decidio una persona.
               Si alguien reintenta algo que ya habia entrado, esto es lo que
               permite reconstruir quien y cuando. */
            crm_bitacora($pdo, $operador, 'destrabar_deposito', json_encode([
                'recarga_id' => $id,
                'accion_id'  => (int)$acc['id'],
                'usuario'    => $rec['usuario'],
                'monto'      => (float)$acc['monto'],
            ], JSON_UNESCAPED_UNICODE));

            salir(['ok' => true, 'accion_id' => (int)$acc['id'],
                   'mensaje' => 'El depósito volvió a la cola. En un minuto se reintenta.']);
        }

        /* ---- resolver: cerrar un depósito trabado que YA se resolvió a mano ----
           El otro lado de 'destrabar'. Cuando el operador ya le depositó las
           fichas por el panel (o lo resolvió de otra forma), la acción sigue en
           'revisar' y el watchdog (monitor-cargas.sh) manda el Telegram "hay
           cargas que el jugador pagó y no recibió" cada rato. Hasta ahora no
           había forma de cerrarla desde el CRM: 'destrabar' la RE-ENCOLA (la
           depositaría de nuevo = pago doble) y no aplica. Esto la marca
           'cancelada' -- NO deposita ni devuelve nada, solo cierra la fila para
           que el aviso pare. La plata ya la movió la persona; esto es admin.
           SOLO desde 'revisar', como 'destrabar'. Queda en la bitácora. */
        if ($accion === 'resolver') {
            $id = (int)($body['id'] ?? 0);
            if (!$id) { salir(['ok' => false, 'error' => 'Falta id'], 400); }
            if (!crm_rate_limite("resolver_carga_$operador", 30, 3600)) {
                salir(['ok' => false, 'error' => 'Demasiados en poco tiempo. Esperá un rato.'], 429);
            }

            $st = $pdo->prepare("SELECT id, usuario, creada_en FROM recargas WHERE id = ? LIMIT 1");
            $st->execute([$id]);
            $rec = $st->fetch(PDO::FETCH_ASSOC);
            if (!$rec) { salir(['ok' => false, 'error' => 'Esa recarga no existe'], 404); }

            // La misma acción que muestra el listado (destrabar usa esto igual):
            // la primera carga de ese jugador creada después de la recarga.
            $sa = $pdo->prepare(
                "SELECT id, estado, monto FROM acciones_saldo
                  WHERE usuario = ? COLLATE utf8mb4_unicode_ci AND tipo = 'cargar'
                    AND creada_en >= ?
                  ORDER BY creada_en ASC LIMIT 1"
            );
            $sa->execute([$rec['usuario'], $rec['creada_en']]);
            $acc = $sa->fetch(PDO::FETCH_ASSOC);
            if (!$acc) {
                salir(['ok' => false, 'error' => 'Esta recarga no tiene un depósito encolado.'], 404);
            }
            if ($acc['estado'] !== 'revisar') {
                salir(['ok' => false, 'error' =>
                    'Solo se puede cerrar un depósito que quedó en revisar. Este está en: '
                    . $acc['estado']], 409);
            }

            $msg = mb_substr("cerrada a mano por $operador: ya resuelta fuera de la cola", 0, 300);
            $upd = $pdo->prepare(
                "UPDATE acciones_saldo
                    SET estado = 'cancelada', mensaje = ?
                  WHERE id = ? AND estado = 'revisar'"
            );
            $upd->execute([$msg, (int)$acc['id']]);
            if ($upd->rowCount() !== 1) {
                // Otro la cerró/tocó entre el SELECT y el UPDATE.
                salir(['ok' => false, 'error' => 'La carga cambió de estado, refrescá.'], 409);
            }

            crm_bitacora($pdo, $operador, 'resolver_deposito', json_encode([
                'recarga_id' => $id,
                'accion_id'  => (int)$acc['id'],
                'usuario'    => $rec['usuario'],
                'monto'      => (float)$acc['monto'],
            ], JSON_UNESCAPED_UNICODE));

            salir(['ok' => true, 'accion_id' => (int)$acc['id'],
                   'mensaje' => 'Listo, la carga quedó cerrada y el aviso deja de sonar.']);
        }

        // ---- cancelar (puntual, solo desde pendiente sin pago vinculado) ----
        if ($accion === 'cancelar') {
            $id   = (int)($body['id'] ?? 0);
            $nota = trim((string)($body['nota'] ?? ''));

            // Mismos topes y mismo criterio que crm_retiros.php::cancelar:
            // NOTA_MAX deja margen real para el prefijo "cancelada por
            // <operador>: " mas el mensaje viejo si lo hay; MENSAJE_MAX
            // coincide con recargas.mensaje (VARCHAR(300)).
            $NOTA_MAX = 200;
            $MENSAJE_MAX = 300;

            if (!$id) { salir(['ok' => false, 'error' => 'Falta id'], 400); }
            if (mb_strlen($nota) < 8) {
                salir(['ok' => false, 'error' => 'La nota es obligatoria (mínimo 8 caracteres)'], 400);
            }
            if (mb_strlen($nota) > $NOTA_MAX) {
                salir(['ok' => false, 'error' => "La nota es muy larga (máximo $NOTA_MAX caracteres)"], 400);
            }
            if (!crm_rate_limite("cancelar_carga_$operador", 10, 3600)) {
                salir(['ok' => false, 'error' => 'Demasiadas cancelaciones en poco tiempo. Esperá un rato.'], 429);
            }

            $pdo->beginTransaction();
            try {
                $st = $pdo->prepare(
                    "SELECT id, usuario, referencia, monto_pedido, estado, pago_id, mensaje
                       FROM recargas WHERE id = ? FOR UPDATE"
                );
                $st->execute([$id]);
                $fila = $st->fetch(PDO::FETCH_ASSOC);

                if (!$fila) {
                    $pdo->rollBack();
                    salir(['ok' => false, 'error' => 'Esa recarga no existe'], 404);
                }
                if ($fila['estado'] !== 'pendiente') {
                    $pdo->rollBack();
                    salir(['ok' => false, 'error' => 'No se puede cancelar una recarga en estado: ' . $fila['estado']], 409);
                }
                if ($fila['pago_id'] !== null) {
                    $pdo->rollBack();
                    salir(['ok' => false, 'error' =>
                        "Esta recarga tiene un pago vinculado (id {$fila['pago_id']}). No se puede cancelar " .
                        "desde el CRM — contactá al desarrollador o resolvé la relación manualmente en pagos."
                    ], 409);
                }

                // PHP-side, no CONCAT_WS: mismo motivo que crm_retiros.php
                // (el mensaje viejo puede venir ya cerca del tope por si
                // solo). Se prioriza la nota nueva, se recorta el contexto
                // viejo si no entra todo.
                $colaNueva = "cancelada por $operador: $nota";
                $mensajeViejo = trim((string)($fila['mensaje'] ?? ''));
                if ($mensajeViejo === '') {
                    $mensajeFinal = $colaNueva;
                } else {
                    $espacio = $MENSAJE_MAX - mb_strlen($colaNueva) - 3; // 3 = " | "
                    $mensajeFinal = $espacio > 0
                        ? mb_substr($mensajeViejo, 0, $espacio) . ' | ' . $colaNueva
                        : $colaNueva;
                }

                $pdo->prepare(
                    "UPDATE recargas
                        SET estado = 'cancelada', mensaje = ?, cancelada_por = ?, cancelada_en = NOW()
                      WHERE id = ?"
                )->execute([$mensajeFinal, $operador, $id]);

                $pdo->commit();
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) { $pdo->rollBack(); }
                error_log('crm_recargas cancelar: ' . $e->getMessage());
                salir(['ok' => false, 'error' => 'No se pudo cancelar'], 500);
            }

            // Recien despues del commit (mismo criterio que el resto del
            // CRM). Sin monto/referencia a proposito: no exponer detalle
            // interno al jugador.
            if (function_exists('notif_crear')) {
                notif_crear(
                    $pdo,
                    (string)$fila['usuario'],
                    'Recarga cancelada',
                    'Tu solicitud de recarga fue cancelada. Consultá por chat si necesitás más info.',
                    'aviso',
                    null,
                    'crm'
                );
            }

            crm_bitacora($pdo, $operador, 'cancelar_recarga', json_encode([
                'id'           => $id,
                'usuario'      => $fila['usuario'],
                'referencia'   => $fila['referencia'],
                'monto_pedido' => (float)$fila['monto_pedido'],
                'nota'         => $nota,
            ], JSON_UNESCAPED_UNICODE));

            salir(['ok' => true, 'id' => $id, 'estado' => 'cancelada']);
        }

        salir(['ok' => false, 'error' => 'Acción desconocida'], 400);
    } catch (Throwable $e) {
        error_log('crm_recargas POST: ' . $e->getMessage());
        salir(['ok' => false, 'error' => 'Error'], 500);
    }
}

salir(['ok' => false, 'error' => 'Método no permitido'], 405);
