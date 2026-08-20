<?php
/**
 * carga_estado.php — Estado de una carga (acciones_saldo) para narrar el proceso
 *                    en el chat. SOLO LECTURA.
 *
 * El front lo sondea con el id que devolvió chatbot.php al encolar la carga
 * (respuesta.carga.id) y va contando el proceso al jugador:
 *   pendiente  -> "dame un minuto que la proceso"
 *   procesando -> "ya te ubiqué, acreditando…"
 *   hecha      -> "listo, en un ratito la ves en tu panel"
 *   error/revisar/cancelada -> "tuve un inconveniente, la estoy revisando"
 *
 * Devuelve solo el `estado` (no el mensaje interno, que puede tener detalle del
 * bot). GET ?id=N -> { ok, estado } | { ok:false, error }.
 */

declare(strict_types=1);
require __DIR__ . '/config.php';
require __DIR__ . '/db.php';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

$id = (int)($_GET['id'] ?? 0);
if (!$id) {
    echo json_encode(['ok' => false, 'error' => 'falta id']);
    exit;
}

try {
    $st = $pdo->prepare(
        "SELECT estado FROM acciones_saldo WHERE id = ? AND tipo = 'cargar' LIMIT 1"
    );
    $st->execute([$id]);
    $estado = $st->fetchColumn();
    if ($estado === false) {
        echo json_encode(['ok' => false, 'error' => 'no existe']);
        exit;
    }
    echo json_encode(['ok' => true, 'estado' => (string)$estado]);
} catch (Throwable $e) {
    error_log('carga_estado: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'error']);
}
