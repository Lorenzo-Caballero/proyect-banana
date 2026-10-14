<?php
/**
 * crm_por_aprobar.php — La cola "Por aprobar" del modo asistido.
 *
 * Solo aplica a los clientes en modo asistido (goldpaw_control.recarga_auto=0):
 * ahí el matcher del mail del banco confirma la plata pero NO acredita, y deja
 * la recarga esperando el visto bueno de un agente. Este endpoint la lista y la
 * cierra con un click.
 *
 * Es un archivo APARTE de crm_recargas.php (el módulo "Cargas", que lista TODAS
 * las recargas): acá la cola es solo las 'por_aprobar' y la acción acredita.
 *
 *   GET  ?accion=badge   -> { ok, cantidad }     cuántas esperan aprobación
 *   GET  ?accion=listar  -> { ok, items:[...] }   con quién/cuánto/de qué cuenta
 *   POST { accion:"aprobar", id }                 acredita de verdad (idempotente)
 *
 * NO hay "rechazar" acá: una transferencia que entró y se confirmó no se
 * rechaza desde esta cola. Si algo está mal (no era para este jugador, llegó de
 * menos), es un caso de revisión, no de "aprobar o tirar".
 */

declare(strict_types=1);

require __DIR__ . '/config.php';
require __DIR__ . '/db.php';
require __DIR__ . '/crm_auth.php';
require_once __DIR__ . '/recargas_lib.php';

header('Content-Type: application/json; charset=utf-8');

$operador = exigir_operador();
if (is_file(__DIR__ . '/crm_lib.php')) { require_once __DIR__ . '/crm_lib.php'; }

function salir($data, int $code = 200): void
{
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $cuerpo = $_SERVER['REQUEST_METHOD'] === 'POST'
        ? (json_decode(file_get_contents('php://input'), true) ?: [])
        : [];
    $accion = (string)($_GET['accion'] ?? $cuerpo['accion'] ?? 'listar');

    if ($accion === 'badge') {
        try {
            $n = (int)$pdo->query("SELECT COUNT(*) FROM recargas WHERE estado='por_aprobar'")->fetchColumn();
        } catch (Throwable $e) {
            $n = 0;   // sin la migración 80 no hay estado: cola vacía
        }
        salir(['ok' => true, 'cantidad' => $n]);
    }

    if ($accion === 'listar') {
        $items = rl_recargas_por_aprobar($pdo);
        salir(['ok' => true, 'items' => $items]);
    }

    if ($accion === 'aprobar') {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            salir(['ok' => false, 'error' => 'Usá POST para aprobar'], 405);
        }
        $id = (int)($cuerpo['id'] ?? 0);
        if ($id <= 0) { salir(['ok' => false, 'error' => 'Falta el id de la recarga'], 422); }

        $r = rl_aprobar_recarga($pdo, $id, $operador);

        switch ($r['resultado'] ?? 'error') {
            case 'acreditada':
                if (function_exists('crm_bitacora')) {
                    crm_bitacora($pdo, $operador, 'recarga_aprobada', json_encode([
                        'recarga_id' => $id, 'usuario' => $r['usuario'] ?? '',
                        'coins' => $r['coins'] ?? 0, 'referencia' => $r['referencia'] ?? '',
                    ], JSON_UNESCAPED_UNICODE));
                }
                salir(['ok' => true, 'mensaje' =>
                    'Acreditadas ' . number_format((int)($r['coins'] ?? 0), 0, ',', '.')
                    . ' fichas a ' . ($r['usuario'] ?? '') . '.']);
            case 'ya_resuelta':
                // Otro agente la aprobó (o la movió) entre que se listó y se
                // apretó. No es un error: se le dice y se refresca la lista.
                salir(['ok' => true, 'ya_resuelta' => true, 'mensaje' =>
                    'Esta recarga ya la resolvió otra persona (quedó ' . ($r['estado'] ?? '') . ').']);
            case 'no_existe':
                salir(['ok' => false, 'error' => 'Esa recarga no existe'], 404);
            case 'sin_pago':
                salir(['ok' => false, 'error' =>
                    'La recarga no tiene la transferencia atada; mirala a mano antes de acreditar.'], 409);
            default:
                salir(['ok' => false, 'error' => 'No se pudo aprobar'], 500);
        }
    }

    salir(['ok' => false, 'error' => 'Acción desconocida'], 400);

} catch (Throwable $e) {
    error_log('crm_por_aprobar: ' . $e->getMessage());
    salir(['ok' => false, 'error' => 'Error'], 500);
}
