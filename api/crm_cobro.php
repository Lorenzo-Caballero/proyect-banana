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
 * POST { accion:"mail_guardar", host, puerto, usuario, clave?, carpeta,
 *        remitentes, activo }                                        -> { ok }
 * POST { accion:"mail_probar" }   -> { ok, encontrados, ultimo, error? }
 *
 * LA CASILLA DE MAIL vive acá y no en Configuración porque es PARTE del
 * método de cobro: el texto que el cliente lee al elegir «Transferencia» dice
 * "el sistema lee tu casilla de mail y acredita solo", y hasta el 22/09/2026
 * eso era falso para cualquiera que no fuéramos nosotros -- la config IMAP
 * estaba en un archivo del servidor, la nuestra. Ponerla en otra pantalla
 * dejaría la promesa en un lado y la forma de cumplirla en otro.
 */

declare(strict_types=1);
require __DIR__ . '/config.php';
require __DIR__ . '/db.php';
require __DIR__ . '/crm_auth.php';
require __DIR__ . '/crm_lib.php';
// El cifrado de la clave de la casilla (AES-256-GCM, mismo formato que lee
// colector/cripto.py). Sin llave configurada se NIEGA a cifrar: guardar la
// contraseña de la casilla de otra persona en claro no es una opción.
require_once __DIR__ . '/cripto.php';
// El probador de la casilla (solo lectura). Ver mail_imap.php.
require_once __DIR__ . '/mail_imap.php';

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

/* Las columnas de la casilla son de la migración 09 del control. Se piden
   aparte y con try: un cliente cuya base todavía no la corrió tiene que poder
   seguir usando el resto de la pantalla. */
$colsMail = 'mail_host, mail_puerto, mail_usuario, mail_clave, mail_carpeta, '
          . 'mail_remitentes, mail_activo, mail_visto_en, mail_error';
