<?php
/**
 * hgcash_lib.php — Todo lo que habla con HG Cash, en un solo lugar.
 *
 * HG Cash (hg.cash) es la pasarela: el jugador DEPOSITA pagando un checkout
 * hosteado (transferencia a un CVU de HG, que matchea monto+DNI solo) y los
 * RETIROS se pagan con un cash-out por API a cualquier CBU/CVU. Docs:
 * https://docs.hg.cash — API v1, Bearer token, webhooks HMAC-SHA256.
 *
 * DECISIONES QUE NO SE NEGOCIAN
 *
 * 1. UN token para toda la plataforma. Lo carga el dueño desde panel.html y
 *    vive en goldpaw_control.config_plataforma. Los clientes NUNCA lo ven:
 *    toda la plata pasa por la cuenta HG del dueño, y a cada cliente se le
 *    liquida su neto segun el libro mayor (hg_transacciones).
 *
 * 2. La comision se CONGELA por transaccion. Al crear la fila se copian los
 *    porcentajes vigentes (HG_COMISION_CLIENTE_PCT / HG_COSTO_HG_PCT): si
 *    mañana cambian, lo viejo se liquida como se pacto.
 *
 * 3. Fail-open hacia el flujo legacy. Si HG esta apagado, sin token, o su
 *    API no responde, rl_crear_recarga sigue con la transferencia manual de
 *    siempre (centavos unicos + colector de mails). Una caida de la pasarela
 *    no puede dejar a los jugadores sin poder cargar.
 *
 * 4. El transporte HTTP es inyectable ($GLOBALS['HG_TRANSPORT']) para poder
 *    simular flujos completos en tests sin pegarle a hg.cash.
 */

declare(strict_types=1);

require_once __DIR__ . '/config.php';

// ============================ CONFIG =======================================

/**
 * Conexion a goldpaw_control (la base maestra). Mismo patron que
 * suscripcion.php / mp_webhook.php: el usuario de la app tiene grant en
 * todas las bases. Cacheada por proceso.
 */
function hg_control(): ?PDO
{
    // Inyectable para los tests: permite simular el flujo entero (deposito,
    // webhook, retiro) contra un SQLite en memoria, sin MySQL ni red.
    if (isset($GLOBALS['HG_CONTROL_OVERRIDE']) && $GLOBALS['HG_CONTROL_OVERRIDE'] instanceof PDO) {
        return $GLOBALS['HG_CONTROL_OVERRIDE'];
    }
    static $ctl = null, $intentado = false;
    if ($intentado) { return $ctl; }
    $intentado = true;
    try {
        $ctl = new PDO(
            'mysql:host=' . cfg('DB_HOST', 'localhost')
                . ';dbname=' . cfg('CONTROL_DB_NAME', 'goldpaw_control') . ';charset=utf8mb4',
            cfg('DB_USER'), cfg('DB_PASS'),
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 4]
        );
    } catch (Throwable $e) {
        error_log('hg: no pude conectar a goldpaw_control: ' . $e->getMessage());
        $ctl = null;
    }
    return $ctl;
}

/** Una clave de config_plataforma, con default. El cache vive en un global
 *  y no en un static para que los tests puedan resetearlo entre escenarios
 *  (en produccion da igual: un request, un proceso). */
function hg_cfg(string $clave, string $default = ''): string
{
    $cache = &$GLOBALS['__hg_cfg_cache'];
    if (!is_array($cache)) { $cache = []; }
    if (array_key_exists($clave, $cache)) { return $cache[$clave]; }
    $ctl = hg_control();
    if (!$ctl) { return $cache[$clave] = $default; }
    try {
        $st = $ctl->prepare('SELECT valor FROM config_plataforma WHERE clave = ?');
        $st->execute([$clave]);
        $v = $st->fetchColumn();
        return $cache[$clave] = (is_string($v) && $v !== '') ? $v : $default;
    } catch (Throwable $e) {
        return $cache[$clave] = $default;
    }
}

/** ¿HG esta operativo? Token cargado + interruptor prendido. */
function hg_activo(): bool
{
    return hg_cfg('HG_ACTIVO', '0') === '1' && hg_cfg('HG_API_TOKEN') !== '';
}

/** Base de la API segun el modo. "dev" es el sandbox de HG (http a proposito:
 *  asi lo publican ellos). */
