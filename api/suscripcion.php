<?php
/**
 * suscripcion.php — "Mi suscripción": lo que el CLIENTE (dueño del casino que
 * usa este CRM) le paga a LA PLATAFORMA. No tiene nada que ver con
 * usuarios.balance/coins/bonus, que es plata de sus JUGADORES.
 *
 * Tenant-aware (usa db.php para saber a qué cliente pertenece la sesión que
 * pide), pero además conecta a goldpaw_control (la base maestra) porque el
 * saldo de suscripción vive ahí, no en la base del cliente.
 *
 * Llama exigir_operador(false) a propósito: un cliente SIN saldo tiene que
 * poder seguir entrando acá para pagar y destrabarse. Si este endpoint
 * exigiera saldo, un cliente bloqueado no tendría forma de salir del bloqueo.
 *
 * GET  ?accion=estado
 *        -> { ok, saldo_usd, suscripcion_estado, dias_restantes,
 *              precio_ars: {quincena, mes}, tipo_cambio_blue }
 * GET  ?accion=historial
 *        -> { ok, pagos:[{id,plan,monto_ars,monto_usd,estado,creado}] }
 * POST { accion:"crear_preference", plan:"quincena"|"mes" }
 *        -> { ok, init_point }
 */

declare(strict_types=1);
require __DIR__ . '/config.php';
require __DIR__ . '/db.php';
require __DIR__ . '/crm_auth.php';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-CSRF-Token');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

exigir_operador(false);

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

/** Cotización blue de dolarapi.com. Devuelve null si la API no responde. */
function dolar_blue(): ?float
{
    $ctx = stream_context_create(['http' => ['timeout' => 5, 'header' => "User-Agent: GoldpawCRM/1.0\r\n"]]);
    $raw = @file_get_contents('https://dolarapi.com/v1/dolares/blue', false, $ctx);
    if ($raw === false) { return null; }
    $j = json_decode($raw, true);
    $venta = $j['venta'] ?? null;
    return is_numeric($venta) ? (float)$venta : null;
}

/** Config de plataforma (config_plataforma), con default si no existe la clave. */
function config_plataforma_get(PDO $ctl, string $clave, string $default = ''): string
{
    $st = $ctl->prepare('SELECT valor FROM config_plataforma WHERE clave = ?');
    $st->execute([$clave]);
    $v = $st->fetchColumn();
    return (is_string($v) && $v !== '') ? $v : $default;
}

$ctl = control_pdo();
$st  = $ctl->prepare('SELECT id, saldo_usd, costo_diario_usd, suscripcion_estado FROM clientes WHERE db_nombre = ? LIMIT 1');
$st->execute([$GLOBALS['TENANT_DB'] ?? '']);
$cliente = $st->fetch();
if (!$cliente) { salir(['ok' => false, 'error' => 'cliente no resuelto'], 500); }

$clienteId = (int)$cliente['id'];
$accion    = $_SERVER['REQUEST_METHOD'] === 'GET' ? (string)($_GET['accion'] ?? '') : '';

if ($_SERVER['REQUEST_METHOD'] === 'GET' && $accion === 'estado') {
    $blue = dolar_blue() ?? (float)config_plataforma_get($ctl, 'TC_ARS_USD_FALLBACK', '1000');
    $costoDiario = (float)$cliente['costo_diario_usd'];
    $dias = $costoDiario > 0 ? (float)$cliente['saldo_usd'] / $costoDiario : 0;

    salir([
        'ok' => true,
        'saldo_usd' => (float)$cliente['saldo_usd'],
        'suscripcion_estado' => $cliente['suscripcion_estado'],
        'dias_restantes' => round($dias, 1),
        'precio_ars' => [
            'quincena' => round($costoDiario * 14 * $blue, 2),
            'mes'      => round($costoDiario * 30 * $blue, 2),
        ],
        'tipo_cambio_blue' => $blue,
    ]);
}

