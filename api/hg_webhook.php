<?php
/**
 * hg_webhook.php — HG Cash avisa aca cada vez que pasa algo con la plata.
 *
 * UNA URL para TODA la plataforma (todos los clientes cobran con el mismo
 * token): https://ganamoscrm.online/gp-api/hg_webhook.php. El ruteo al
 * cliente correcto NO sale del payload — sale del libro mayor
 * (hg_transacciones.hg_id -> db_nombre), que escribimos NOSOTROS al crear
 * la operacion. Un webhook con un id que no registramos se ignora.
 *
 * REGLAS DE ORO
 *
 * 1. NUNCA se acredita por lo que dice el POST. El payload solo dice "mira
 *    el checkout X": el estado real se consulta a la API de HG con nuestro
 *    token. Un POST falsificado, como mucho, nos hace hacer un GET.
 * 2. Idempotente en DOS capas: la transicion condicional del libro
 *    (estado='pendiente' + rowCount) y el UPDATE condicional en la tabla
 *    del cliente. HG reintenta hasta 4 veces; acreditar 2 veces es regalar.
 * 3. Se responde 200 rapido incluso ante errores nuestros: un 500 hace que
 *    HG reintente algo que va a volver a fallar igual. Lo irrecuperable se
 *    loguea y se resuelve a mano.
 *
 * Corre en el VPS (nginx /gp-api/), no en Hostinger: el WAF de Hostinger
 * corta POSTs server-to-server.
 */

declare(strict_types=1);

require __DIR__ . '/config.php';
require __DIR__ . '/hgcash_lib.php';

header('Content-Type: application/json; charset=utf-8');

function hgw_salir(array $d, int $code = 200): void
{
    http_response_code($code);
    echo json_encode($d, JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    hgw_salir(['ok' => false, 'error' => 'POST only'], 405);
}

$raw = (string)file_get_contents('php://input');
if (!hg_firma_ok($raw, (string)($_SERVER['HTTP_X_HG_WEBHOOK_SIGNATURE'] ?? ''))) {
    // Firma invalida = alguien que NO es HG. 401 y sin pistas.
    hgw_salir(['ok' => false], 401);
}

$ev = json_decode($raw, true);
if (!is_array($ev)) { hgw_salir(['ok' => true, 'ignorado' => 'sin json']); }

$topic     = strtoupper((string)($ev['topic'] ?? ''));
$eventType = (string)($ev['eventType'] ?? '');

/** Abre la base del CLIENTE dueño de esta transaccion. */
function hgw_tenant(string $dbNombre): ?PDO
{
    try {
        $pdo = new PDO(
            'mysql:host=' . cfg('DB_HOST', 'localhost') . ';dbname=' . $dbNombre . ';charset=utf8mb4',
            cfg('DB_USER'), cfg('DB_PASS'),
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 5]
        );
        // Las libs del tenant (notif, bonos) resuelven la base por esta global.
        $GLOBALS['TENANT_DB'] = $dbNombre;
        return $pdo;
    } catch (Throwable $e) {
        error_log("hg_webhook: no pude abrir $dbNombre: " . $e->getMessage());
        return null;
    }
}

