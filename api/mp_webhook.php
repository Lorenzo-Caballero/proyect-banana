<?php
/**
 * mp_webhook.php — Notificación de pago de MercadoPago. Desplegar en el VPS,
 * NUNCA en Hostinger: el WAF de Hostinger bloquea POSTs sin User-Agent de
 * navegador, y las notificaciones de MP son server-to-server (ver CLAUDE.md).
 *
 * Nunca confía en el payload que llega -- cualquiera podría mandar un POST
 * directo acá simulando un pago aprobado. Lo único confiable es volver a
 * pedirle el pago a la propia API de MercadoPago con el access token.
 *
 * POST /gp-api/mp_webhook.php   (sin auth propia -- la seguridad es la
 *                                 verificación server-side contra la API de MP)
 */

declare(strict_types=1);
require __DIR__ . '/config.php';

header('Content-Type: application/json; charset=utf-8');

function salir($data, int $code = 200): void
{
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function control_pdo(): PDO
{
    return new PDO(
        'mysql:host=' . cfg('DB_HOST', 'localhost') . ';dbname=' . cfg('CONTROL_DB_NAME', 'goldpaw_control') . ';charset=utf8mb4',
        cfg('DB_USER'), cfg('DB_PASS'),
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );
}

function config_plataforma_get(PDO $ctl, string $clave, string $default = ''): string
{
    $st = $ctl->prepare('SELECT valor FROM config_plataforma WHERE clave = ?');
    $st->execute([$clave]);
    $v = $st->fetchColumn();
    return (is_string($v) && $v !== '') ? $v : $default;
}

function dolar_blue(): ?float
{
    $ctx = stream_context_create(['http' => ['timeout' => 5, 'header' => "User-Agent: GoldpawCRM/1.0\r\n"]]);
    $raw = @file_get_contents('https://dolarapi.com/v1/dolares/blue', false, $ctx);
    if ($raw === false) { return null; }
    $j = json_decode($raw, true);
    $venta = $j['venta'] ?? null;
    return is_numeric($venta) ? (float)$venta : null;
}

// MP manda el id del pago por query string o en el body, según la versión.
$paymentId = (string)($_GET['data_id'] ?? $_GET['id'] ?? '');
if ($paymentId === '') {
    $raw  = file_get_contents('php://input');
    $body = json_decode($raw, true) ?: [];
    $paymentId = (string)($body['data']['id'] ?? '');
}
if ($paymentId === '' || !ctype_digit($paymentId)) {
    // Nada que procesar (puede ser un ping de prueba de MP) -- 200 igual,
    // no es un error nuestro.
    salir(['ok' => true, 'ignorado' => true]);
}

$ctl   = control_pdo();
$token = config_plataforma_get($ctl, 'MP_ACCESS_TOKEN');
if ($token === '') {
    error_log('mp_webhook: MP_ACCESS_TOKEN no configurado');
    salir(['ok' => false, 'error' => 'sin configurar'], 500);
}

// Único punto confiable: preguntarle a MP por el pago real, nunca al payload.
$ch = curl_init('https://api.mercadopago.com/v1/payments/' . $paymentId);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $token],
    CURLOPT_TIMEOUT => 15,
]);
$resp = curl_exec($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($resp === false || $code >= 300) {
    error_log('mp_webhook: no se pudo verificar el pago ' . $paymentId . ' (HTTP ' . $code . ')');
    // 500 para que MP reintente más tarde -- puede ser un problema transitorio.
    salir(['ok' => false, 'error' => 'no se pudo verificar'], 500);
}

$pago = json_decode((string)$resp, true);
$status = (string)($pago['status'] ?? '');
$externalRef = (string)($pago['external_reference'] ?? '');
$montoArsConfirmado = (float)($pago['transaction_amount'] ?? 0);

// external_reference = "<cliente_id>:<pagos_plataforma.id>"
$partes = explode(':', $externalRef, 2);
$clienteId = isset($partes[0]) && ctype_digit($partes[0]) ? (int)$partes[0] : 0;
$pagoLocalId = isset($partes[1]) && ctype_digit($partes[1]) ? (int)$partes[1] : 0;

if ($clienteId <= 0 || $pagoLocalId <= 0) {
    error_log('mp_webhook: external_reference inválido: ' . $externalRef);
    salir(['ok' => true, 'ignorado' => true]); // no es nuestro pago, no reintentar
}

if ($status !== 'approved') {
    $ctl->prepare(
        "UPDATE pagos_plataforma SET estado='rechazado', raw_webhook=? WHERE id=? AND estado='pendiente'"
    )->execute([json_encode($pago), $pagoLocalId]);
    salir(['ok' => true, 'status' => $status]);
}

// Conversión a USD con el blue del momento; si la API externa falla, usa el
// fallback configurado y marca la fila para revisión manual -- un pago
// aprobado NUNCA queda sin acreditar por la caída de un servicio de terceros.
$blue = dolar_blue();
$estadoFinal = 'acreditado';
if ($blue === null) {
    $blue = (float)config_plataforma_get($ctl, 'TC_ARS_USD_FALLBACK', '1000');
    $estadoFinal = 'revision';
}
$montoUsd = $blue > 0 ? round($montoArsConfirmado / $blue, 2) : 0;

$pdo = $ctl;
$pdo->beginTransaction();
try {
    $upd = $pdo->prepare(
        "UPDATE pagos_plataforma SET payment_id=?, monto_usd=?, tipo_cambio=?, estado=?,
                acreditado_en=NOW(), raw_webhook=?
          WHERE id=? AND cliente_id=? AND estado='pendiente'"
    );
    $upd->execute([$paymentId, $montoUsd, $blue, $estadoFinal, json_encode($pago), $pagoLocalId, $clienteId]);

    if ($upd->rowCount() !== 1) {
        // Ya estaba procesado (reintento de MP) o no matchea -- no duplicar.
        $pdo->rollBack();
        salir(['ok' => true, 'ya_procesado' => true]);
    }

    $pdo->prepare(
        "UPDATE clientes SET saldo_usd = saldo_usd + ?,
                suscripcion_estado = IF(saldo_usd + ? > 0, 'activa', suscripcion_estado)
          WHERE id = ?"
    )->execute([$montoUsd, $montoUsd, $clienteId]);

    $pdo->commit();
    salir(['ok' => true, 'acreditado' => $montoUsd]);
} catch (Throwable $e) {
    $pdo->rollBack();
    error_log('mp_webhook: error acreditando pago ' . $pagoLocalId . ': ' . $e->getMessage());
    salir(['ok' => false, 'error' => 'error interno'], 500);
}
