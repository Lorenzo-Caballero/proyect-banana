<?php
/**
 * stock_agente.php — cuántas fichas nos quedan para repartir.
 *
 * POST (X-API-Key: BOT_API_KEY)  body {"saldo": 233911.1}
 *      -> {"ok":true, "saldo":233911.1, "umbral":50000, "aviso":false}
 *
 * POR QUÉ EXISTE
 * El 12/09/2026 la cuenta de agente se quedó SIN FICHAS y la plataforma empezó
 * a rechazar los depósitos con {"status":501}. Es una condición operativa
 * NORMAL y previsible —se acaba el stock y hay que comprarle más al proveedor—
 * pero se descubría cuando los jugadores reclamaban: el worker marcaba la
 * acción como 'hecha' y nadie se enteraba.
 *
 * Ese silencio ya se arregló (ahora un depósito fallido avisa), pero eso avisa
 * TARDE: cuando el primer jugador ya se quedó sin sus fichas. Esto avisa ANTES,
 * mientras todavía hay tiempo de reponer.
 *
 * QUIÉN LO LLAMA
 * `colector/aprobar_cargas.py`, que ya corre cada minuto con la sesión del
 * panel abierta. El saldo sale de
 *     GET {PANEL_API}/agent_admin/user/  ->  result.source_user.balance
 * `source_user` es el agente que hace la request, o sea nosotros.
 *
 * OJO CON DE DÓNDE SALE EL NÚMERO: esa misma respuesta trae `balance_sum` (la
 * suma de los saldos de los JUGADORES) y `users[].balance` (el saldo de uno
 * suelto). Ninguno de los dos es nuestro stock, y los tres son números
 * plausibles. Verificado el 13/09/2026 contra lo que muestra el panel en
 * pantalla: source_user.balance = 233.911,10 = el "Saldo" del header.
 */

declare(strict_types=1);
require __DIR__ . '/config.php';
require __DIR__ . '/db.php';
require_once __DIR__ . '/config_crm.php';
/* Opcional a propósito, como en acciones_cola.php: si falta la librería, esto
   sigue guardando la lectura y solo se pierde el aviso. */
if (is_file(__DIR__ . '/telegram_lib.php')) { require_once __DIR__ . '/telegram_lib.php'; }

header('Content-Type: application/json; charset=utf-8');
exigir_api_key();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Solo POST']);
    exit;
}

$body  = json_decode(file_get_contents('php://input'), true) ?: [];
$saldo = $body['saldo'] ?? null;

/* Un saldo que no se pudo leer NO es un saldo de cero. Si el worker manda null
   —porque el WAF le cortó la lectura, o cambió la forma de la respuesta— y esto
   lo tomara como 0, dispararía el aviso de "te quedaste sin fichas" teniendo la
   cuenta llena. Un aviso falso de este tipo es caro: la próxima vez que suene
   de verdad, nadie le va a dar bola. */
if ($saldo === null || $saldo === '' || !is_numeric($saldo)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Falta un saldo numerico']);
    exit;
}
$saldo = (float)$saldo;

try {
    cfg_crm_guardar($pdo, [
        'stock_fichas'    => (string)$saldo,
        'stock_fichas_en' => date('Y-m-d H:i:s'),
    ], 'bot');
} catch (Throwable $e) {
    error_log('stock_agente: no pude guardar la lectura: ' . $e->getMessage());
}

$umbral = 0;
try {
    $umbral = (int)(cfg_crm($pdo, 'lim_stock_aviso') ?? 0);
} catch (Throwable $e) { /* sin config: sin aviso */ }

$aviso = false;
if ($umbral > 0 && $saldo < $umbral && function_exists('tg_evento')) {
    /* Clave de dedupe por TRAMO, no por lectura: esto corre cada pocos minutos
       y el saldo baja de a poco, así que una clave con el número exacto
       mandaría un mensaje por cada ficha que se mueve. El tramo hace que
       vuelva a sonar solo cuando el saldo empeora de verdad (bajó otro 10% del
       umbral), y que deje de sonar cuando se repone. */
    $tramo = $umbral > 0 ? (int)floor($saldo / max(1, (int)($umbral / 10))) : 0;
    $aviso = tg_evento($pdo, 'salud', '🪙 Te estás quedando sin fichas', [
        'Stock ahora' => number_format($saldo, 0, ',', '.'),
        'Avisa bajo'  => number_format($umbral, 0, ',', '.'),
        'Que hacer'   => 'Comprale fichas al proveedor. Cuando se acaben, la '
                       . 'plataforma rechaza los depositos y los jugadores no '
                       . 'reciben sus cargas.',
    ], 'stock_bajo:' . $tramo);
}

echo json_encode([
    'ok'     => true,
    'saldo'  => $saldo,
    'umbral' => $umbral,
    'aviso'  => $aviso,
]);