try {
    $st = $ctl->prepare(
        'SELECT id, metodo_cobro, coins_por_peso, cobro_alias, cobro_cbu, cobro_titular, cobro_modo, cobro_fija_id,
                hg_propio_activo, hg_propio_token, hg_propio_account_id, hg_propio_modo,
                ' . $colsMail . '
           FROM clientes WHERE db_nombre = ? LIMIT 1'
    );
    $st->execute([(string)($GLOBALS['TENANT_DB'] ?? '')]);
    $cliente = $st->fetch();
    $hayMail = true;
} catch (Throwable $e) {
    $hayMail = false;
    $st = $ctl->prepare(
        'SELECT id, metodo_cobro, coins_por_peso, cobro_alias, cobro_cbu, cobro_titular, cobro_modo, cobro_fija_id,
                hg_propio_activo, hg_propio_token, hg_propio_account_id, hg_propio_modo
           FROM clientes WHERE db_nombre = ? LIMIT 1'
    );
}
if (!$hayMail) {
    $st->execute([(string)($GLOBALS['TENANT_DB'] ?? '')]);
    $cliente = $st->fetch();
}
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
        // Cuántos coins vale un peso PARA ESTE CLIENTE (lo usa rl_crear_recarga
        // para calcular el monto a transferir). Antes lo cargaba el dueño de la
        // plataforma en su panel; ahora es del cliente, como el resto de esto.
        'coins_por_peso' => (float)($cliente['coins_por_peso'] ?? 1),
        'cobro_modo'    => (string)($cliente['cobro_modo'] ?? 'azar'),
        'cobro_fija_id' => $cliente['cobro_fija_id'] !== null ? (int)$cliente['cobro_fija_id'] : 0,
        'hg_propio' => [
            'activo'     => (int)($cliente['hg_propio_activo'] ?? 0) === 1,
            'tiene_token' => trim((string)($cliente['hg_propio_token'] ?? '')) !== '',
            'account_id' => (string)($cliente['hg_propio_account_id'] ?? ''),
            'modo'       => (string)($cliente['hg_propio_modo'] ?? 'prod'),
            'webhook_url' => cobro_webhook_url(),
        ],
        /* NUNCA la clave, ni cifrada: solo si HAY una. Mismo criterio que el
           token de HG Cash y el de Meta -- un campo vacío en el POST significa
           "no la cambies", nunca "borrala". */
        'mail' => $hayMail ? [
            'host'       => (string)($cliente['mail_host'] ?? ''),
            'puerto'     => (int)($cliente['mail_puerto'] ?? 993),
            'usuario'    => (string)($cliente['mail_usuario'] ?? ''),
            'tiene_clave'=> trim((string)($cliente['mail_clave'] ?? '')) !== '',
            'carpeta'    => (string)($cliente['mail_carpeta'] ?? 'INBOX'),
            'remitentes' => (string)($cliente['mail_remitentes'] ?? ''),
            'activo'     => (int)($cliente['mail_activo'] ?? 0) === 1,
            /* LO QUE EVITA EL FALLO SILENCIOSO: cuándo funcionó por última vez
               y cuál fue el último error. Si el cliente revoca la contraseña
               de aplicación, sus jugadores dejan de cobrar y NADA se rompe a
               la vista -- el CRM abre, el chat contesta, y las recargas quedan
               pendientes para siempre. Esto es lo que lo hace visible. */
            'visto_en'   => $cliente['mail_visto_en'] ?? null,
            'error'      => (string)($cliente['mail_error'] ?? ''),
            'cripto_ok'  => cripto_disponible(),
        ] : null,
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

        /* Cuántos coins vale un peso. Del CLIENTE, no del dueño (15/09/2026):
           es SU precio, y cargarlo en otro panel era el campo decorativo de
           siempre. La guarda del > 0 está repetida en rl_coins_por_peso()
           (un 0 acá dividiría por cero en el cálculo del monto), pero mejor
           rechazarlo en la puerta que degradar en silencio a la constante. */
        if ($accion === 'coins_por_peso_guardar') {
            $v = (float)($body['valor'] ?? 0);
            if ($v <= 0 || $v > 10000) {
                salir(['ok' => false, 'error' => 'Coins por peso tiene que ser mayor a 0'], 400);
            }
            $ctl->prepare('UPDATE clientes SET coins_por_peso = ? WHERE id = ?')->execute([$v, $clienteId]);
            crm_bitacora($pdo, $operador, 'cobro_coins_por_peso', "valor=$v");
            salir(['ok' => true]);
        }

        // Cual es la billetera EN USO. fija_id=0 (o ausente) significa "la
        // principal" -- mismo sentinel que usa recargas_lib.php.
        //
        // El modo 'azar' ya no se ofrece (el CRM manda siempre 'fija') pero el
        // parametro se sigue aceptando para no romper lo ya guardado. La
        // rotacion se saco porque el CBU tambien vive en el panel de ganamos
        // y con rotacion las dos fuentes se desincronizan en silencio -- ver
        // rl_cuenta_elegida() en recargas_lib.php.
        //
        // No se valida que la cuenta exista: si el cliente la pausa o la
        // borra despues, rl_cuenta_elegida() cae sola a la principal.
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

        /* GUARDAR LA CASILLA. La clave se cifra con AES-256-GCM (cripto.php,
           el mismo formato que lee colector/cripto.py). Si no hay llave
           configurada se RECHAZA en vez de guardar en claro: es la contraseña
           de la casilla de otra persona. */
        if ($accion === 'mail_guardar') {
            $host  = mb_substr(trim((string)($body['host'] ?? '')), 0, 120);
            $usr   = mb_substr(trim((string)($body['usuario'] ?? '')), 0, 190);
            $puerto= (int)($body['puerto'] ?? 993);
            if ($puerto < 1 || $puerto > 65535) { $puerto = 993; }
            $carp  = mb_substr(trim((string)($body['carpeta'] ?? 'INBOX')), 0, 120) ?: 'INBOX';
            $rem   = mb_substr(trim((string)($body['remitentes'] ?? '')), 0, 400);
            $act   = !empty($body['activo']);
            $clave = (string)($body['clave'] ?? '');

            /* PRENDERLA SIN LOS DATOS COMPLETOS es la forma de que el cliente
               crea que está cobrando y no. Se exige todo antes de dejar
               activar; apagada se puede guardar a medias para seguir después. */
            if ($act && ($host === '' || $usr === '')) {
                salir(['ok' => false, 'error' => 'Para activarla faltan el servidor y el usuario'], 400);
            }

            $sets = ['mail_host = ?', 'mail_puerto = ?', 'mail_usuario = ?',
                     'mail_carpeta = ?', 'mail_remitentes = ?', 'mail_activo = ?'];
            $args = [$host ?: null, $puerto, $usr ?: null, $carp, $rem ?: null, $act ? 1 : 0];

            if ($clave !== '') {
                if (!cripto_disponible()) {
                    salir(['ok' => false, 'error' =>
                        'No hay llave de cifrado en el servidor: no se puede guardar la contraseña. '
                        . 'Avisale al soporte (falta GOLDPAW_CRIPTO_LLAVE).'], 500);
                }
                $cif = cripto_cifrar($clave);
                if ($cif === null) {
                    salir(['ok' => false, 'error' => 'No se pudo cifrar la contraseña'], 500);
                }
                $sets[] = 'mail_clave = ?';
                $args[] = $cif;
            }
            /* Al activarla se limpia el error viejo: si no, la pantalla sigue
               mostrando el motivo de una configuración que ya se corrigió. */
            if ($act) { $sets[] = 'mail_error = NULL'; }

            $args[] = $clienteId;
            try {
                $ctl->prepare('UPDATE clientes SET ' . implode(', ', $sets) . ' WHERE id = ?')
                    ->execute($args);
            } catch (Throwable $e) {
                error_log('mail_guardar: ' . $e->getMessage());
                salir(['ok' => false, 'error' => 'No se pudo guardar (¿falta la migración 09 del control?)'], 500);
            }
            crm_bitacora($pdo, $operador, 'mail_guardar',
                ($act ? 'casilla ACTIVA' : 'casilla apagada') . ' ' . $usr);
            salir(['ok' => true]);
        }

        /* PROBAR LA CONEXIÓN, que es el 80% del valor de esta pantalla.
           Pedirle a alguien servidor, puerto, usuario y una contraseña de
           aplicación sin decirle en el momento si funciona garantiza que la
           mitad queden mal configuradas -- y el modo de fallar de esto es
           silencioso: nadie se entera hasta que un jugador reclama que no le
           acreditaron.

           Se prueba con lo YA GUARDADO (incluida la clave cifrada), no con lo
           que está en pantalla: así lo que se prueba es exactamente lo que va
           a usar el colector. Por eso el front guarda antes de probar. */
        if ($accion === 'mail_probar') {
            try {
                $st = $ctl->prepare('SELECT ' . $colsMail . ' FROM clientes WHERE id = ? LIMIT 1');
                $st->execute([$clienteId]);
                $m = $st->fetch();
            } catch (Throwable $e) {
                salir(['ok' => false, 'error' => 'Falta la migración 09 del control'], 500);
            }
            if (!$m || trim((string)($m['mail_host'] ?? '')) === ''
                    || trim((string)($m['mail_usuario'] ?? '')) === '') {
                salir(['ok' => false, 'error' => 'Faltan el servidor o el usuario'], 400);
            }
            $clave = cripto_descifrar($m['mail_clave'] ?? null);
            if ($clave === null || $clave === '') {
                salir(['ok' => false, 'error' => 'Todavía no cargaste la contraseña de aplicación'], 400);
            }
            $r = mail_probar_imap(
                (string)$m['mail_host'], (int)$m['mail_puerto'], (string)$m['mail_usuario'],
                $clave, (string)($m['mail_carpeta'] ?: 'INBOX'), (string)($m['mail_remitentes'] ?? '')
            );
            /* El resultado se guarda: una prueba que anduvo ES una lectura que
               anduvo, y deja el estado de la pantalla al día sin esperar al
               colector. */
            try {
                $ctl->prepare('UPDATE clientes SET mail_visto_en = ?, mail_error = ? WHERE id = ?')
                    ->execute([
                        !empty($r['ok']) ? date('Y-m-d H:i:s') : ($m['mail_visto_en'] ?? null),
                        !empty($r['ok']) ? null : mb_substr((string)($r['error'] ?? 'error'), 0, 300),
                        $clienteId,
                    ]);
            } catch (Throwable $e) { /* el resultado de la prueba se devuelve igual */ }
            salir($r);
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
