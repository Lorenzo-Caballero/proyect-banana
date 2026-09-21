<?php
/**
 * recaudar_cola.php — Cola de RECAUDACIONES para el bot del VPS.
 *
 * El CRM encola un pedido (crm.php ?accion=recaudar_pedir) y bot_recaudar.py
 * lo ejecuta contra el panel de agentes -- el CRM no puede abrir ese panel
 * (necesita navegador). Mismo patron que altas_cola / acciones_cola.
 *
 * Auth: header X-API-Key = BOT_API_KEY. ACA SE DISPARAN RETIROS DE PLATA:
 * sin la clave no se abre.
 *
 * GET  ?accion=pendientes   -> RECLAMA la mas vieja (pasa a 'procesando') y
 *                              devuelve sus parametros, o {datos:null} si no hay.
 * POST ?accion=marcar   body {id, estado:'hecha'|'error', resultado?, mensaje?}
 * POST ?accion=avance   body {id, resultado}   -> progreso PARCIAL, sin cerrar
 *
 * `avance` existe para que el CRM muestre la corrida MIENTRAS PASA (pedido del
 * dueño, 21/09/2026: "que se vea reflejado en el momento, retiro por retiro").
 * Antes el bot escribía una sola vez, al terminar: una recaudación de 25
 * jugadores eran varios minutos de "En curso…" y después todo junto. Con plata
 * de por medio, no ver qué está pasando es lo peor de las dos opciones.
 *
 * Escribe el MISMO campo `resultado` que usa el cierre, así el front no
 * necesita otro contrato: lee `resultado.fase` y sabe si mira un avance o el
 * final. Y deja el estado en 'procesando' -- `marcar` sigue siendo el único
 * que cierra una corrida.
 *
 * Reglas (como en acciones_cola):
 *  - Se RECLAMA antes de entregar: si dos bots leen la misma fila, uno recauda
 *    y el otro repite -- y esto RETIRA plata, el doble no se deshace.
 *  - Una sola en 'procesando' a la vez: recaudar abre el panel y navega
 *    paginas; dos corridas en paralelo se pisan el orden del listado.
 *  - Las 'procesando' colgadas > 30 min vuelven a 'pendiente' NO: el bot pudo
 *    haber retirado a medias. Quedan para que las mire una persona (igual que
 *    los depositos 'revisar'): recaudar no se reintenta solo.
 */

declare(strict_types=1);
require __DIR__ . '/config.php';
require __DIR__ . '/db.php';

header('Content-Type: application/json; charset=utf-8');
exigir_api_key();

$accion = (string)($_GET['accion'] ?? 'pendientes');
$metodo = $_SERVER['REQUEST_METHOD'];

