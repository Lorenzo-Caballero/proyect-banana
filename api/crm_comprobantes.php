<?php
/**
 * crm_comprobantes.php — Backend del módulo "Comprobantes sin resolver".
 *
 * Pagos que colector_mail.php capturó pero que rl_matchear_y_acreditar() no
 * pudo casar solo con ninguna recarga pendiente (quedaron en estado
 * 'revision'). Este endpoint deja que un operador los asigne a mano.
 *
 * Fase A, Módulo 1 (ver CRM_DESIGN.md).
 *
 * GET  ?accion=badge                              -> { ok, cantidad }
 * GET  ?accion=listar                             -> { ok, items:[...] }
 * GET  ?accion=detalle&pago_id=<id_unico>&q=texto  -> { ok, pago, candidatas:[...] }
 * POST { accion:"asignar", pago_id, recarga_id }
 */

declare(strict_types=1);
require __DIR__ . '/config.php';
require __DIR__ . '/db.php';
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

// ============================== GET =========================================
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $accion = (string)($_GET['accion'] ?? 'listar');

    try {
        /* Las transferencias reservadas para una carga del camino A (pedida
           desde el boton Depositos) NO se ofrecen acá: las resuelve
           aprobar_cargas.py y asignarlas a mano además haría cobrar dos veces.
           Se ven en «Cargas del panel». rl_asignar_manual() lo revalida por las
           dudas, pero mejor no ofrecer lo que después se va a rechazar.

           El LEFT JOIN va sin COLLATE: las dos tablas comparten collation (ver
           sql/48). Si la migración 48 no corrió, se cae al SELECT de siempre. */
        $reservadas =
            " LEFT JOIN peticiones_carga q
                     ON q.pago_id_unico = p.id_unico
                    AND q.estado IN ('esperando','revision') ";
        $noReservada = " AND q.request_id IS NULL ";

        if ($accion === 'badge') {
            try {
                $n = (int)$pdo->query(
                    "SELECT COUNT(*) FROM pagos p $reservadas
                      WHERE p.estado='revision' $noReservada"
                )->fetchColumn();
            } catch (PDOException $e) {
                $n = (int)$pdo->query("SELECT COUNT(*) FROM pagos WHERE estado='revision'")->fetchColumn();
            }
            salir(['ok' => true, 'cantidad' => $n]);
        }

        if ($accion === 'listar') {
            $cols = "p.id, p.id_unico, p.monto, p.remitente, p.cuit, p.cbu_origen,
                     p.nro_transaccion, p.entidad, p.fecha_operacion, p.mail_de, p.capturado_en";
            try {
                $st = $pdo->query(
                    "SELECT $cols FROM pagos p $reservadas
                      WHERE p.estado = 'revision' $noReservada
                      ORDER BY p.capturado_en DESC
                      LIMIT 100"
                );
            } catch (PDOException $e) {
                $st = $pdo->query(
                    "SELECT $cols FROM pagos p
                      WHERE p.estado = 'revision'
                      ORDER BY p.capturado_en DESC
                      LIMIT 100"
                );
            }
            $items = array_map(function ($r) {
                $r['monto'] = (float)$r['monto'];
                return $r;
            }, $st->fetchAll(PDO::FETCH_ASSOC));
            salir(['ok' => true, 'items' => $items]);
        }

        if ($accion === 'detalle') {
            $idUnico = trim((string)($_GET['pago_id'] ?? ''));
            if ($idUnico === '') { salir(['ok' => false, 'error' => 'Falta pago_id'], 400); }

            $st = $pdo->prepare(
                "SELECT id, id_unico, monto, remitente, cuit, cbu_origen, nro_transaccion,
                        entidad, fecha_operacion, mail_de, capturado_en
                   FROM pagos WHERE id_unico = ? AND estado = 'revision' LIMIT 1"
            );
            $st->execute([$idUnico]);
            $pago = $st->fetch(PDO::FETCH_ASSOC);
            if (!$pago) { salir(['ok' => false, 'error' => 'Ese pago ya no está en revisión'], 404); }
            $pago['monto'] = (float)$pago['monto'];

            // Sin q: las 20 recargas pendientes mas parecidas en monto (la
            // candidata correcta suele aparecer primero sin escribir nada).
            // Con q: ademas filtra por usuario, referencia exacta o id.
            $q = trim((string)($_GET['q'] ?? ''));
            $st2 = $pdo->prepare(
                "SELECT id, referencia, usuario, coins, monto_base, monto_pedido, centavos,
                        titular_declarado, creada_en, vence_en
                   FROM recargas
                  WHERE estado = 'pendiente'
                    AND (? = '' OR usuario LIKE ? OR referencia = ? OR id = ?)
                  ORDER BY ABS(monto_pedido - ?) ASC, creada_en ASC
                  LIMIT 20"
            );
            $st2->execute([$q, '%' . $q . '%', strtoupper($q), (int)($q !== '' ? $q : 0), $pago['monto']]);
            $candidatas = array_map(function ($r) {
                $r['id']           = (int)$r['id'];
                $r['coins']        = (int)$r['coins'];
                $r['monto_base']   = (float)$r['monto_base'];
                $r['monto_pedido'] = (float)$r['monto_pedido'];
                return $r;
            }, $st2->fetchAll(PDO::FETCH_ASSOC));

            /* Por que este pago PODRIA ser de cada candidata.
               Si el matcher automatico no la eligio es porque algo no le
               alcanzo -- dos titulares parecidos, o ninguno declarado. Pero
               las mismas señales que el uso sirven igual para ordenarle la
               lista al operador y que no tenga que compararlas de memoria.
               ACA NO SE ACREDITA NADA: es solo el orden y la explicacion, la
               decision sigue siendo de la persona. */
            $conHuella = function_exists('rl_usuarios_por_huella')
                ? rl_usuarios_por_huella($pdo, $pago) : [];
            foreach ($candidatas as &$c) {
                $c['huella']  = in_array((string)$c['usuario'], $conHuella, true);
                $c['parecido'] = (function_exists('rl_similitud_nombres') && trim((string)$c['titular_declarado']) !== '')
                    ? rl_similitud_nombres((string)$pago['remitente'], (string)$c['titular_declarado'])
                    : 0.0;
                if ($c['huella']) {
                    $c['motivo'] = 'ya cargó antes desde esta cuenta';
                } elseif ($c['parecido'] >= RL_UMBRAL_NOMBRE) {
                    $c['motivo'] = sprintf('el titular coincide (%.0f%%)', $c['parecido'] * 100);
                } elseif (abs($c['monto_pedido'] - $pago['monto']) < 0.005) {
                    $c['motivo'] = 'el monto es exacto';
                } else {
                    $c['motivo'] = '';
                }
            }
            unset($c);

            // Primero la huella (señal exacta), despues el parecido de
            // nombre, y a igualdad el monto mas cercano -- el mismo orden de
            // confianza que usa el matcher automatico.
            usort($candidatas, function ($a, $b) use ($pago) {
                if ($a['huella'] !== $b['huella'])       { return $b['huella'] <=> $a['huella']; }
                if ($a['parecido'] !== $b['parecido'])   { return $b['parecido'] <=> $a['parecido']; }
                return abs($a['monto_pedido'] - $pago['monto']) <=> abs($b['monto_pedido'] - $pago['monto']);
            });

            salir(['ok' => true, 'pago' => $pago, 'candidatas' => $candidatas]);
        }

        salir(['ok' => false, 'error' => 'Acción desconocida'], 400);
    } catch (Throwable $e) {
        error_log('crm_comprobantes GET: ' . $e->getMessage());
        salir(['ok' => false, 'error' => 'Error al consultar'], 500);
    }
}

// ============================== POST ========================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $body   = json_decode(file_get_contents('php://input'), true) ?: [];
    $accion = (string)($body['accion'] ?? '');

    if ($accion === 'asignar') {
        $idUnico   = trim((string)($body['pago_id'] ?? ''));
        $recargaId = (int)($body['recarga_id'] ?? 0);
        if ($idUnico === '' || !$recargaId) {
            salir(['ok' => false, 'error' => 'Faltan datos'], 400);
        }

        $r = rl_asignar_manual($pdo, $idUnico, $recargaId, $operador);
        if ($r['resultado'] !== 'acreditada') {
            salir(['ok' => false, 'error' => $r['error'] ?? 'No se pudo asignar'], 409);
        }
        salir(array_merge(['ok' => true], $r));
    }

    salir(['ok' => false, 'error' => 'Acción desconocida'], 400);
}

salir(['ok' => false, 'error' => 'Método no permitido'], 405);
