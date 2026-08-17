<?php
/**
 * crm_retiros.php — Backend del módulo "Retiros pendientes".
 *
 * Cola de acciones_saldo (tipo='retirar') que ejecuta bot/bot_cargar_fichas.py
 * en FICHAS_MODE=LIVE. Este endpoint da visibilidad de la cola completa y dos
 * acciones de mantenimiento.
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
 * Fase A, Módulo 2 (ver CRM_DESIGN.md).
 *
 * GET  ?accion=badge               -> { ok, cantidad } — SOLO 'procesando'
 *                                      trabado hace mas de 30 min (la unica
 *                                      señal realmente urgente; un pendiente
 *                                      normal no alerta)
 * GET  ?accion=listar&estado=&q=   -> { ok, items:[...] }
 * POST { accion:"liberar" }        -> 'procesando' -> 'pendiente' (masivo,
 *                                      solo tipo='retirar')
 * POST { accion:"reintentar", id } -> 'error' -> 'pendiente' (puntual)
 */

declare(strict_types=1);
require __DIR__ . '/config.php';
require __DIR__ . '/db.php';
require __DIR__ . '/crm_lib.php';
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
        if ($accion === 'badge') {
            $n = (int)$pdo->query(
                "SELECT COUNT(*) FROM acciones_saldo
                  WHERE tipo='retirar' AND estado='procesando'
                    AND tomada_en < DATE_SUB(NOW(), INTERVAL 30 MINUTE)"
            )->fetchColumn();
            salir(['ok' => true, 'cantidad' => $n]);
        }

        if ($accion === 'listar') {
            $estado = (string)($_GET['estado'] ?? '');
            $q      = trim((string)($_GET['q'] ?? ''));

            $where  = ["tipo = 'retirar'"];
            $params = [];
            if (in_array($estado, ['pendiente', 'procesando', 'hecha', 'error', 'revisar'], true)) {
                $where[]  = 'estado = ?';
                $params[] = $estado;
            }
            if ($q !== '') {
                $where[]  = '(usuario LIKE ? OR id = ?)';
                $params[] = '%' . $q . '%';
                $params[] = (int)(ctype_digit($q) ? $q : 0);
            }

            $st = $pdo->prepare(
                "SELECT id, usuario, monto, motivo, estado, tomada_en, mensaje,
                        saldo_antes, saldo_despues, creada_en, ejecutada_en
                   FROM acciones_saldo
                  WHERE " . implode(' AND ', $where) . "
                  ORDER BY creada_en DESC
                  LIMIT 200"
            );
            $st->execute($params);
            $items = array_map(function ($r) {
                $r['id']            = (int)$r['id'];
                $r['monto']         = (float)$r['monto'];
                $r['saldo_antes']   = $r['saldo_antes']   !== null ? (float)$r['saldo_antes']   : null;
                $r['saldo_despues'] = $r['saldo_despues'] !== null ? (float)$r['saldo_despues'] : null;
                return $r;
            }, $st->fetchAll(PDO::FETCH_ASSOC));

            salir(['ok' => true, 'items' => $items]);
        }

        salir(['ok' => false, 'error' => 'Acción desconocida'], 400);
    } catch (Throwable $e) {
        error_log('crm_retiros GET: ' . $e->getMessage());
        salir(['ok' => false, 'error' => 'Error al consultar'], 500);
    }
}

// ============================== POST ========================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $body   = json_decode(file_get_contents('php://input'), true) ?: [];
    $accion = (string)($body['accion'] ?? '');

    try {
        // ---- liberar (masivo, acotado a retiros) ----
        if ($accion === 'liberar') {
            $st = $pdo->prepare(
                "UPDATE acciones_saldo
                    SET estado = 'pendiente', tomada_en = NULL
                  WHERE estado = 'procesando' AND tipo = 'retirar'"
            );
            $st->execute();
            $liberadas = $st->rowCount();
            crm_bitacora($pdo, $operador, 'liberar_retiros_trabados', "$liberadas liberados");
            salir(['ok' => true, 'liberadas' => $liberadas]);
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
            crm_bitacora($pdo, $operador, 'reintentar_retiro', "id $id");
            salir(['ok' => true]);
        }

        salir(['ok' => false, 'error' => 'Acción desconocida'], 400);
    } catch (Throwable $e) {
        error_log('crm_retiros POST: ' . $e->getMessage());
        salir(['ok' => false, 'error' => 'Error'], 500);
    }
}

salir(['ok' => false, 'error' => 'Método no permitido'], 405);
