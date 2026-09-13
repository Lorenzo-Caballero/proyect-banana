<?php
/**
 * crm_peticiones.php — Backend de la vista "Cargas del panel".
 *
 * Las cargas que el jugador pidio desde el boton Depositos de la plataforma y
 * que resuelve solo colector/aprobar_cargas.py. Esta pantalla es para MIRAR:
 * que se aprobo, que sigue esperando la transferencia y que necesita a una
 * persona -- y sobre todo POR QUE, que es justo lo que el camino B no cuenta
 * (alla el motivo de la revision se devuelve por HTTP y se pierde).
 *
 * GET ?accion=badge   -> { ok, cantidad }   las que necesitan a alguien
 * GET ?accion=listar  -> { ok, items:[...] }
 *
 * De solo lectura a proposito. Aprobar o rechazar se hace en el panel de
 * ganamos, que es donde vive la solicitud: un boton aca que "aprueba" sin poder
 * confirmar que el panel lo acepto seria mentirle al operador.
 */

declare(strict_types=1);
require __DIR__ . '/config.php';
require __DIR__ . '/db.php';
require __DIR__ . '/crm_auth.php';

header('Content-Type: application/json; charset=utf-8');
/* Se guarda quien es: hasta ahora este archivo era de SOLO LECTURA y el
   operador no hacia falta para nada mas que el permiso. Desde que se puede
   cerrar una solicitud a mano, si hace falta: queda en el motivo y en la
   bitacora. */
$operador = exigir_operador();
/* crm_lib trae crm_bitacora(). Opcional -- si falta, cerrar una solicitud sigue
   funcionando y solo se pierde el registro de quien la cerro. */
if (is_file(__DIR__ . '/crm_lib.php')) { require_once __DIR__ . '/crm_lib.php'; }

