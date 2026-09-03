<?php
/**
 * hg_webhook.php — HG Cash avisa aca cada vez que pasa algo con la plata.
 *
 * DOS MODOS, distinguidos por el Host al que llego el POST (nunca por el
 * payload -- HG no manda nada que diga de que cliente es):
 *
 *  - MODO CASA (de siempre): una sola URL para toda la plataforma,
 *    https://ganamoscrm.online/gp-api/hg_webhook.php, con el token
 *    COMPARTIDO del dueño. El ruteo al cliente correcto sale del libro
 *    mayor (hg_transacciones.hg_id -> db_nombre), que escribimos NOSOTROS
 *    al crear la operacion. Hoy INACTIVO (no se ofrece desde el CRM), pero
 *    sigue funcionando si algun dia se reactiva a mano.
 *
 *  - MODO POR-CLIENTE (nuevo): cada cliente con HG Cash propio configura,
 *    en SU cuenta de HG, la URL de SU PROPIO dominio (o /slug/) como
 *    webhook. hgw_resolver_tenant_por_host() identifica el tenant por ese
 *    Host -- exactamente como hace db.php para cualquier otro endpoint --
 *    asi que no hace falta ningun libro mayor: la firma se verifica con EL
 *    secret de ESE cliente, y la verificacion de estado (regla de oro #1,
 *    abajo) usa el token de ESE cliente.
 *
 * REGLAS DE ORO (aplican a los dos modos)
 *
 * 1. NUNCA se acredita por lo que dice el POST. El payload solo dice "mira
 *    el checkout X": el estado real se consulta a la API de HG con el token
 *    que corresponda (de la plataforma o del cliente, segun el modo). Un
 *    POST falsificado, como mucho, nos hace hacer un GET.
 * 2. Idempotente: en modo casa, dos capas (transicion condicional del libro
 *    + UPDATE condicional en la tabla del cliente); en modo por-cliente, el
 *    UPDATE condicional solo ya alcanza (no hay libro que routear). HG
 *    reintenta hasta 4 veces; acreditar 2 veces es regalar.
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

/**
 * Resuelve el tenant por el DOMINIO al que llegó este request -- mismo
 * criterio y misma query que db.php, pero sin morir si no hay match (acá un
 * Host desconocido no es un error, es "no es un webhook por-cliente, seguí
 * con el modo casa"). Devuelve la fila de clientes (con hg_propio_*) o null.
 *
 * Esto es lo que hace posible que CADA cliente tenga su propia URL de
 * webhook sin ningun libro mayor de por medio: el cliente carga en SU
 * cuenta de HG Cash la URL de SU dominio (o /slug/), y ese POST llega acá
 * ya identificado por Host -- ni hg.cash ni el payload dicen de quien es.
 */
function hgw_resolver_tenant_por_host(): ?array
{
    $host = isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : (string)($_SERVER['SERVER_NAME'] ?? '');
    $host = strtolower((string)preg_replace('/:\d+$/', '', $host));
    $slug = trim((string)($_SERVER['HTTP_X_TENANT_SLUG'] ?? ''));
    if ($host === '') { return null; }

    $ctl = hg_control();
    if (!$ctl) { return null; }
    try {
        if ($slug !== '') {
            $st = $ctl->prepare(
                "SELECT id, db_nombre, dominio, slug, hg_propio_activo, hg_propio_token,
                        hg_propio_account_id, hg_propio_webhook_secret, hg_propio_modo
                   FROM clientes WHERE dominio = ? AND slug = ? AND path_tenant = 1 AND estado = 'activo' LIMIT 1"
            );
            $st->execute([$host, $slug]);
        } else {
            $st = $ctl->prepare(
                "SELECT id, db_nombre, dominio, slug, hg_propio_activo, hg_propio_token,
                        hg_propio_account_id, hg_propio_webhook_secret, hg_propio_modo
                   FROM clientes WHERE dominio = ? AND path_tenant = 0 AND estado = 'activo' LIMIT 1"
            );
            $st->execute([$host]);
        }
        return $st->fetch(PDO::FETCH_ASSOC) ?: null;
    } catch (Throwable $e) {
        error_log('hgw_resolver_tenant_por_host: ' . $e->getMessage());
        return null;
    }
}

