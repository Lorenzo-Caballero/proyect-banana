<?php
/**
 * crm_creditos.php — El cajero ve sus créditos y los carga con USDT.
 *
 * GET  ?accion=estado    -> { ok, saldo, pct, umbral, estado, wallet, red,
 *                             cotizacion, consumido_30d, cargas_30d }
 * GET  ?accion=consumos  -> { ok, items:[...] }   en qué se fue el saldo
 * GET  ?accion=recargas  -> { ok, items:[...] }   sus recargas de USDT
 * POST { accion:"cargar", txid }  (solo admin)  -> verifica y acredita
 *
 * ============================================================================
 * EL CLIENTE NO ELIGE CUÁNTO SE LE ACREDITA, y eso no es desconfianza: es lo
 * único que hace que el circuito pueda ser automático. Manda un hash; el monto
 * sale de la blockchain y la cotización de lo que fijó el dueño en su panel.
 * Si el monto lo declarara él, esto sería un formulario para regalarse
 * créditos.
 *
 * Solo admin, como las credenciales del panel: un operador cualquiera del CRM
 * no tiene por qué mover la plata que el cajero le paga a la plataforma.
 */

declare(strict_types=1);
require __DIR__ . '/config.php';
require __DIR__ . '/db.php';
require __DIR__ . '/crm_auth.php';
require_once __DIR__ . '/creditos_lib.php';
require_once __DIR__ . '/usdt_lib.php';

header('Content-Type: application/json; charset=utf-8');

/* ===========================================================================
 * `false` = NO SE CHEQUEA EL SALDO PARA ENTRAR ACÁ, y es lo único que impide
 * que esta pantalla sea inútil justo cuando hace falta.
 *
 * El gate de crm_auth.php corta con 402 a todo el CRM de un cliente sin
 * créditos. Si esta pantalla también quedara detrás de ese corte, el cliente
 * se quedaría sin saldo, se le bloquearía el CRM, y la ÚNICA pantalla que lo
 * destraba estaría bloqueada igual: sin salida, y teniendo que escribirnos
 * para algo que el sistema existe para resolver solo.
 *
 * Es la misma excepción que ya hace api/suscripcion.php, y por la misma
 * razón -- su comentario lo dice textual: "un cliente sin saldo tiene que
 * poder seguir entrando ahí para pagar y destrabarse".
 *
 * No afloja nada más: exigir_operador() igual pide sesión válida y, en POST,
 * el token CSRF. El rol admin se chequea abajo a mano porque exigir_admin()
 * vuelve a pasar por el gate del saldo.
 * =========================================================================== */
$operador = exigir_operador(false);
if ($_SERVER['REQUEST_METHOD'] === 'POST' && operador_rol() !== 'admin') {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Necesitás ser admin para esto']);
    exit;
}

function cr_salir($data, int $code = 200): void
{
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $ctl = new PDO(
        'mysql:host=' . cfg('DB_HOST', 'localhost')
            . ';dbname=' . cfg('CONTROL_DB_NAME', 'goldpaw_control') . ';charset=utf8mb4',
        cfg('DB_USER'), cfg('DB_PASS'),
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );
} catch (Throwable $e) {
    error_log('crm_creditos: sin control: ' . $e->getMessage());
    cr_salir(['ok' => false, 'error' => 'No se pudo conectar a la base de control'], 500);
}

$cliente = cred_cliente($ctl, (string)($GLOBALS['TENANT_DB'] ?? ''));
if (!$cliente) { cr_salir(['ok' => false, 'error' => 'cliente no resuelto'], 500); }
$cid = (int)$cliente['id'];

/** Config de plataforma con default. */
function cr_cfg(PDO $ctl, string $clave, string $def = ''): string
{
    try {
        $st = $ctl->prepare('SELECT valor FROM config_plataforma WHERE clave = ?');
        $st->execute([$clave]);
        $v = $st->fetchColumn();
        return (is_string($v) && $v !== '') ? $v : $def;
    } catch (Throwable $e) { return $def; }
}

$metodo = $_SERVER['REQUEST_METHOD'];
$accion = $metodo === 'GET'
    ? (string)($_GET['accion'] ?? 'estado')
    : (string)((json_decode((string)file_get_contents('php://input'), true)['accion'] ?? ''));

