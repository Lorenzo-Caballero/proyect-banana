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

/**
 * Rate limit de "cancelar" por OPERADOR (no por IP: acá ya sabemos quién es,
 * exigir_operador() ya lo validó). Mismo mecanismo de archivo temporal que
 * crm_login.php::crm_login_limite() y api/auth.php::_limite() — deliberadamente
 * NO extraído a un helper compartido ni tocando esos dos archivos: son un
 * login de jugadores en producción y un login de operador ya probado, no
 * vale la pena rozarlos por una función de diez líneas.
 */
function crm_cancelar_limite(string $operador, int $max, int $ventanaSeg): bool
{
    $f = sys_get_temp_dir() . '/crm_cancelar_rl_' . md5($operador);
    $ahora = time();
    $hits = [];
    if (is_file($f)) {
        foreach (explode(',', (string)@file_get_contents($f)) as $t) {
            if ($t !== '' && (int)$t > $ahora - $ventanaSeg) {
                $hits[] = (int)$t;
            }
        }
    }
    if (count($hits) >= $max) {
        return false;
    }
    $hits[] = $ahora;
    @file_put_contents($f, implode(',', $hits), LOCK_EX);
    return true;
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
            if (in_array($estado, ['pendiente', 'procesando', 'hecha', 'error', 'revisar', 'cancelada'], true)) {
                $where[]  = 'estado = ?';
                $params[] = $estado;
            } else {
                // "Todos" (sin filtro explicito): no mostrar canceladas para
                // no generar ruido -- hay un tab aparte para consultarlas.
                $where[] = "estado <> 'cancelada'";
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

        // ---- cancelar (puntual, solo desde pendiente o error) ----
        if ($accion === 'cancelar') {
            $id   = (int)($body['id'] ?? 0);
            $nota = trim((string)($body['nota'] ?? ''));

            if (!$id) { salir(['ok' => false, 'error' => 'Falta id'], 400); }
            if (mb_strlen($nota) < 8) {
                salir(['ok' => false, 'error' => 'La nota es obligatoria (mínimo 8 caracteres)'], 400);
            }
            if (!crm_cancelar_limite($operador, 10, 3600)) {
                salir(['ok' => false, 'error' => 'Demasiadas cancelaciones en poco tiempo. Esperá un rato.'], 429);
            }

            $pdo->beginTransaction();
            try {
                $st = $pdo->prepare(
                    "SELECT id, usuario, monto, estado FROM acciones_saldo
                      WHERE id = ? AND tipo = 'retirar' FOR UPDATE"
                );
                $st->execute([$id]);
                $fila = $st->fetch(PDO::FETCH_ASSOC);

                if (!$fila) {
                    $pdo->rollBack();
                    salir(['ok' => false, 'error' => 'Ese retiro no existe'], 404);
                }
                if (!in_array($fila['estado'], ['pendiente', 'error'], true)) {
                    $pdo->rollBack();
                    salir(['ok' => false, 'error' => 'No se puede cancelar un retiro en estado: ' . $fila['estado']], 409);
                }

                $mensaje = mb_substr("cancelada por $operador: $nota", 0, 300);
                $pdo->prepare(
                    "UPDATE acciones_saldo SET estado = 'cancelada', mensaje = ?, tomada_en = NOW() WHERE id = ?"
                )->execute([$mensaje, $id]);

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
                    '🔴 Retiro cancelado',
                    'Tu retiro fue cancelado por el equipo. Consultá por chat si necesitás más info.',
                    'aviso',
                    null,
                    'crm'
                );
            }

            crm_bitacora($pdo, $operador, 'cancelar_retiro', json_encode([
                'id'      => $id,
                'usuario' => $fila['usuario'],
                'monto'   => (float)$fila['monto'],
                'nota'    => $nota,
            ], JSON_UNESCAPED_UNICODE));

            salir(['ok' => true, 'id' => $id, 'estado' => 'cancelada']);
        }

        salir(['ok' => false, 'error' => 'Acción desconocida'], 400);
    } catch (Throwable $e) {
        error_log('crm_retiros POST: ' . $e->getMessage());
        salir(['ok' => false, 'error' => 'Error'], 500);
    }
}

salir(['ok' => false, 'error' => 'Método no permitido'], 405);