function hg_base(): string
{
    return hg_cfg('HG_MODO', 'prod') === 'dev'
        ? 'http://dev.hg.cash/api/v1'
        : 'https://hg.cash/api/v1';
}

/** Porcentajes vigentes: [comision al cliente, costo de HG]. */
function hg_pcts(): array
{
    return [
        (float)hg_cfg('HG_COMISION_CLIENTE_PCT', '3.5'),
        (float)hg_cfg('HG_COSTO_HG_PCT', '2.0'),
    ];
}

/**
 * El cliente (fila de goldpaw_control.clientes) dueño de la base actual.
 * db.php ya resolvio TENANT_DB por dominio; aca solo se mapea a id.
 */
function hg_cliente_actual(): ?array
{
    $cli = &$GLOBALS['__hg_cli_cache'];
    if (array_key_exists('__hg_cli_listo', $GLOBALS)) { return $cli; }
    $GLOBALS['__hg_cli_listo'] = true;
    $db = (string)($GLOBALS['TENANT_DB'] ?? cfg('DB_NAME'));
    $ctl = hg_control();
    if (!$ctl || $db === '') { return null; }
    try {
        $st = $ctl->prepare('SELECT id, nombre, db_nombre, dominio FROM clientes WHERE db_nombre = ? LIMIT 1');
        $st->execute([$db]);
        $cli = $st->fetch(PDO::FETCH_ASSOC) ?: null;
    } catch (Throwable $e) {
        $cli = null;
    }
    return $cli;
}

// ============================ HTTP =========================================

/**
 * Una llamada a la API de HG. Devuelve [httpCode, arrayDecodificado|null].
 *
 * El transporte se puede inyectar via $GLOBALS['HG_TRANSPORT'] =
 * fn($metodo,$url,$body,$headers) => [code, jsonString] — es lo que permite
 * simular el flujo entero (deposito, webhook, retiro) en un test sin red.
 */
function hg_api(string $metodo, string $path, ?array $body = null): array
{
    $url = hg_base() . $path;
    $headers = [
        'Authorization: Bearer ' . hg_cfg('HG_API_TOKEN'),
        'Content-Type: application/json',
    ];

    if (isset($GLOBALS['HG_TRANSPORT']) && is_callable($GLOBALS['HG_TRANSPORT'])) {
        [$code, $raw] = ($GLOBALS['HG_TRANSPORT'])($metodo, $url, $body, $headers);
        return [$code, is_string($raw) ? json_decode($raw, true) : $raw];
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST  => $metodo,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_TIMEOUT        => 12,
        CURLOPT_CONNECTTIMEOUT => 6,
    ]);
    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body, JSON_UNESCAPED_UNICODE));
    }
    $raw  = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    if ($raw === false) {
        error_log('hg_api ' . $metodo . ' ' . $path . ': ' . curl_error($ch));
        curl_close($ch);
        return [0, null];
    }
    curl_close($ch);
    return [$code, json_decode((string)$raw, true)];
}

// ============================ LIBRO MAYOR ==================================

/**
 * Registra una transaccion en el libro de la plataforma, con la comision
 * congelada al porcentaje de HOY. Idempotente por hg_id (UNIQUE): si ya
 * estaba, no duplica y devuelve false.
 */
function hg_ledger_alta(string $tipo, array $cli, string $usuario, string $refTenant,
                        string $hgId, float $monto, ?string $checkoutUrl = null): bool
{
    $ctl = hg_control();
    if (!$ctl) { return false; }
    [$pctCli, $pctHg] = hg_pcts();
    $comision = round($monto * $pctCli / 100, 2);
    $costoHg  = round($monto * $pctHg / 100, 2);
    try {
        $ctl->prepare(
            "INSERT INTO hg_transacciones
               (cliente_id, db_nombre, tipo, usuario, ref_tenant, hg_id, monto,
                comision_pct, costo_hg_pct, comision, costo_hg, margen, neto,
                estado, checkout_url)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,'pendiente',?)"
        )->execute([
            (int)$cli['id'], (string)$cli['db_nombre'], $tipo, $usuario, $refTenant,
            $hgId, $monto, $pctCli, $pctHg, $comision, $costoHg,
            round($comision - $costoHg, 2), round($monto - $comision, 2),
            $checkoutUrl,
        ]);
        return true;
    } catch (PDOException $e) {
        if ($e->getCode() === '23000') { return false; }   // ya registrada
        error_log('hg_ledger_alta: ' . $e->getMessage());
        return false;
    }
}

