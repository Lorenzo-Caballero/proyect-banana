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
exigir_operador();

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
    $accion = (string)($_GET['accion'] ?? 'listar');

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
        try {
            $st = $pdo->query(
                "SELECT request_id, username, titular, monto, alias_destino, creada_api,
                        primera_vez, estado, confianza, motivo, pago_id_unico,
                        actualizada_en,
                        TIMESTAMPDIFF(MINUTE, primera_vez, NOW()) AS minutos
                   FROM peticiones_carga
                  ORDER BY FIELD(estado,'revision','error','esperando','aprobada','cerrada'),
                           primera_vez DESC
                  LIMIT 200"
            );
        } catch (PDOException $e) {
            salir(['ok' => true, 'items' => [], 'sin_migracion' => true]);
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

    salir(['ok' => false, 'error' => 'Acción desconocida'], 400);

} catch (Throwable $e) {
    error_log('crm_peticiones: ' . $e->getMessage());
    salir(['ok' => false, 'error' => 'Error'], 500);
}