if ($_SERVER['REQUEST_METHOD'] === 'GET' && $accion === 'historial') {
    $st = $ctl->prepare(
        'SELECT id, plan, monto_ars, monto_usd, estado, creado
           FROM pagos_plataforma WHERE cliente_id = ? ORDER BY creado DESC LIMIT 50'
    );
    $st->execute([$clienteId]);
    salir(['ok' => true, 'pagos' => $st->fetchAll()]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $body = json_decode(file_get_contents('php://input'), true) ?: [];
    $accionPost = (string)($body['accion'] ?? '');

    if ($accionPost === 'crear_preference') {
        $plan = (string)($body['plan'] ?? '');
        if (!in_array($plan, ['quincena', 'mes'], true)) {
            salir(['ok' => false, 'error' => 'plan inválido'], 422);
        }

        $token = config_plataforma_get($ctl, 'MP_ACCESS_TOKEN');
        if ($token === '') {
            salir(['ok' => false, 'error' => 'El dueño de la plataforma todavía no configuró el cobro'], 500);
        }

        // Monto en ARS SIEMPRE calculado acá, nunca aceptado del cliente.
        $blue = dolar_blue() ?? (float)config_plataforma_get($ctl, 'TC_ARS_USD_FALLBACK', '1000');
        $costoDiario = (float)$cliente['costo_diario_usd'];
        $dias = $plan === 'quincena' ? 14 : 30;
        $montoArs = round($costoDiario * $dias * $blue, 2);

        // Se llama a MP primero: si falla, no queda una fila basura en pagos_plataforma.
        $pref = [
            'items' => [[
                'title' => 'Suscripción CRM Goldpaw — ' . ($plan === 'quincena' ? '2 semanas' : '1 mes'),
                'quantity' => 1,
                'unit_price' => $montoArs,
                'currency_id' => 'ARS',
            ]],
            'external_reference' => $clienteId . ':pendiente',
            'notification_url' => 'https://ganamoscrm.online/gp-api/mp_webhook.php',
            'back_urls' => [
                'success' => 'https://ganamoscrm.online/crm.html',
                'failure' => 'https://ganamoscrm.online/crm.html',
                'pending' => 'https://ganamoscrm.online/crm.html',
            ],
            'auto_return' => 'approved',
        ];

        $ch = curl_init('https://api.mercadopago.com/checkout/preferences');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Authorization: Bearer ' . $token],
            CURLOPT_POSTFIELDS => json_encode($pref),
            CURLOPT_TIMEOUT => 15,
        ]);
        $resp = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($resp === false || $code >= 300) {
            error_log('suscripcion.php crear_preference: MP respondió ' . $code . ' ' . (string)$resp);
            salir(['ok' => false, 'error' => 'No se pudo iniciar el pago, probá de nuevo'], 502);
        }

        $j = json_decode((string)$resp, true);
        $preferenceId = (string)($j['id'] ?? '');
        $initPoint    = (string)($j['init_point'] ?? '');
        if ($preferenceId === '' || $initPoint === '') {
            salir(['ok' => false, 'error' => 'Respuesta inesperada de MercadoPago'], 502);
        }

        $ctl->prepare(
            'INSERT INTO pagos_plataforma (cliente_id, preference_id, plan, monto_ars, estado)
             VALUES (?,?,?,?,\'pendiente\')'
        )->execute([$clienteId, $preferenceId, $plan, $montoArs]);
        $pagoId = (int)$ctl->lastInsertId();

        // external_reference real ahora que ya existe el id local -- se
        // actualiza la preference en MP para que el webhook pueda ubicar la
        // fila exacta sin ambigüedad.
        $chUpd = curl_init('https://api.mercadopago.com/checkout/preferences/' . $preferenceId);
        curl_setopt_array($chUpd, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => 'PUT',
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Authorization: Bearer ' . $token],
            CURLOPT_POSTFIELDS => json_encode(['external_reference' => $clienteId . ':' . $pagoId]),
            CURLOPT_TIMEOUT => 15,
        ]);
        curl_exec($chUpd);
        curl_close($chUpd);

        salir(['ok' => true, 'init_point' => $initPoint]);
    }
}

salir(['ok' => false, 'error' => 'acción desconocida'], 400);