// ============================ DEPOSITOS ====================================
if ($topic === 'CHECKOUT') {
    // El id puede venir en distintos lugares segun el evento; se prueban en
    // orden y despues se valida contra NUESTRO libro, que es el que manda.
    $checkoutId = (string)($ev['checkoutId'] ?? $ev['checkout']['id'] ?? $ev['id'] ?? '');
    if ($checkoutId === '') { hgw_salir(['ok' => true, 'ignorado' => 'sin id']); }

    if ($eventType === 'checkout.completed') {
        // VERIFICAR CONTRA LA API, no contra el payload (regla de oro #1).
        [$code, $chk] = hg_api('GET', '/checkouts/' . rawurlencode($checkoutId));
        $estadoReal = strtolower((string)($chk['status'] ?? ''));
        if ($code !== 200 || $estadoReal !== 'completed') {
            error_log("hg_webhook: checkout $checkoutId dice completed pero la API dice "
                . "HTTP $code/'$estadoReal' — no se acredita");
            hgw_salir(['ok' => true, 'verificacion' => 'fallo']);
        }

        // Transicion del libro: SOLO si estaba pendiente (idempotencia capa 1).
        $fila = hg_ledger_transicion($checkoutId, 'completado', $raw, 'pendiente');
        if (!$fila) { hgw_salir(['ok' => true, 'ignorado' => 'no es nuestro']); }
        if (empty($fila['transiciono'])) { hgw_salir(['ok' => true, 'ya_procesado' => true]); }

        $pdo = hgw_tenant((string)$fila['db_nombre']);
        if (!$pdo) { hgw_salir(['ok' => true, 'error' => 'tenant caido, acreditar a mano']); }

        // Idempotencia capa 2: reclamar la recarga pendiente.
        $st = $pdo->prepare("SELECT * FROM recargas WHERE hg_checkout_id = ? LIMIT 1");
        $st->execute([$checkoutId]);
        $recarga = $st->fetch(PDO::FETCH_ASSOC);
        if (!$recarga) {
            error_log("hg_webhook: checkout $checkoutId sin recarga en {$fila['db_nombre']}");
            hgw_salir(['ok' => true, 'error' => 'recarga no encontrada']);
        }
        $claim = $pdo->prepare(
            "UPDATE recargas SET estado='acreditada', acreditada_en=NOW(), mensaje='HG Cash'
              WHERE id = ? AND estado = 'pendiente'"
        );
        $claim->execute([(int)$recarga['id']]);
        if ($claim->rowCount() !== 1) { hgw_salir(['ok' => true, 'ya_procesado' => true]); }

        // Acreditar las fichas — el paso por el que existe todo esto.
        $pdo->prepare("UPDATE usuarios SET coins = coins + ? WHERE username = ?")
            ->execute([(int)$recarga['coins'], (string)$recarga['usuario']]);

        // Aviso + bono pendiente, best-effort: nada de esto puede hacer que
        // una acreditacion ya hecha parezca fallida.
        foreach (['/recargas_lib.php', '/notificaciones_lib.php', '/crm_lib.php'] as $lib) {
            if (is_file(__DIR__ . $lib)) { require_once __DIR__ . $lib; }
        }
        try {
            if (function_exists('rl_notificar_acreditada')) {
                rl_notificar_acreditada($pdo, $recarga);
            }
            if (function_exists('crmnotif_bono_aplicar_en_recarga')) {
                crmnotif_bono_aplicar_en_recarga($pdo, (string)$recarga['usuario'],
                    (int)$recarga['id'], (int)$recarga['coins']);
            }
        } catch (Throwable $e) {
            error_log('hg_webhook aviso: ' . $e->getMessage());
        }

        hgw_salir(['ok' => true, 'acreditada' => (int)$recarga['coins']]);
    }

    if (in_array($eventType, ['checkout.rejected', 'checkout.expired', 'checkout.cancelled'], true)) {
        $fila = hg_ledger_transicion($checkoutId, 'caido', $raw, 'pendiente');
        if ($fila && !empty($fila['transiciono'])) {
            $pdo = hgw_tenant((string)$fila['db_nombre']);
            if ($pdo) {
                // La recarga vuelve al estado que el resto del sistema ya
                // entiende ('vencida'): asi el jugador puede crear otra.
                $pdo->prepare(
                    "UPDATE recargas SET estado='vencida', mensaje=?
                      WHERE hg_checkout_id = ? AND estado='pendiente'"
                )->execute(['HG: ' . $eventType, $checkoutId]);
            }
        }
        hgw_salir(['ok' => true]);
    }

    // awaiting_manual_review y demas: se anota el estado y se espera.
    hg_ledger_transicion($checkoutId, 'revision', $raw, 'pendiente');
    hgw_salir(['ok' => true]);
}

// ============================ RETIROS ======================================
if ($topic === 'TRANSACTION_REQUEST') {
    $reqId = (string)($ev['requestId'] ?? $ev['request']['id'] ?? $ev['id'] ?? '');
    if ($reqId === '') { hgw_salir(['ok' => true, 'ignorado' => 'sin id']); }

    // Regla de oro #1 tambien aca: el estado real sale de la API.
    $estado = hg_cashout_estado($reqId);
    $status = strtoupper((string)($estado['status'] ?? ''));
    if ($status === '') { hgw_salir(['ok' => true, 'verificacion' => 'fallo']); }

    $terminales = ['DONE' => 'pagado', 'ERROR' => 'error', 'CANCELLED' => 'cancelado'];
    if (!isset($terminales[$status])) { hgw_salir(['ok' => true, 'intermedio' => $status]); }

    $fila = hg_ledger_transicion($reqId, $terminales[$status], $raw, 'pendiente');
    if (!$fila) { hgw_salir(['ok' => true, 'ignorado' => 'no es nuestro']); }
    if (empty($fila['transiciono'])) { hgw_salir(['ok' => true, 'ya_procesado' => true]); }

    $pdo = hgw_tenant((string)$fila['db_nombre']);
    if (!$pdo) { hgw_salir(['ok' => true, 'error' => 'tenant caido']); }

    if ($status === 'DONE') {
        $pdo->prepare("UPDATE acciones_saldo SET hg_estado='DONE' WHERE hg_request_id = ?")
            ->execute([$reqId]);
        // Avisarle al jugador que la plata YA salio.
        foreach (['/notificaciones_lib.php'] as $lib) {
            if (is_file(__DIR__ . $lib)) { require_once __DIR__ . $lib; }
        }
        try {
            if (function_exists('notif_crear') && !empty($fila['usuario'])) {
                notif_crear($pdo, (string)$fila['usuario'], 'Retiro pagado',
                    'Te transferimos $' . number_format((float)$fila['monto'], 2, ',', '.')
                        . '. Ya está en camino a tu cuenta.',
                    'recarga', null, 'hgcash');
            }
        } catch (Throwable $e) { error_log('hg_webhook notif retiro: ' . $e->getMessage()); }
    } else {
        // ERROR/CANCELLED: el retiro vuelve a la vista del agente como
        // 'revisar' con el motivo — NUNCA se reintenta solo (es plata).
        $motivo = (string)($estado['errorCode'] ?? $status);
        $pdo->prepare(
            "UPDATE acciones_saldo SET hg_estado=?, estado='revisar', mensaje=CONCAT('HG: ', ?)
              WHERE hg_request_id = ?"
        )->execute([$status, $motivo, $reqId]);
    }
    hgw_salir(['ok' => true, 'estado' => $status]);
}

// Otros topics (TRANSACTION, CLAIM): se acusa recibo y listo. Registrarlos
// seria guardar el ruido de toda la cuenta de la plataforma.
hgw_salir(['ok' => true, 'ignorado' => $topic]);