$raw = (string)file_get_contents('php://input');

// Modo por-cliente: SOLO si el Host de este request resolvio a un cliente
// con HG Cash propio prendido. Cualquier otro caso (Host desconocido,
// cliente sin hg_propio_activo) sigue el modo "casa" de siempre, sin tocar
// nada de lo que ya funcionaba.
$tenantPropio = hgw_resolver_tenant_por_host();
$esModoPropio = $tenantPropio !== null
    && (int)($tenantPropio['hg_propio_activo'] ?? 0) === 1
    && trim((string)($tenantPropio['hg_propio_token'] ?? '')) !== '';

if ($esModoPropio) {
    $secretPropio = (string)($tenantPropio['hg_propio_webhook_secret'] ?? '');
    $firmaOk = $secretPropio === ''
        ? true   // mismo criterio que hg_firma_ok(): sin secret configurado, se acepta (nunca se confia en el payload igual)
        : (preg_match('/^sha256=([0-9a-f]{64})$/', trim((string)($_SERVER['HTTP_X_HG_WEBHOOK_SIGNATURE'] ?? '')), $m)
           && hash_equals(hash_hmac('sha256', $raw, $secretPropio), $m[1]));
    if (!$firmaOk) { hgw_salir(['ok' => false], 401); }
} elseif (!hg_firma_ok($raw, (string)($_SERVER['HTTP_X_HG_WEBHOOK_SIGNATURE'] ?? ''))) {
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

/**
 * Idempotencia capa 2 + acreditacion + aviso, compartida entre el modo
 * "casa" y el modo por-cliente -- lo unico que cambia entre los dos es COMO
 * se llego hasta aca (por libro mayor, o por Host); a partir de este punto
 * es exactamente la misma operacion sobre la base del tenant. Termina
 * siempre en hgw_salir() (nunca vuelve).
 */
function hgw_acreditar_checkout(PDO $pdo, string $checkoutId, string $dbNombre): void
{
    $st = $pdo->prepare("SELECT * FROM recargas WHERE hg_checkout_id = ? LIMIT 1");
    $st->execute([$checkoutId]);
    $recarga = $st->fetch(PDO::FETCH_ASSOC);
    if (!$recarga) {
        error_log("hg_webhook: checkout $checkoutId sin recarga en $dbNombre");
        hgw_salir(['ok' => true, 'error' => 'recarga no encontrada']);
    }
    // Las libs ANTES de abrir la transaccion: nada de I/O de disco con locks
    // tomados. Aviso, bono pendiente y bono de bienvenida las necesitan.
    foreach (['/recargas_lib.php', '/notificaciones_lib.php', '/crm_lib.php'] as $lib) {
        if (is_file(__DIR__ . $lib)) { require_once __DIR__ . $lib; }
    }

    /* TODO el tramo que mueve plata va en UNA transaccion. Antes eran
       statements sueltos en autocommit y eso tenia dos problemas reales:
       (a) el claim podia quedar commiteado y el coins+= fallar despues --
       recarga "acreditada" sin fichas; (b) el candado de una-sola-vez del
       bono de bienvenida (SELECT ... FOR UPDATE en el helper) no retiene
       ningun lock en autocommit, asi que dos webhooks del mismo jugador a la
       vez (HG reintenta hasta 4 veces) podian pagar el bono dos veces. */
    try {
        $pdo->beginTransaction();

        /* Serializa POR JUGADOR: dos webhooks del mismo usuario entran de a
           uno, asi el conteo de "primera" y el candado del bono ven siempre
           lo que el anterior ya commiteo. Mismo rol que cumple el UPDATE de
           usuarios dentro de la transaccion del matcher. */
        $lk = $pdo->prepare("SELECT id FROM usuarios WHERE username = ? FOR UPDATE");
        $lk->execute([(string)$recarga['usuario']]);

        /* "Primera carga" se decide ANTES del claim: el claim mismo vuelve
           acreditada ESTA recarga y el conteo ya no diria "cero previas".
           Mismo calculo que rl_acreditar(); se persiste en la fila para que
           Publicidad haga SUM(es_primera) sin recalcular (las recargas HG
           quedaban en NULL y no contaban como primera carga en el embudo). */
        $esPrimera = null;
        try {
            $st = $pdo->prepare(
                "SELECT COUNT(*) FROM recargas WHERE usuario = ? AND estado = 'acreditada'"
            );
            $st->execute([(string)$recarga['usuario']]);
            $esPrimera = ((int)$st->fetchColumn() === 0) ? 1 : 0;
        } catch (Throwable $e) {
            error_log('hg_webhook: no pude calcular es_primera: ' . $e->getMessage());
        }

        try {
            $claim = $pdo->prepare(
                "UPDATE recargas SET estado='acreditada', acreditada_en=NOW(), mensaje='HG Cash',
                        es_primera=?
                  WHERE id = ? AND estado = 'pendiente'"
            );
            $claim->execute([$esPrimera, (int)$recarga['id']]);
        } catch (PDOException $e) {
            // SOLO si falta la columna (migracion 44: SQLSTATE 42S22 / 1054)
            // se reintenta sin ella. Cualquier otro error (deadlock, lock
            // timeout) NO es "falta la columna": va al catch grande, que
            // deshace todo y le pide a HG que reintente.
            if ((string)($e->errorInfo[0] ?? '') !== '42S22'
                && (int)($e->errorInfo[1] ?? 0) !== 1054) {
                throw $e;
            }
            $claim = $pdo->prepare(
                "UPDATE recargas SET estado='acreditada', acreditada_en=NOW(), mensaje='HG Cash'
                  WHERE id = ? AND estado = 'pendiente'"
            );
            $claim->execute([(int)$recarga['id']]);
        }
        if ($claim->rowCount() !== 1) {
            $pdo->rollBack();
            hgw_salir(['ok' => true, 'ya_procesado' => true]);
        }

        // Acreditar las fichas — el paso por el que existe todo esto.
        $pdo->prepare("UPDATE usuarios SET coins = coins + ? WHERE username = ?")
            ->execute([(int)$recarga['coins'], (string)$recarga['usuario']]);

        // Aviso + bonos, best-effort: nada de esto puede hacer que la
        // acreditacion parezca fallida...
        try {
            if (function_exists('rl_notificar_acreditada')) {
                rl_notificar_acreditada($pdo, $recarga);
            }
            if (function_exists('crmnotif_bono_aplicar_en_recarga')) {
                crmnotif_bono_aplicar_en_recarga($pdo, (string)$recarga['usuario'],
                    (int)$recarga['id'], (int)$recarga['coins']);
            }
            /* Bono de bienvenida: una recarga HG ES camino B (la creo
               rl_crear_recarga), pero se acredita por aca sin pasar por
               rl_acreditar() -- y el bono no salia NUNCA para un jugador de
               landing que pagaba por HG Cash. Mismo gate de "primera" que
               alla; el candado de una-sola-vez esta en el helper. */
            if ($esPrimera === 1 && function_exists('rl_bono_bienvenida_aplicar')) {
                rl_bono_bienvenida_aplicar($pdo, (string)$recarga['usuario'], (int)$recarga['coins']);
            }
            // Referidos: tercera puerta de acreditacion, mismo gate de
            // "primera" que el bono de arriba. Sin esto, el amigo que paga su
            // primera carga por HG Cash no le daba el bono a quien lo trajo.
            if ($esPrimera === 1 && function_exists('ref_pagar_por_primera_carga')) {
                ref_pagar_por_primera_carga($pdo, (string)$recarga['usuario']);
            }
        } catch (Throwable $e) {
            // ...SALVO un deadlock: ese ya revirtio la transaccion entera del
            // lado del server (los helpers lo relanzan a proposito) y aca no
            // queda nada que commitear -- sigue al catch grande.
            if ($e instanceof PDOException
                && ((string)($e->errorInfo[0] ?? '') === '40001'
                    || (int)($e->errorInfo[1] ?? 0) === 1213)) {
                throw $e;
            }
            error_log('hg_webhook aviso: ' . $e->getMessage());
        }

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            try { $pdo->rollBack(); } catch (Throwable $e2) {}
        }
        error_log('hg_webhook acreditar (' . $checkoutId . '): ' . $e->getMessage());
        /* 500 A PROPOSITO, excepcion puntual a la regla de oro #3: esto es un
           fallo transitorio (deadlock, base caida un instante) y el reintento
           de HG es exactamente la recuperacion correcta -- el claim
           condicional lo mantiene idempotente. La regla #3 aplica a errores
           que van a volver a fallar igual; este no. */
        hgw_salir(['ok' => false, 'error' => 'transitorio'], 500);
    }

    hgw_salir(['ok' => true, 'acreditada' => (int)$recarga['coins']]);
}