/**
 * Cambia el estado de una fila del libro. Devuelve la fila (para saber a que
 * cliente/base pertenece) o null si el hg_id no existe.
 *
 * $soloSi limita la transicion ("acredita SOLO si estaba pendiente"): con el
 * rowCount de ese UPDATE condicional se decide si este webhook es el primero
 * o un reintento — la misma jugada que pagos.id_unico y el raspa.
 */
function hg_ledger_transicion(string $hgId, string $estado, ?string $detalle = null,
                              ?string $soloSi = null): ?array
{
    $ctl = hg_control();
    if (!$ctl) { return null; }
    try {
        $st = $ctl->prepare('SELECT * FROM hg_transacciones WHERE hg_id = ? LIMIT 1');
        $st->execute([$hgId]);
        $fila = $st->fetch(PDO::FETCH_ASSOC);
        if (!$fila) { return null; }

        $sql = "UPDATE hg_transacciones
                   SET estado = ?, detalle = ?,
                       acreditado_en = IF(? IN ('completado','pagado'), NOW(), acreditado_en)
                 WHERE hg_id = ?" . ($soloSi !== null ? " AND estado = ?" : "");
        $par = [$estado, $detalle !== null ? mb_substr($detalle, 0, 4000) : $fila['detalle'], $estado, $hgId];
        if ($soloSi !== null) { $par[] = $soloSi; }
        $upd = $ctl->prepare($sql);
        $upd->execute($par);
        $fila['transiciono'] = $upd->rowCount() === 1;
        return $fila;
    } catch (Throwable $e) {
        error_log('hg_ledger_transicion: ' . $e->getMessage());
        return null;
    }
}

// ============================ OPERACIONES ==================================

/**
 * Crea un checkout de deposito (AR). Devuelve
 *   ['ok'=>true,  'id'=>uuid, 'url'=>checkoutUrl, 'cvu'=>..., 'alias'=>..., 'titular'=>...]
 *   ['ok'=>false, 'error'=>...]
 *
 * El monto viaja como STRING decimal ("1500.00"): asi lo pide la API.
 * accountDisplay trae el CVU/alias de HG por si el jugador prefiere
 * transferir a mano en vez de abrir el link.
 */
function hg_checkout_crear(float $monto, string $referencia, array $meta = []): array
{
    $host = (string)($_SERVER['HTTP_HOST'] ?? 'ganamoscrm.online');
    [$code, $r] = hg_api('POST', '/checkouts', [
        'country'          => 'AR',
        'amount'           => number_format($monto, 2, '.', ''),
        'successUrl'       => 'https://' . $host . '/?pago=ok',
        'webhookUrl'       => 'https://ganamoscrm.online/gp-api/hg_webhook.php',
        'idempotencyKey'   => $referencia,
        'expiresInSeconds' => 45 * 60,      // mismo vencimiento que la recarga
        'metadata'         => $meta,
        'locale'           => 'es',
    ]);
    if ($code !== 201 || !is_array($r) || empty($r['id'])) {
        error_log('hg_checkout_crear: HTTP ' . $code . ' ' . json_encode($r));
        return ['ok' => false, 'error' => 'HG no pudo crear el pago (HTTP ' . $code . ')'];
    }
    $cuenta = (array)($r['accountDisplay'] ?? []);
    return [
        'ok'      => true,
        'id'      => (string)$r['id'],
        'url'     => (string)($r['checkoutUrl'] ?? ''),
        'cvu'     => (string)($cuenta['CVU'] ?? $cuenta['cvu'] ?? ''),
        'alias'   => (string)($cuenta['alias'] ?? ''),
        'titular' => (string)($cuenta['holder'] ?? $cuenta['titular'] ?? ''),
    ];
}

/**
 * Paga un retiro: cash-out a un CBU/CVU (o alias, que primero se resuelve).
 * Devuelve ['ok'=>true,'id'=>uuid,'estado'=>'PENDING'] o ['ok'=>false,...].
 *
 * externalID = "db:accion_id" — si HG recibe dos veces el mismo, contesta
 * 409 y NO paga dos veces: la idempotencia la garantiza HG, no un flag local.
 */
