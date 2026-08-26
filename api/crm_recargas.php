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

// ============================== GET =========================================
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $accion = (string)($_GET['accion'] ?? 'listar');

    try {
        if ($accion === 'listar') {
            rl_vencer($pdo); // refresca lo que ya se puede confirmar en la columna real

            $estado = (string)($_GET['estado'] ?? '');
            $q      = trim((string)($_GET['q'] ?? ''));
            $limit  = min(max((int)($_GET['limit'] ?? 100), 1), 200);

            $where  = [];
            $params = [];
            if ($estado === 'pendiente') {
                $where[] = "estado = 'pendiente' AND vence_en >= NOW()";
            } elseif ($estado === 'vencida') {
                $where[] = "(estado = 'vencida' OR " . RC_VENCIDA_SQL . ")";
            } elseif (in_array($estado, ['acreditada', 'cancelada'], true)) {
                $where[]  = 'estado = ?';
                $params[] = $estado;
            } else {
                // "Todos" (sin filtro explicito): excluir vencidas, mismo
                // criterio que Retiros excluye 'cancelada' por defecto --
                // hay un tab aparte para consultarlas.
                $where[] = "NOT (estado = 'vencida' OR " . RC_VENCIDA_SQL . ")";
            }
            if ($q !== '') {
                $where[]  = '(usuario LIKE ? OR referencia = ? OR id = ?)';
                $params[] = '%' . $q . '%';
                $params[] = strtoupper($q);
                $params[] = (int)(ctype_digit($q) ? $q : 0);
            }

            $st = $pdo->prepare(
                "SELECT id, referencia, usuario, coins, monto_base, monto_pedido, centavos,
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
            $items = array_map(function ($r) {
                $r['id']                   = (int)$r['id'];
                $r['coins']                = (int)$r['coins'];
                $r['monto_base']           = (float)$r['monto_base'];
                $r['monto_pedido']         = (float)$r['monto_pedido'];
                $r['centavos']             = $r['centavos'] !== null ? (int)$r['centavos'] : null;
                $r['segundos_para_vencer'] = $r['segundos_para_vencer'] !== null ? (int)$r['segundos_para_vencer'] : null;
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