// ------------------------------------------------------------------ estado
if ($metodo === 'GET' && $accion === 'estado') {
    $cons = ['gastado' => 0.0, 'cargas' => 0];
    try {
        $q = $ctl->prepare(
            'SELECT COALESCE(SUM(comision_ars),0) AS gastado, COUNT(*) AS cargas
               FROM consumos_plataforma
              WHERE cliente_id = ? AND cobrado_en >= NOW() - INTERVAL 30 DAY'
        );
        $q->execute([$cid]);
        $cons = $q->fetch() ?: $cons;
    } catch (Throwable $e) { /* sin migración 11 todavía */ }

    cr_salir([
        'ok'            => true,
        'modelo'        => (string)$cliente['cobro_modelo'],
        'saldo'         => (float)$cliente['creditos_ars'],
        'pct'           => cred_pct($cliente),
        'umbral'        => (float)$cliente['aviso_umbral_ars'],
        'estado'        => (string)$cliente['suscripcion_estado'],
        'wallet'        => cr_cfg($ctl, 'usdt_wallet'),
        'red'           => cr_cfg($ctl, 'usdt_red', 'TRC20'),
        'cotizacion'    => (float)cr_cfg($ctl, 'usdt_cotizacion_ars', '0'),
        'gastado_30d'   => (float)($cons['gastado'] ?? 0),
        'cargas_30d'    => (int)($cons['cargas'] ?? 0),
    ]);
}

// --------------------------------------------------------------- consumos
if ($metodo === 'GET' && $accion === 'consumos') {
    try {
        $q = $ctl->prepare(
            'SELECT via, referencia, usuario, monto_carga, comision_pct, comision_ars, cuando
               FROM consumos_plataforma WHERE cliente_id = ?
              ORDER BY cuando DESC, id DESC LIMIT 200'
        );
        $q->execute([$cid]);
        cr_salir(['ok' => true, 'items' => $q->fetchAll()]);
    } catch (Throwable $e) { cr_salir(['ok' => true, 'items' => []]); }
}

// --------------------------------------------------------------- recargas
if ($metodo === 'GET' && $accion === 'recargas') {
    try {
        $q = $ctl->prepare(
            'SELECT txid, red, monto_usdt, cotizacion, monto_ars, estado, motivo,
                    confirmaciones, creado, acreditado_en
               FROM recargas_usdt WHERE cliente_id = ? ORDER BY id DESC LIMIT 100'
        );
        $q->execute([$cid]);
        cr_salir(['ok' => true, 'items' => $q->fetchAll()]);
    } catch (Throwable $e) { cr_salir(['ok' => true, 'items' => []]); }
}