function salir($data, int $code = 200): void
{
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

// Cuanto puede esperar una solicitud antes de que valga la pena mirarla. Es el
// mismo numero que usaba el conciliador viejo (ESPERA_TRANSFER_MIN): pasado
// eso, o el jugador no transfirio o algo no esta casando.
const CRMP_ESPERA_MIN = 15;

try {
    /* La accion viaja por la query en los GET y por el cuerpo en los POST:
       'cerrar' es lo unico que escribe, y mandarlo por GET lo haria disparable
       desde un link. */
    $cuerpo = $_SERVER['REQUEST_METHOD'] === 'POST'
        ? (json_decode(file_get_contents('php://input'), true) ?: [])
        : [];
    $accion = (string)($cuerpo['accion'] ?? $_GET['accion'] ?? 'listar');

    /* El badge cuenta lo que necesita a una persona: lo ambiguo ('revision'),
       lo que el panel rechazo ('error') y lo que hace rato que espera. Las que
       recien entraron NO cuentan: el jugador todavia esta transfiriendo y un
       badge que titila cada vez que alguien pide una carga se vuelve ruido. */
    $sqlPendientes =
        "FROM peticiones_carga
          WHERE estado IN ('revision','error')
             OR (estado = 'esperando'
                 AND primera_vez < DATE_SUB(NOW(), INTERVAL " . CRMP_ESPERA_MIN . " MINUTE))";

    if ($accion === 'badge') {
        try {
            $n = (int)$pdo->query("SELECT COUNT(*) $sqlPendientes")->fetchColumn();
        } catch (PDOException $e) {
            // La migracion 48 todavia no corrio: el CRM no tiene por que
            // romperse por un modulo que aun no existe.
            salir(['ok' => true, 'cantidad' => 0]);
        }
        salir(['ok' => true, 'cantidad' => $n]);
    }

    if ($accion === 'listar') {
        /* Dos intentos, y la diferencia importa: `rechazo_pedido_en` es de la
           migracion 63 y solo sirve para mostrar "rechazo en camino". Si falta,
           NO se puede declarar `sin_migracion` -- eso vacia la pantalla entera y
           le dice al operador que falta la 48, que es otra cosa. Se sirve la
           lista sin ese dato, que es lo que importa. Sin la 48 si no hay tabla,
           y ahi si corresponde. */
        $cols = "request_id, username, titular, monto, alias_destino, creada_api,
                 primera_vez, estado, confianza, motivo, pago_id_unico, actualizada_en,
                 TIMESTAMPDIFF(MINUTE, primera_vez, NOW()) AS minutos";
        $orden = "ORDER BY FIELD(estado,'revision','error','esperando','aprobada','cerrada'),
                           primera_vez DESC
                  LIMIT 200";
        $st = null;
        try {
            $st = $pdo->query("SELECT $cols, rechazo_pedido_en FROM peticiones_carga $orden");
        } catch (PDOException $e) {
            try {
                $st = $pdo->query("SELECT $cols, NULL AS rechazo_pedido_en FROM peticiones_carga $orden");
            } catch (PDOException $e2) {
                salir(['ok' => true, 'items' => [], 'sin_migracion' => true]);
            }
        }
        $items = array_map(static function ($r) {
            $r['request_id'] = (int)$r['request_id'];
            $r['monto']      = (float)$r['monto'];
            $r['minutos']    = (int)$r['minutos'];
            // Que la solicitud lleve mucho esperando es informacion del
            // operador, no un estado distinto: sigue siendo 'esperando' y el
            // worker la sigue intentando.
            $r['demorada']   = ($r['estado'] === 'esperando' && $r['minutos'] >= CRMP_ESPERA_MIN);
            return $r;
        }, $st->fetchAll(PDO::FETCH_ASSOC));
        salir(['ok' => true, 'items' => $items, 'espera_min' => CRMP_ESPERA_MIN]);
    }

    /* ---- cerrar: esta resuelta, pero fuera del CRM ----
       EL BUG QUE ARREGLA: el jugador pide la carga desde el juego, el operador
       la aprueba A MANO en el panel de ganamos porque el matcher no pudo
       probar cual transferencia era, y el CRM se queda mostrandola como
       "Esperando la transferencia" PARA SIEMPRE. No habia forma de cerrarla:
       este archivo era de solo lectura -- badge y listar, nada mas.
       El estado 'cerrada' ya existia en el ENUM y en las etiquetas del front
       ("Resuelta fuera del CRM"): estaba previsto y nunca se implemento quien
       lo pone.

       NO TOCA PLATA, y por eso es seguro: la carga la hizo el operador en el
       panel, aca solo se deja de preguntar por ella. El worker tampoco la va a
       tomar de nuevo, porque solo mira las 'esperando'.

       La nota es obligatoria: es la unica constancia de por que se cerro sin
       que el sistema pudiera probar el pago. */
    if ($accion === 'cerrar' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $rid  = (int)($cuerpo['request_id'] ?? 0);
        $nota = trim((string)($cuerpo['nota'] ?? ''));

        if (!$rid) { salir(['ok' => false, 'error' => 'Falta request_id'], 400); }
        if (mb_strlen($nota) < 8) {
            salir(['ok' => false, 'error' =>
                'Contá por qué la cerrás (mínimo 8 caracteres). Es la única constancia.'], 400);
        }
        if (mb_strlen($nota) > 200) {
            salir(['ok' => false, 'error' => 'La nota es muy larga (máximo 200 caracteres)'], 400);
        }

        /* Solo desde los estados que ESPERAN algo. Desde 'aprobada' no tiene
           sentido (ya se resolvio sola) y desde 'cerrada' tampoco. */
        $upd = $pdo->prepare(
            "UPDATE peticiones_carga
                SET estado = 'cerrada',
                    motivo = ?,
                    actualizada_en = NOW()
              WHERE request_id = ?
                AND estado IN ('esperando','revision','error')"
        );
        $msg = mb_substr("cerrada a mano por $operador: $nota", 0, 255);
        $upd->execute([$msg, $rid]);
        if ($upd->rowCount() === 0) {
            salir(['ok' => false, 'error' =>
                'No se pudo cerrar: puede que ya esté aprobada o que otro operador la haya tocado.'], 409);
        }

        if (function_exists('crm_bitacora')) {
            crm_bitacora($pdo, $operador, 'peticion_cerrada_a_mano', json_encode([
                'request_id' => $rid, 'nota' => $nota,
            ], JSON_UNESCAPED_UNICODE));
        }
        salir(['ok' => true, 'mensaje' => 'Solicitud cerrada']);
    }

    /* ---- rechazar DE VERDAD, tambien en ganamos ----
       La diferencia con 'cerrar': aquello solo sacaba la fila de ESTA lista y
       la solicitud seguia abierta del otro lado. Esto la cancela en la
       plataforma.

       El CRM no puede hacerlo solo: ese endpoint necesita la sesion del panel,
       que la tiene el worker. Asi que aca solo se PIDE, y lo ejecuta
       colector/aprobar_cargas.py en su proxima pasada (PATCH
       /api/payment/deposit/{id} con {"status":0} -- capturado del panel el
       13/9/2026, no adivinado: el mismo endpoint que aprueba con status 1).

       LA GUARDA QUE IMPORTA: no se rechaza una solicitud que YA tiene un pago
       reclamado. Si el matcher encontro la transferencia, el jugador pago y lo
       que corresponde es aprobarla, no cancelarla -- rechazarla ahi seria
       quedarse con la plata. */
    if ($accion === 'rechazar' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $rid  = (int)($cuerpo['request_id'] ?? 0);
        $nota = trim((string)($cuerpo['nota'] ?? ''));

        if (!$rid) { salir(['ok' => false, 'error' => 'Falta request_id'], 400); }
        if (mb_strlen($nota) < 8) {
            salir(['ok' => false, 'error' =>
                'Contá por qué la rechazás (mínimo 8 caracteres). Es la única constancia.'], 400);
        }
        if (mb_strlen($nota) > 200) {
            salir(['ok' => false, 'error' => 'La nota es muy larga (máximo 200 caracteres)'], 400);
        }

        $st = $pdo->prepare(
            "SELECT request_id, username, monto, estado, pago_id_unico
               FROM peticiones_carga WHERE request_id = ? LIMIT 1"
        );
        $st->execute([$rid]);
        $fila = $st->fetch(PDO::FETCH_ASSOC);
        if (!$fila) { salir(['ok' => false, 'error' => 'Esa solicitud no existe'], 404); }
        if ($fila['estado'] !== 'esperando' && $fila['estado'] !== 'revision') {
            salir(['ok' => false, 'error' =>
                'Solo se rechaza una solicitud que sigue abierta. Esta está: ' . $fila['estado']], 409);
        }
        if (!empty($fila['pago_id_unico'])) {
            salir(['ok' => false, 'error' =>
                'Esta solicitud ya tiene una transferencia asociada: el jugador pagó. '
                . 'Lo que corresponde es aprobarla, no rechazarla.'], 409);
        }

        try {
            $upd = $pdo->prepare(
                "UPDATE peticiones_carga
                    SET rechazo_pedido_en = NOW(), rechazo_por = ?,
                        motivo = ?, actualizada_en = NOW()
                  WHERE request_id = ? AND estado IN ('esperando','revision')
                    AND rechazo_pedido_en IS NULL"
            );
            $upd->execute([mb_substr($operador, 0, 60),
                           mb_substr("rechazo pedido por $operador: $nota", 0, 255), $rid]);
        } catch (Throwable $e) {
            salir(['ok' => false, 'error' =>
                'Falta aplicar la migración 63. Avisale a soporte.'], 500);
        }
        if ($upd->rowCount() === 0) {
            salir(['ok' => false, 'error' =>
                'Ya había un rechazo pedido, o la solicitud cambió de estado.'], 409);
        }

        if (function_exists('crm_bitacora')) {
            crm_bitacora($pdo, $operador, 'peticion_rechazo_pedido', json_encode([
                'request_id' => $rid, 'usuario' => $fila['username'],
                'monto' => (float)$fila['monto'], 'nota' => $nota,
            ], JSON_UNESCAPED_UNICODE));
        }
        salir(['ok' => true,
               'mensaje' => 'Pedido de rechazo anotado. Se cancela en ganamos en menos de un minuto.']);
    }

    salir(['ok' => false, 'error' => 'Acción desconocida'], 400);

} catch (Throwable $e) {
    error_log('crm_peticiones: ' . $e->getMessage());
    salir(['ok' => false, 'error' => 'Error'], 500);
}