// ============================ DEPOSITOS ====================================
if ($topic === 'CHECKOUT') {
    // El id puede venir en distintos lugares segun el evento; se prueban en
    // orden y despues se valida contra NUESTRO libro, que es el que manda.
    $checkoutId = (string)($ev['checkoutId'] ?? $ev['checkout']['id'] ?? $ev['id'] ?? '');
    if ($checkoutId === '') { hgw_salir(['ok' => true, 'ignorado' => 'sin id']); }

    if ($eventType === 'checkout.completed') {
        if ($esModoPropio) {
            // Modo por-cliente: no hay libro mayor que resolver -- el Host ya
            // nos dijo de que tenant es (hgw_resolver_tenant_por_host()), asi
            // que abrimos ESA base directo. VERIFICAR CONTRA LA API con el
            // token del CLIENTE (regla de oro #1: nunca confiar en el payload).
            $GLOBALS['TENANT_DB'] = (string)$tenantPropio['db_nombre'];
            [$code, $chk] = hg_propio_api('GET', '/checkouts/' . rawurlencode($checkoutId));
            $estadoReal = strtolower((string)($chk['status'] ?? ''));
            if ($code !== 200 || $estadoReal !== 'completed') {
                error_log("hg_webhook (propio {$tenantPropio['db_nombre']}): checkout $checkoutId "
                    . "dice completed pero la API dice HTTP $code/'$estadoReal' — no se acredita");
                hgw_salir(['ok' => true, 'verificacion' => 'fallo']);
            }
            $pdo = hgw_tenant((string)$tenantPropio['db_nombre']);
            if (!$pdo) { hgw_salir(['ok' => true, 'error' => 'tenant caido, acreditar a mano']); }
            hgw_acreditar_checkout($pdo, $checkoutId, (string)$tenantPropio['db_nombre']);
        }

        // Modo "casa" (de siempre): el ruteo sale del libro mayor de plataforma.
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
        hgw_acreditar_checkout($pdo, $checkoutId, (string)$fila['db_nombre']);
    }

    if (in_array($eventType, ['checkout.rejected', 'checkout.expired', 'checkout.cancelled'], true)) {
        if ($esModoPropio) {
            // Mismo efecto que el modo casa, sin libro mayor: directo contra
            // la base del tenant que ya identificamos por Host. El UPDATE
            // condicional (estado='pendiente') es idempotencia de sobra --
            // un reintento de HG no la vuelve a tocar.
            $pdo = hgw_tenant((string)$tenantPropio['db_nombre']);
            if ($pdo) {
                $pdo->prepare(
                    "UPDATE recargas SET estado='vencida', mensaje=?
                      WHERE hg_checkout_id = ? AND estado='pendiente'"
                )->execute(['HG: ' . $eventType, $checkoutId]);
            }
            hgw_salir(['ok' => true]);
        }
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
