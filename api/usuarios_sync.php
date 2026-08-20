<?php
/**
 * usuarios_sync.php — Recibe lotes de usuarios del panel (los manda
 *                     sync_usuarios.py) y los upsertea en la tabla `usuarios`.
 *
 * POST  { "usuarios": [ {id, username, balance, bonus, total_deposits,
 *                        role, is_banned, creation_date}, ... ] }
 *   header: X-API-Key  (o X-Api-Token) = BOT_API_KEY
 *   -> { "ok":true, "recibidos":N, "guardados":N }
 */

declare(strict_types=1);
require __DIR__ . '/config.php';
require __DIR__ . '/db.php';

header('Content-Type: application/json; charset=utf-8');

$key = cfg('BOT_API_KEY');
$enviada = $_SERVER['HTTP_X_API_KEY'] ?? $_SERVER['HTTP_X_API_TOKEN'] ?? '';
if ($key === '' || strlen($key) < 16 || !hash_equals($key, $enviada)) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'No autorizado']);
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Usa POST']);
    exit;
}

$body = json_decode(file_get_contents('php://input'), true) ?: [];
$usuarios = (isset($body['usuarios']) && is_array($body['usuarios'])) ? $body['usuarios'] : [];
if (!$usuarios) {
    echo json_encode(['ok' => true, 'recibidos' => 0, 'guardados' => 0]);
    exit;
}

// OJO: `bonus` y `coins` NO se actualizan en el UPDATE: ahora son nuestros
// bonos/fichas internos (ruleta, CRM, recargas), no vienen de ganamos. En un
// alta nueva entran en 0 (VALUES(bonus)=0); en updates se preservan.
$sql = "INSERT INTO usuarios
          (id, username, balance, bonus, total_deposits, role, is_banned, creation_date)
        VALUES (?,?,?,?,?,?,?,?)
        ON DUPLICATE KEY UPDATE
          username       = VALUES(username),
          balance        = VALUES(balance),
          total_deposits = VALUES(total_deposits),
          role           = VALUES(role),
          is_banned      = VALUES(is_banned),
          creation_date  = VALUES(creation_date)";

$num = function ($v): float {
    return is_numeric($v) ? (float)$v : 0.0;
};

$guardados = 0;
$pdo->beginTransaction();
try {
    $st = $pdo->prepare($sql);
    foreach ($usuarios as $u) {
        $id = (int)($u['id'] ?? 0);
        $username = trim((string)($u['username'] ?? ''));
        if (!$id || $username === '') {
            continue;   // fila incompleta, la salteamos
        }
        $fecha = $u['creation_date'] ?? null;
        if ($fecha !== null) {
            $fecha = substr(str_replace('T', ' ', (string)$fecha), 0, 19) ?: null;
        }
        $st->execute([
            $id,
            mb_substr($username, 0, 80),
            $num($u['balance'] ?? 0),
            $num($u['bonus'] ?? 0),
            $num($u['total_deposits'] ?? 0),
            isset($u['role']) ? (string)$u['role'] : null,
            !empty($u['is_banned']) ? 1 : 0,
            $fecha,
        ]);
        $guardados++;
    }
    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('usuarios_sync: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Fallo al guardar', 'detalle' => $e->getMessage()]);
    exit;
}

echo json_encode(['ok' => true, 'recibidos' => count($usuarios), 'guardados' => $guardados]);
