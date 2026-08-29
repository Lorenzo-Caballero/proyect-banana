<?php
/**
 * crm_cobro.php — "Cómo cobro" del CRM del cliente: método de recarga
 * automática (transferencia con cuenta propia, o HG Cash con token propio),
 * las cuentas de transferencia, y las credenciales de HG Cash propias.
 *
 * Tenant-aware (db.php resuelve a qué cliente pertenece la sesión que
 * pide), pero conecta ADEMÁS a goldpaw_control (la base maestra) porque
 * todo esto vive ahí -- metodo_cobro/hg_propio_* en `clientes`, las cuentas
 * extra en `cobro_cuentas` (migración panel/sql/06_metodo_cobro.sql).
 *
 * Nunca devuelve tokens/secrets en claro: solo "tiene_token" (bool). El
 * mismo criterio que ya usa crm_publicidad.php con el Token CAPI de Meta --
 * un campo vacío en el POST significa "no lo cambies", nunca "borralo".
 *
 * GET  ?accion=estado
 *        -> { ok, metodo_cobro, cuenta_principal, cuentas_extra:[...],
 *              cobro_modo, cobro_fija_id,
 *              hg_propio:{activo,tiene_token,account_id,modo,webhook_url} }
 * POST { accion:"metodo_guardar", metodo_cobro }                     -> { ok }
 * POST { accion:"modo_seleccion_guardar", modo, fija_id }            -> { ok }
 *        (fija_id: 0/ausente = la principal; id de cobro_cuentas si no)
 * POST { accion:"hg_propio_guardar", activo, token?, account_id?,
 *        webhook_secret?, modo }                                     -> { ok }
 * POST { accion:"cuenta_agregar", alias?, cbu, titular? }            -> { ok, id }
 * POST { accion:"cuenta_editar", id, alias?, cbu, titular?, activa } -> { ok }
 * POST { accion:"cuenta_borrar", id }                                -> { ok }
 */

declare(strict_types=1);
require __DIR__ . '/config.php';
require __DIR__ . '/db.php';
require __DIR__ . '/crm_auth.php';
require __DIR__ . '/crm_lib.php';

header('Content-Type: application/json; charset=utf-8');

$operador = exigir_operador();

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

/** La URL de webhook de ESTE tenant -- misma resolución que
 *  hg_propio_webhook_url() en hgcash_lib.php, sin depender de esa lib
 *  (este archivo no manda eventos, no hace falta cargarla entera). */
function cobro_webhook_url(): string
{
    $host = (string)($GLOBALS['TENANT_HOST'] ?? $_SERVER['HTTP_HOST'] ?? 'ganamoscrm.online');
    $slug = (string)($GLOBALS['TENANT_SLUG'] ?? '');
    return 'https://' . $host . ($slug !== '' ? '/' . $slug : '') . '/gp-api/hg_webhook.php';
}

try {
    $ctl = control_pdo();
} catch (Throwable $e) {
    error_log('crm_cobro: no pude conectar a goldpaw_control: ' . $e->getMessage());
    salir(['ok' => false, 'error' => 'No se pudo conectar a la base de control'], 500);
}

$st = $ctl->prepare(
    'SELECT id, metodo_cobro, cobro_alias, cobro_cbu, cobro_titular, cobro_modo, cobro_fija_id,
            hg_propio_activo, hg_propio_token, hg_propio_account_id, hg_propio_modo
       FROM clientes WHERE db_nombre = ? LIMIT 1'
);
$st->execute([(string)($GLOBALS['TENANT_DB'] ?? '')]);
$cliente = $st->fetch();
if (!$cliente) { salir(['ok' => false, 'error' => 'cliente no resuelto'], 500); }
$clienteId = (int)$cliente['id'];

$metodo = $_SERVER['REQUEST_METHOD'];