// ----------------------------------------------------------------- cargar
if ($metodo === 'POST' && $accion === 'cargar') {
    $body = json_decode((string)file_get_contents('php://input'), true) ?: [];
    $txid = strtolower(trim((string)($body['txid'] ?? '')));

    if (!usdt_txid_valido($txid)) {
        cr_salir(['ok' => false, 'error' => 'Ese no parece el hash de una transacción. '
                . 'Copialo completo desde tu billetera: son 64 caracteres.'], 400);
    }

    /* Un cliente que NO está en el modelo por transacción no tiene dónde usar
       estos créditos: su facturación la lleva el cron de suscripción. Dejarlo
       cargar sería cobrarle por algo que no consume. */
    if ((string)$cliente['cobro_modelo'] !== 'transaccion') {
        cr_salir(['ok' => false, 'error' => 'Tu plan no se paga con créditos. Escribinos.'], 409);
    }

    $wallet = cr_cfg($ctl, 'usdt_wallet');
    $cotiz  = (float)cr_cfg($ctl, 'usdt_cotizacion_ars', '0');
    $minCf  = (int)cr_cfg($ctl, 'usdt_min_confirmaciones', (string)USDT_CONFIRMACIONES_MIN);

    if ($wallet === '' || $cotiz <= 0) {
        cr_salir(['ok' => false, 'error' => 'La carga por USDT todavía no está habilitada. '
                . 'Escribinos y la activamos.'], 409);
    }

    /* SE RESERVA EL HASH ANTES DE IR A LA RED, y ese orden es el que importa.
       Consultar la cadena tarda segundos; dos clicks del mismo botón, o dos
       clientes con el mismo hash, entrarían los dos a verificar y los dos
       acreditarían. El UNIQUE de txid es GLOBAL: acá se convierte en "el
       primero que llega se lo queda", y el segundo recibe un error claro. */
    try {
        $ctl->prepare(
            'INSERT INTO recargas_usdt (cliente_id, txid, red, estado) VALUES (?,?,?,?)'
        )->execute([$cid, $txid, cr_cfg($ctl, 'usdt_red', 'TRC20'), 'pendiente']);
    } catch (Throwable $e) {
        $q = $ctl->prepare('SELECT cliente_id, estado, monto_ars FROM recargas_usdt WHERE txid = ?');
        $q->execute([$txid]);
        $ya = $q->fetch();
        if ($ya && (int)$ya['cliente_id'] === $cid) {
            cr_salir(['ok' => false, 'error' => 'Ese comprobante ya lo cargaste'
                    . ($ya['estado'] === 'acreditada' ? ' y está acreditado.' : '. Está ' . $ya['estado'] . '.')], 409);
        }
        cr_salir(['ok' => false, 'error' => 'Ese hash ya fue usado. Si creés que es un error, escribinos.'], 409);
    }

    $v = usdt_verificar($txid, $wallet, $minCf);

    if ($v['estado'] !== 'ok') {
        /* PENDIENTE NO QUEMA EL HASH: si solo falta que la red confirme, la
           fila queda y el cron la retoma sola. Rechazada y revisión también
           quedan guardadas, para que se pueda ver qué pasó. */
        $ctl->prepare(
            'UPDATE recargas_usdt SET estado = ?, motivo = ?, monto_usdt = ?, destino = ?,
                    confirmaciones = ?, raw = ? WHERE txid = ?'
        )->execute([$v['estado'], mb_substr($v['motivo'], 0, 240), $v['monto'] ?: null,
                    $v['destino'] ?: null, $v['confirmaciones'], $v['raw'], $txid]);

        $msg = $v['estado'] === 'pendiente'
            ? 'Encontramos tu transferencia, pero la red todavía la está confirmando. '
              . 'Se acredita sola en unos minutos, no hace falta que hagas nada.'
            : $v['motivo'];
        cr_salir(['ok' => false, 'estado' => $v['estado'], 'error' => $msg], 200);
    }

    $ars = round($v['monto'] * $cotiz, 2);
    $ctl->prepare(
        "UPDATE recargas_usdt
            SET estado = 'acreditada', monto_usdt = ?, cotizacion = ?, monto_ars = ?,
                destino = ?, confirmaciones = ?, raw = ?, acreditado_en = NOW(), motivo = NULL
          WHERE txid = ?"
    )->execute([$v['monto'], $cotiz, $ars, $v['destino'], $v['confirmaciones'], $v['raw'], $txid]);

    /* `$operador` es un STRING: exigir_operador() devuelve el nombre, no un
       array. Tratarlo como array dejaba la primera letra del nombre como
       autor del movimiento. */
    $saldo = cred_acreditar($ctl, $cid, $ars,
        'recarga USDT ' . rtrim(rtrim(number_format($v['monto'], 6, '.', ''), '0'), '.')
        . ' a $' . number_format($cotiz, 2, ',', '.') . ' (tx ' . substr($txid, 0, 12) . '…)',
        $operador !== '' ? $operador : 'cliente');

    /* EL NOMBRE DE ESTA CLAVE TIENE QUE SER EL EXACTO. El gate de crm_auth
       cachea el "sin saldo" 5 minutos en la sesión; si no se tira la clave que
       usa de verdad --`saldo_plataforma_cache`, la misma que limpia
       suscripcion.php-- el cliente paga, sigue viendo el CRM bloqueado, y lo
       natural es que vuelva a pagar. Tirar una clave inventada no falla: no
       hace nada, que es peor. */
    unset($_SESSION['saldo_plataforma_cache']);

    cr_salir(['ok' => true, 'usdt' => $v['monto'], 'cotizacion' => $cotiz,
              'acreditado' => $ars, 'saldo' => $saldo]);
}

cr_salir(['ok' => false, 'error' => 'acción desconocida'], 400);