function hg_cashout_crear(float $monto, string $destino, string $nombre,
                          string $cuit, string $externalId, string $concepto = 'Retiro'): array
{
    $accountId = hg_cfg('HG_ACCOUNT_ID');
    if ($accountId === '') {
        return ['ok' => false, 'error' => 'Falta configurar HG_ACCOUNT_ID en el panel.'];
    }

    $destino = trim($destino);
    $esNumerico = (bool)preg_match('/^\d{22}$/', $destino);
    if (!$esNumerico) {
        // Alias: se resuelve a CVU con el lookup de HG. Si el lookup falla
        // (es una feature paga, puede no estar contratada) se corta con un
        // error claro en vez de mandar plata a ciegas.
        [$code, $r] = hg_api('POST', '/alias-lookup', ['alias' => $destino]);
        if ($code !== 200 || empty($r['cvu'])) {
            return ['ok' => false, 'codigo' => 'alias',
                    'error' => "No pude resolver el alias '$destino'. Pedile el CBU/CVU de 22 dígitos."];
        }
        $destino = (string)$r['cvu'];
        if ($nombre === '' && !empty($r['nombre'])) { $nombre = (string)$r['nombre']; }
        if ($cuit === '' && !empty($r['cuit']))     { $cuit = (string)$r['cuit']; }
    }

    $body = [
        'accountId'  => $accountId,
        'amount'     => round($monto, 2),
        'toCBU'      => $destino,
        'toName'     => $nombre !== '' ? mb_substr($nombre, 0, 255) : 'Jugador',
        'concept'    => mb_substr($concepto, 0, 500),
        'data'       => ['externalID' => $externalId],
        'webhookUrl' => 'https://ganamoscrm.online/gp-api/hg_webhook.php',
    ];
    if ($cuit !== '' && preg_match('/^\d{11}$/', $cuit)) {
        $body['toCUIT'] = (int)$cuit;
    }

    [$code, $r] = hg_api('POST', '/transactions', $body);
    if ($code === 409) {
        // Saldo insuficiente en la cuenta HG de la plataforma, o duplicado.
        $msg = (string)($r['message'] ?? $r['error'] ?? 'HG rechazó el pago (409)');
        return ['ok' => false, 'codigo' => 'rechazado', 'error' => $msg];
    }
    if ($code !== 201 || !is_array($r) || empty($r['id'])) {
        error_log('hg_cashout_crear: HTTP ' . $code . ' ' . json_encode($r));
        return ['ok' => false, 'error' => 'HG no pudo crear el pago (HTTP ' . $code . ')'];
    }
    return ['ok' => true, 'id' => (string)$r['id'], 'estado' => (string)($r['status'] ?? 'PENDING')];
}

/** Estado de un cash-out (poll de respaldo si el webhook no llego). */
function hg_cashout_estado(string $requestId): ?array
{
    [$code, $r] = hg_api('GET', '/transaction/' . rawurlencode($requestId) . '/status');
    return ($code === 200 && is_array($r)) ? $r : null;
}

/** Las cuentas del token (para elegir HG_ACCOUNT_ID desde el panel). */
function hg_cuentas(): ?array
{
    [$code, $r] = hg_api('GET', '/accounts');
    return ($code === 200 && is_array($r)) ? $r : null;
}

// ============================ WEBHOOK ======================================

/**
 * Verifica la firma HMAC-SHA256 del webhook.
 *
 * SIN secret configurado se acepta (HG solo manda la firma si hay secret) --
 * pero el webhook igual nunca confia en el payload: para acreditar consulta
 * el estado real por API, asi que un POST falso no puede inventar plata.
 */
function hg_firma_ok(string $rawBody, string $header): bool
{
    $secret = hg_cfg('HG_WEBHOOK_SECRET');
    if ($secret === '') { return true; }
    if (!preg_match('/^sha256=([0-9a-f]{64})$/', trim($header), $m)) { return false; }
    return hash_equals(hash_hmac('sha256', $rawBody, $secret), $m[1]);
}

/** SOLO PARA TESTS: limpia los caches por-proceso de la lib. */
function hg_cfg_reset_para_tests(): void
{
    unset($GLOBALS['__hg_cfg_cache'], $GLOBALS['__hg_cli_cache'], $GLOBALS['__hg_cli_listo']);
}