// ============================== GET =========================================
if ($metodo === 'GET' && ($_GET['accion'] ?? '') === 'estado') {
    $st = $ctl->prepare('SELECT id, alias, cbu, titular, activa FROM cobro_cuentas WHERE cliente_id = ? ORDER BY id');
    $st->execute([$clienteId]);

    salir([
        'ok' => true,
        'metodo_cobro' => (string)($cliente['metodo_cobro'] ?? 'transferencia'),
        // id=0: mismo sentinel que usa recargas_lib.php (rl_cuenta_cobro) para
        // identificar "la principal" junto a las de cobro_cuentas -- así el
        // frontend puede tratar a todas las cuentas como una sola lista.
        'cuenta_principal' => [
            'id'      => 0,
            'alias'   => (string)($cliente['cobro_alias']   ?? ''),
            'cbu'     => (string)($cliente['cobro_cbu']     ?? ''),
            'titular' => (string)($cliente['cobro_titular'] ?? ''),
        ],
        'cuentas_extra' => $st->fetchAll(),
        'cobro_modo'    => (string)($cliente['cobro_modo'] ?? 'azar'),
        'cobro_fija_id' => $cliente['cobro_fija_id'] !== null ? (int)$cliente['cobro_fija_id'] : 0,
        'hg_propio' => [
            'activo'     => (int)($cliente['hg_propio_activo'] ?? 0) === 1,
            'tiene_token' => trim((string)($cliente['hg_propio_token'] ?? '')) !== '',
            'account_id' => (string)($cliente['hg_propio_account_id'] ?? ''),
            'modo'       => (string)($cliente['hg_propio_modo'] ?? 'prod'),
            'webhook_url' => cobro_webhook_url(),
        ],
    ]);
}