try {
    // ------------------------- pendientes -------------------------
    if ($accion === 'pendientes') {
        // Si ya hay una en 'procesando', NO se entrega otra: recaudar es
        // secuencial (una sola sesion del panel, un solo orden del listado).
        $enCurso = $pdo->query(
            "SELECT id FROM recaudaciones WHERE estado='procesando' LIMIT 1"
        )->fetchColumn();
        if ($enCurso) {
            echo json_encode(['ok' => true, 'datos' => null, 'motivo' => 'ya hay una en curso']);
            exit;
        }

        $pdo->beginTransaction();
        $sel = $pdo->prepare(
            "SELECT id FROM recaudaciones WHERE estado='pendiente'
              ORDER BY id ASC LIMIT 1 FOR UPDATE"
        );
        $sel->execute();
        $id = $sel->fetchColumn();
        if (!$id) {
            $pdo->commit();
            echo json_encode(['ok' => true, 'datos' => null]);
            exit;
        }
        $pdo->prepare(
            "UPDATE recaudaciones SET estado='procesando', tomada_en=NOW(),
                    actualizada_en=NOW() WHERE id=?"
        )->execute([$id]);
        /* `saltar_jug` es la unidad de verdad (jugadores). `saltar` queda por
           compatibilidad con un bot viejo, que se despliega aparte. */
        try {
            $fila = $pdo->query(
                "SELECT id, dry_run, dias, saltar,
                        COALESCE(saltar_jug, saltar * 50) AS saltar_jug,
                        tope, min_saldo, COALESCE(sin_chequeo, 0) AS sin_chequeo,
                        pedido_por
                   FROM recaudaciones WHERE id=" . (int)$id
            )->fetch(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            $fila = $pdo->query(
                "SELECT id, dry_run, dias, saltar, (saltar * 50) AS saltar_jug,
                        tope, min_saldo, 0 AS sin_chequeo, pedido_por
                   FROM recaudaciones WHERE id=" . (int)$id
            )->fetch(PDO::FETCH_ASSOC);
        }
        $pdo->commit();

        echo json_encode(['ok' => true, 'datos' => [
            'id'        => (int)$fila['id'],
            'dry_run'   => (int)$fila['dry_run'] === 1,
            'dias'      => (int)$fila['dias'],
            'saltar'    => (int)$fila['saltar'],
            'saltar_jug'=> (int)$fila['saltar_jug'],
            'sin_chequeo'=> (int)($fila['sin_chequeo'] ?? 0) === 1,
            'tope'      => (int)$fila['tope'],
            'min_saldo' => (int)$fila['min_saldo'],
            'pedido_por'=> (string)($fila['pedido_por'] ?? ''),
        ]], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // --------------------------- avance ---------------------------
    /* Progreso parcial de una corrida EN CURSO. No cambia el estado: si lo
       cambiara, `pendientes` entregaría otra recaudación creyendo que esta
       terminó, y dos corridas en paralelo se pisan el orden del listado (y
       retiran plata). */
    if ($accion === 'avance' && $metodo === 'POST') {
        $body = json_decode(file_get_contents('php://input'), true) ?: [];
        $id   = (int)($body['id'] ?? 0);
        if (!$id || !isset($body['resultado'])) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'falta id o resultado']);
            exit;
        }
        $resultado = mb_substr(json_encode($body['resultado'], JSON_UNESCAPED_UNICODE), 0, 60000);
        /* El WHERE exige 'procesando': un avance que llega tarde --el bot lo
           mandó justo cuando la corrida ya cerró-- no puede reabrir ni pisar
           el resultado final con una foto a medias. */
        $pdo->prepare(
            "UPDATE recaudaciones SET resultado=?, actualizada_en=NOW()
              WHERE id=? AND estado='procesando'"
        )->execute([$resultado, $id]);
        echo json_encode(['ok' => true]);
        exit;
    }

    // --------------------------- marcar ---------------------------
    if ($accion === 'marcar' && $metodo === 'POST') {
        $body   = json_decode(file_get_contents('php://input'), true) ?: [];
        $id     = (int)($body['id'] ?? 0);
        $estado = (string)($body['estado'] ?? '');
        if (!$id || !in_array($estado, ['hecha', 'error'], true)) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'falta id o estado invalido']);
            exit;
        }
        // resultado: se guarda como JSON (lo arma el bot). Se recorta por las
        // dudas: es un resumen, no un log entero.
        $resultado = isset($body['resultado'])
            ? mb_substr(json_encode($body['resultado'], JSON_UNESCAPED_UNICODE), 0, 60000)
            : null;
        $mensaje = isset($body['mensaje']) ? mb_substr((string)$body['mensaje'], 0, 255) : null;

        $pdo->prepare(
            "UPDATE recaudaciones SET estado=?, resultado=?, mensaje=?, actualizada_en=NOW()
              WHERE id=? AND estado='procesando'"
        )->execute([$estado, $resultado, $mensaje, $id]);
        echo json_encode(['ok' => true]);
        exit;
    }

    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'accion desconocida']);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) { $pdo->rollBack(); }
    error_log('recaudar_cola: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Error', 'detalle' => $e->getMessage()]);
}
