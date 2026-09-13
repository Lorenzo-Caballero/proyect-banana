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
        $fila = $pdo->query(
            "SELECT id, dry_run, dias, saltar, tope, min_saldo, pedido_por
               FROM recaudaciones WHERE id=" . (int)$id
        )->fetch(PDO::FETCH_ASSOC);
        $pdo->commit();

        echo json_encode(['ok' => true, 'datos' => [
            'id'        => (int)$fila['id'],
            'dry_run'   => (int)$fila['dry_run'] === 1,
            'dias'      => (int)$fila['dias'],
            'saltar'    => (int)$fila['saltar'],
            'tope'      => (int)$fila['tope'],
            'min_saldo' => (int)$fila['min_saldo'],
            'pedido_por'=> (string)($fila['pedido_por'] ?? ''),
        ]], JSON_UNESCAPED_UNICODE);
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