// ============================== POST ========================================
if ($metodo === 'POST') {
    $body   = json_decode(file_get_contents('php://input'), true) ?: [];
    $accion = (string)($body['accion'] ?? '');

    try {
        if ($accion === 'metodo_guardar') {
            $nuevo = (string)($body['metodo_cobro'] ?? '');
            if (!in_array($nuevo, ['transferencia', 'hgcash'], true)) {
                salir(['ok' => false, 'error' => 'Método inválido'], 400);
            }
            $ctl->prepare('UPDATE clientes SET metodo_cobro = ? WHERE id = ?')->execute([$nuevo, $clienteId]);
            crm_bitacora($pdo, $operador, 'cobro_metodo', "metodo=$nuevo");
            salir(['ok' => true]);
        }

        // Con mas de una cuenta de transferencia: rotar al azar (de siempre)
        // o fijar siempre la misma. fija_id=0 (o ausente) significa "la
        // principal" -- mismo sentinel que usa recargas_lib.php. No se valida
        // que la cuenta exista: si el cliente la pausa o la borra despues,
        // rl_cuenta_elegida() ya cae sola a azar (fail-safe, ver esa función).
        if ($accion === 'modo_seleccion_guardar') {
            $modo   = ((string)($body['modo'] ?? 'azar')) === 'fija' ? 'fija' : 'azar';
            $fijaId = (int)($body['fija_id'] ?? 0);
            $ctl->prepare('UPDATE clientes SET cobro_modo = ?, cobro_fija_id = ? WHERE id = ?')
                ->execute([$modo, $fijaId > 0 ? $fijaId : null, $clienteId]);
            crm_bitacora($pdo, $operador, 'cobro_modo_seleccion', "modo=$modo fija_id=$fijaId");
            salir(['ok' => true]);
        }

        if ($accion === 'hg_propio_guardar') {
            $activo    = !empty($body['activo']);
            $token     = trim((string)($body['token']     ?? ''));
            $accountId = trim((string)($body['account_id'] ?? ''));
            $secret    = trim((string)($body['webhook_secret'] ?? ''));
            $modo      = ((string)($body['modo'] ?? 'prod')) === 'dev' ? 'dev' : 'prod';

            // Mismo criterio que crm_publicidad.php: un campo vacío al editar
            // significa "no lo toques" -- el token/secret no vuelven nunca al
            // frontend, así que exigir que siempre vengan completos rompería
            // cualquier edición que no sea "cambiar el token a propósito".
            $campos = ['hg_propio_activo = ?', 'hg_propio_modo = ?'];
            $valores = [$activo ? 1 : 0, $modo];
            if ($token !== '')     { $campos[] = 'hg_propio_token = ?';           $valores[] = $token; }
            if ($accountId !== '') { $campos[] = 'hg_propio_account_id = ?';      $valores[] = $accountId; }
            if ($secret !== '')    { $campos[] = 'hg_propio_webhook_secret = ?';  $valores[] = $secret; }
            $valores[] = $clienteId;

            if ($activo && $token === '' && trim((string)($cliente['hg_propio_token'] ?? '')) === '') {
                salir(['ok' => false, 'error' => 'Necesitás cargar el token de HG Cash para activarlo'], 400);
            }

            $ctl->prepare('UPDATE clientes SET ' . implode(', ', $campos) . ' WHERE id = ?')->execute($valores);
            crm_bitacora($pdo, $operador, 'cobro_hg_propio', 'activo=' . ($activo ? '1' : '0'));
            salir(['ok' => true]);
        }

        if ($accion === 'cuenta_agregar') {
            $alias   = trim((string)($body['alias']   ?? ''));
            $cbu     = trim((string)($body['cbu']     ?? ''));
            $titular = trim((string)($body['titular'] ?? ''));
            if ($cbu === '') { salir(['ok' => false, 'error' => 'Falta el CBU/CVU'], 400); }

            $ins = $ctl->prepare(
                'INSERT INTO cobro_cuentas (cliente_id, alias, cbu, titular, activa) VALUES (?,?,?,?,1)'
            );
            $ins->execute([$clienteId, $alias !== '' ? $alias : null, $cbu, $titular !== '' ? $titular : null]);
            $nuevoId = (int)$ctl->lastInsertId();
            crm_bitacora($pdo, $operador, 'cobro_cuenta_agregar', "id=$nuevoId cbu=$cbu");
            salir(['ok' => true, 'id' => $nuevoId]);
        }

        if ($accion === 'cuenta_editar') {
            $id      = (int)($body['id'] ?? 0);
            $alias   = trim((string)($body['alias']   ?? ''));
            $cbu     = trim((string)($body['cbu']     ?? ''));
            $titular = trim((string)($body['titular'] ?? ''));
            $activa  = !empty($body['activa']);
            if ($id <= 0 || $cbu === '') { salir(['ok' => false, 'error' => 'Faltan datos'], 400); }

            // WHERE cliente_id = ? además de id: nadie puede editar la cuenta
            // de otro cliente adivinando un id.
            $upd = $ctl->prepare(
                'UPDATE cobro_cuentas SET alias=?, cbu=?, titular=?, activa=? WHERE id=? AND cliente_id=?'
            );
            $upd->execute([$alias !== '' ? $alias : null, $cbu, $titular !== '' ? $titular : null,
                            $activa ? 1 : 0, $id, $clienteId]);
            if ($upd->rowCount() === 0) { salir(['ok' => false, 'error' => 'Cuenta inexistente'], 404); }
            crm_bitacora($pdo, $operador, 'cobro_cuenta_editar', "id=$id");
            salir(['ok' => true]);
        }

        if ($accion === 'cuenta_borrar') {
            $id = (int)($body['id'] ?? 0);
            if ($id <= 0) { salir(['ok' => false, 'error' => 'Falta el id'], 400); }
            $del = $ctl->prepare('DELETE FROM cobro_cuentas WHERE id=? AND cliente_id=?');
            $del->execute([$id, $clienteId]);
            if ($del->rowCount() === 0) { salir(['ok' => false, 'error' => 'Cuenta inexistente'], 404); }
            crm_bitacora($pdo, $operador, 'cobro_cuenta_borrar', "id=$id");
            salir(['ok' => true]);
        }

        salir(['ok' => false, 'error' => 'Acción desconocida'], 400);
    } catch (Throwable $e) {
        error_log('crm_cobro POST: ' . $e->getMessage());
        salir(['ok' => false, 'error' => 'Error al guardar'], 500);
    }
}

salir(['ok' => false, 'error' => 'Método no permitido'], 405);
