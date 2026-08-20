<?php
/**
 * pagos.php — Recibe las transferencias que captura el colector de mails
 *             y las manda a acreditar (rl_registrar_pago).
 *
 * POST  (JSON con el comprobante)   header: X-API-Key  (o X-Api-Token)
 *   -> { "ok":true, "nuevo":true, "resultado":"acreditada|revision|sin_monto|ya_visto", ... }
 *
 * La clave es la misma BOT_API_KEY del resto de la API.
 */

declare(strict_types=1);
require __DIR__ . '/config.php';
require __DIR__ . '/db.php';
require __DIR__ . '/recargas_lib.php';

header('Content-Type: application/json; charset=utf-8');

// Auth: aceptamos X-API-Key o X-Api-Token (el colector manda este ultimo).
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
echo json_encode(rl_registrar_pago($pdo, $body), JSON_UNESCAPED_UNICODE);
