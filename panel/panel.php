<?php
/**
 * panel.php — API del plano de control (operador crea/gestiona clientes).
 *
 * Todo JSON. Auth por token firmado (HMAC): login devuelve un token, el resto
 * de las acciones lo piden en el header Authorization: Bearer <token>. Sin
 * sesiones de PHP (no dependemos de configurar almacenamiento de sesión).
 *
 * Lo que este archivo NO hace todavía: aprovisionar el dominio (Caddy), el
 * contenedor de bot ni el aislamiento por tenant_id. Eso son los ladrillos que
 * siguen; acá se registra el cliente y su config, que es lo que esos pasos van
 * a consumir.
 */
header('Content-Type: application/json; charset=utf-8');

// CORS: panel.html puede vivir en OTRO dominio (Hostinger) mientras panel.php
// se queda acá en el VPS (necesita Docker + MySQL local). Nunca "*": este
// endpoint crea clientes y devuelve credenciales/API keys, así que el origen
// permitido está fijo a mano, no reflejado desde el request.
$__origenesPermitidos = ['https://orange-crab-483661.hostingersite.com'];
$__origen = $_SERVER['HTTP_ORIGIN'] ?? '';
if (in_array($__origen, $__origenesPermitidos, true)) {
    header('Access-Control-Allow-Origin: ' . $__origen);
    header('Access-Control-Allow-Headers: Content-Type, Authorization');
    header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
}
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

$cfgFile = __DIR__ . '/panel_config.php';
if (!is_file($cfgFile)) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'falta panel_config.php']);
    exit;
}
$cfg = require $cfgFile;

try {
    $pdo = new PDO(
        "mysql:host={$cfg['DB_HOST']};dbname={$cfg['DB_NAME']};charset=utf8mb4",
        $cfg['DB_USER'], $cfg['DB_PASS'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
         PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'no pude conectar a la base de control']);
    exit;
}

// ---- helpers ----
function b64u(string $s): string { return rtrim(strtr(base64_encode($s), '+/', '-_'), '='); }
function b64u_dec(string $s): string { return (string) base64_decode(strtr($s, '-_', '+/')); }

function token_crear(string $usuario, string $secret): string {
    $p = b64u(json_encode(['u' => $usuario, 'exp' => time() + 60 * 60 * 12]));
    return $p . '.' . b64u(hash_hmac('sha256', $p, $secret, true));
}
function token_usuario(?string $token, string $secret): ?string {
    $parts = explode('.', (string) $token);
    if (count($parts) !== 2) return null;
    [$p, $sig] = $parts;
    if (!hash_equals(b64u(hash_hmac('sha256', $p, $secret, true)), $sig)) return null;
    $d = json_decode(b64u_dec($p), true);
    if (!is_array($d) || ($d['exp'] ?? 0) < time()) return null;
    return $d['u'] ?? null;
}
function body(): array { $j = json_decode(file_get_contents('php://input'), true); return is_array($j) ? $j : []; }
function bearer(): string {
    $h = $_SERVER['HTTP_AUTHORIZATION'] ?? ($_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
    return preg_match('/Bearer\s+(.+)/i', $h, $m) ? trim($m[1]) : '';
}
function slugify(string $s): string {
    $s = strtolower(trim($s));
    $s = preg_replace('/[^a-z0-9]+/', '-', $s);
    return trim((string) $s, '-');
}
function salida(array $x, int $code = 200): void { http_response_code($code); echo json_encode($x); exit; }

/**
 * Nombre de la base de un cliente por su id, ya validado. Devuelve null si no
 * existe o si el nombre no es [a-z0-9_] (defensa: ese nombre se usa para armar
 * el DSN de una conexión aparte).
 */
function cliente_db(PDO $pdo, int $id): ?string {
    $st = $pdo->prepare('SELECT db_nombre FROM clientes WHERE id = ?');
    $st->execute([$id]);
    $db = $st->fetchColumn();
    if (!$db || !preg_match('/^[a-z0-9_]+$/i', (string) $db)) return null;
    return (string) $db;
}

/**
 * Conexión a la base de UN cliente, con las mismas credenciales del panel (el
 * usuario de la app tiene GRANT en todas las bases; se lo da provisionar.php al
 * crear cada una). Es el único lugar del panel que sale de goldpaw_control.
 */
function conectar_cliente(array $cfg, string $db): PDO {
    return new PDO(
        "mysql:host={$cfg['DB_HOST']};dbname={$db};charset=utf8mb4",
        $cfg['DB_USER'], $cfg['DB_PASS'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );
}

/**
 * Crea la tabla `operadores` en la base del cliente si no está. Idempotente y
 * seguro sobre bases que ya la tienen (IF NOT EXISTS no toca la existente). Es
 * la misma tabla que usa crm_auth.php: username + password_hash + activo.
 */
function operadores_asegurar_tabla(PDO $cpdo): void {
    $cpdo->exec(
        "CREATE TABLE IF NOT EXISTS operadores (
           id INT AUTO_INCREMENT PRIMARY KEY,
           username VARCHAR(120) NOT NULL UNIQUE,
           password_hash VARCHAR(255) NOT NULL,
           rol ENUM('admin','agente') NOT NULL DEFAULT 'admin',
           activo TINYINT(1) NOT NULL DEFAULT 1,
           ultimo_login DATETIME DEFAULT NULL,
           creado TIMESTAMP DEFAULT CURRENT_TIMESTAMP
         ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
    // Clientes que ya tenían la tabla de antes de esta feature: agregarles la
    // columna sin romper (mismo patrón de las migraciones de api/sql/).
    try {
        $cpdo->exec("ALTER TABLE operadores ADD COLUMN IF NOT EXISTS rol ENUM('admin','agente') NOT NULL DEFAULT 'admin' AFTER password_hash");
    } catch (Throwable $e) { /* MariaDB viejo sin soporte de IF NOT EXISTS en ALTER: se ignora */ }
}

$in     = body();
$accion = $in['accion'] ?? ($_GET['accion'] ?? '');
$secret = $cfg['PANEL_SECRET'];

// ---- login (no requiere token) ----
if ($accion === 'login') {
    $u = trim($in['usuario'] ?? '');
    $st = $pdo->prepare('SELECT password_hash FROM operadores_panel WHERE usuario = ?');
    $st->execute([$u]);
    $hash = $st->fetchColumn();
    if ($hash && password_verify((string) ($in['password'] ?? ''), $hash)) {
        salida(['ok' => true, 'token' => token_crear($u, $secret), 'usuario' => $u]);
    }
    salida(['ok' => false, 'error' => 'usuario o clave incorrectos'], 401);
}

// ---- de acá en más: requiere token ----
$oper = token_usuario(bearer(), $secret);
if (!$oper) salida(['ok' => false, 'error' => 'no autorizado'], 401);

switch ($accion) {
    case 'listar':
        $rows = $pdo->query(
            'SELECT id,nombre,slug,dominio,path_tenant,cobro_alias,coins_por_peso,estado,creado,
                    saldo_usd,costo_diario_usd,suscripcion_estado,trial_hasta
             FROM clientes ORDER BY creado DESC'
        )->fetchAll();
        salida(['ok' => true, 'clientes' => $rows]);

    case 'ver':
        $id = (int) ($in['id'] ?? ($_GET['id'] ?? 0));
        $st = $pdo->prepare('SELECT * FROM clientes WHERE id = ?');
        $st->execute([$id]);
        $c = $st->fetch();
        $c ? salida(['ok' => true, 'cliente' => $c]) : salida(['ok' => false, 'error' => 'no existe'], 404);

    case 'crear':
        $nombre = trim($in['nombre'] ?? '');
        if ($nombre === '') {
            salida(['ok' => false, 'error' => 'el nombre es obligatorio'], 422);
        }
        // Todo cliente nuevo entra por path bajo el dominio propio del
        // operador -- nunca dominio a elección de quien llama a la API.
        $dominio    = $cfg['CF_ZONE_NAME'] ?? 'ganamoscrm.online';
        $pathTenant = 1;
        $slug       = slugify($in['slug'] ?? '') ?: slugify($nombre);
        if ($slug === '') {
            salida(['ok' => false, 'error' => 'no se pudo generar un slug válido del nombre'], 422);
        }
        $botKey = trim($in['bot_api_key'] ?? '') ?: bin2hex(random_bytes(24));
        // Nombre de la base del cliente: solo [a-z0-9_], porque va sin escapar
        // en un CREATE DATABASE del worker. gp_ de prefijo para no chocar con
        // otras bases del hosting.
        $dbNombre = 'gp_' . preg_replace('/[^a-z0-9]/', '_', $slug);
        try {
            $st = $pdo->prepare(
                'INSERT INTO clientes
                 (nombre,slug,dominio,path_tenant,db_nombre,agente_usuario,agente_password,cobro_alias,cobro_cbu,
                  cobro_titular,coins_por_peso,cohere_key,bot_api_key,notas,suscripcion_estado,trial_hasta)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
            );
            // Todo cliente nuevo arranca con 14 días de cortesía: el cron de
            // consumo (panel/consumo_diario.php) no le descuenta saldo ni lo
            // bloquea mientras siga en 'trial' y no haya pasado trial_hasta.
            $st->execute([
                $nombre, $slug, $dominio, $pathTenant, $dbNombre,
                $in['agente_usuario'] ?? null, $in['agente_password'] ?? null,
                $in['cobro_alias'] ?? null, $in['cobro_cbu'] ?? null, $in['cobro_titular'] ?? null,
                (float) ($in['coins_por_peso'] ?? 1),
                $in['cohere_key'] ?? null, $botKey,
                $in['notas'] ?? null,
                'trial', date('Y-m-d', strtotime('+14 days')),
            ]);
            // aprovisionado queda en 0 (default): el worker le crea la base en < 1 min.
            $url = $pathTenant ? ('https://' . $dominio . '/' . $slug . '/crm.html')
                                : ('https://' . $dominio . '/crm.html');
            salida(['ok' => true, 'id' => (int) $pdo->lastInsertId(), 'slug' => $slug,
                    'db_nombre' => $dbNombre, 'bot_api_key' => $botKey, 'url' => $url]);
        } catch (PDOException $e) {
            $dup = $e->getCode() === '23000';
            salida(['ok' => false, 'error' => $dup ? 'ya existe un cliente con ese dominio y slug' : 'no se pudo crear'], $dup ? 409 : 500);
        }
        // no cae acá

    case 'estado':
        $id     = (int) ($in['id'] ?? 0);
        $estado = $in['estado'] ?? '';
        if (!in_array($estado, ['activo', 'pausado', 'baja'], true)) {
            salida(['ok' => false, 'error' => 'estado inválido'], 422);
        }
        $st = $pdo->prepare('UPDATE clientes SET estado = ? WHERE id = ?');
        $st->execute([$estado, $id]);
        salida(['ok' => true]);

    case 'listar_operadores': {
        $id = (int) ($in['id'] ?? ($_GET['id'] ?? 0));
        $db = cliente_db($pdo, $id);
        if ($db === null) salida(['ok' => false, 'error' => 'cliente no existe'], 404);
        try {
            $cpdo = conectar_cliente($cfg, $db);
            operadores_asegurar_tabla($cpdo);
            $ops = $cpdo->query('SELECT id,username,rol,activo,ultimo_login FROM operadores ORDER BY username')->fetchAll();
            salida(['ok' => true, 'operadores' => $ops]);
        } catch (PDOException $e) {
            salida(['ok' => false, 'error' => 'no pude leer los operadores de este cliente'], 500);
        }
    }

    case 'crear_operador': {
        // Crea (o resetea la clave de) un operador del CRM en la base del
        // cliente. El db_nombre sale de `clientes` por id, NUNCA del navegador:
        // el que elige el tenant es el panel, no quien manda el request.
        $id   = (int) ($in['id'] ?? 0);
        $usr  = trim($in['usuario'] ?? '');
        $pass = (string) ($in['password'] ?? '');
        if ($usr === '' || strlen($pass) < 6) {
            salida(['ok' => false, 'error' => 'usuario y contraseña (mínimo 6) obligatorios'], 422);
        }
        $db = cliente_db($pdo, $id);
        if ($db === null) salida(['ok' => false, 'error' => 'cliente no existe'], 404);
        try {
            $cpdo = conectar_cliente($cfg, $db);
            operadores_asegurar_tabla($cpdo);
            // Upsert a mano (no ON DUPLICATE KEY): así funciona igual aunque la
            // tabla existente del cliente no tuviera UNIQUE en username.
            $ex = $cpdo->prepare('SELECT id FROM operadores WHERE username = ? LIMIT 1');
            $ex->execute([$usr]);
            $hash = password_hash($pass, PASSWORD_DEFAULT);
            if ($ex->fetchColumn()) {
                // Solo clave/activo: si ya era agente, resetear la clave no lo
                // convierte en admin de golpe. El rol se cambia aparte, si hiciera falta.
                $cpdo->prepare('UPDATE operadores SET password_hash = ?, activo = 1 WHERE username = ?')
                     ->execute([$hash, $usr]);
                salida(['ok' => true, 'usuario' => $usr, 'accion' => 'actualizado']);
            }
            // Los operadores que da de alta EL PANEL son accesos admin: pueden
            // gestionar agentes desde el CRM (ver crm.php::exigir_admin()).
            $cpdo->prepare("INSERT INTO operadores (username,password_hash,rol,activo) VALUES (?,?,'admin',1)")
                 ->execute([$usr, $hash]);
            salida(['ok' => true, 'usuario' => $usr, 'accion' => 'creado']);
        } catch (PDOException $e) {
            salida(['ok' => false, 'error' => 'no se pudo crear el operador'], 500);
        }
    }

    case 'saldo_ajustar': {
        // Ajuste manual de saldo de suscripción (cortesía, corrección). Queda
        // auditado con motivo y quién lo hizo -- mismo espíritu que
        // `movimientos` en la base de cada cliente.
        $id     = (int) ($in['id'] ?? 0);
        $delta  = (float) ($in['delta_usd'] ?? 0);
        $motivo = trim((string) ($in['motivo'] ?? ''));
        if ($id <= 0 || $delta == 0.0) {
            salida(['ok' => false, 'error' => 'faltan id o delta_usd (no puede ser 0)'], 422);
        }
        try {
            $pdo->beginTransaction();
            $st = $pdo->prepare(
                "UPDATE clientes SET saldo_usd = saldo_usd + ?,
                    suscripcion_estado = IF(saldo_usd + ? > 0 AND suscripcion_estado = 'sin_saldo', 'activa', suscripcion_estado)
                 WHERE id = ?"
            );
            $st->execute([$delta, $delta, $id]);
            if ($st->rowCount() !== 1) {
                $pdo->rollBack();
                salida(['ok' => false, 'error' => 'cliente no existe'], 404);
            }
            $pdo->prepare(
                'INSERT INTO ajustes_saldo_plataforma (cliente_id, delta_usd, motivo, operador) VALUES (?,?,?,?)'
            )->execute([$id, $delta, $motivo !== '' ? $motivo : null, $oper]);
            $pdo->commit();
            salida(['ok' => true]);
        } catch (PDOException $e) {
            $pdo->rollBack();
            salida(['ok' => false, 'error' => 'no se pudo ajustar el saldo'], 500);
        }
    }

    case 'editar': {
        // Edita los datos de un cliente ya creado.
        //
        // NO se toca `slug` ni `dominio`: son la URL con la que el cliente ya
        // esta operando y la llave con la que db.php resuelve su base. Y
        // `db_nombre` menos todavia -- cambiarlo apunta el CRM a otra base
        // (o a una que no existe) sin mover un solo dato.
        $id = (int) ($in['id'] ?? 0);
        if ($id <= 0) salida(['ok' => false, 'error' => 'falta el id'], 422);

        $nombre = trim((string) ($in['nombre'] ?? ''));
        if ($nombre === '') salida(['ok' => false, 'error' => 'el nombre es obligatorio'], 422);

        // Solo estos campos son editables. Lo que no este en la lista no se
        // toca, aunque venga en el body.
        $campos = [
            'nombre'         => $nombre,
            'agente_usuario' => $in['agente_usuario'] ?? null,
            'cobro_alias'    => $in['cobro_alias']    ?? null,
            'cobro_cbu'      => $in['cobro_cbu']      ?? null,
            'cobro_titular'  => $in['cobro_titular']  ?? null,
            'coins_por_peso' => (float) ($in['coins_por_peso'] ?? 1),
            'notas'          => $in['notas']          ?? null,
        ];

        // Las claves solo se pisan si mandaron una nueva: el formulario las
        // muestra vacias (nunca se devuelven), y un vacio ahi significa
        // "dejala como esta", no "borrala".
        $agPass = (string) ($in['agente_password'] ?? '');
        if ($agPass !== '') $campos['agente_password'] = $agPass;
        $cohere = (string) ($in['cohere_key'] ?? '');
        if ($cohere !== '') $campos['cohere_key'] = $cohere;

        $sets = [];
        $vals = [];
        foreach ($campos as $col => $val) {
            $sets[] = "$col = ?";
            $vals[] = ($val === '') ? null : $val;
        }
        $vals[] = $id;

        try {
            $st = $pdo->prepare('UPDATE clientes SET ' . implode(', ', $sets) . ' WHERE id = ?');
            $st->execute($vals);
            // rowCount 0 tambien pasa si guardaron sin cambiar nada, asi que
            // no alcanza para decir "no existe": se chequea aparte.
            $ex = $pdo->prepare('SELECT 1 FROM clientes WHERE id = ?');
            $ex->execute([$id]);
            if (!$ex->fetchColumn()) salida(['ok' => false, 'error' => 'cliente no existe'], 404);
            salida(['ok' => true]);
        } catch (PDOException $e) {
            salida(['ok' => false, 'error' => 'no se pudo guardar'], 500);
        }
    }

    case 'borrar': {
        // Baja de un cliente. Por defecto NO borra la fila: la marca
        // estado='baja', que ya deja de resolver en db.php (su WHERE pide
        // estado='activo') -- el CRM de ese cliente deja de atender en el acto.
        //
        // La base del cliente NUNCA se toca acá. Tiene sus jugadores, su
        // historial de recargas y sus conversaciones; borrarla desde un boton
        // del panel es irreversible y no hay como deshacerlo. Si de verdad hay
        // que eliminarla, se hace a mano en el servidor, mirando lo que hay.
        //
        // Con purgar=true se borra la FILA de `clientes` (no la base), para
        // sacar de la lista un cliente de prueba que nunca llego a usarse.
        // Exige que el nombre escrito coincida: es el mismo freno de "escribi
        // el nombre para confirmar" de GitHub, y evita el borrado por click.
        $id = (int) ($in['id'] ?? 0);
        if ($id <= 0) salida(['ok' => false, 'error' => 'falta el id'], 422);

        $st = $pdo->prepare('SELECT nombre, db_nombre FROM clientes WHERE id = ?');
        $st->execute([$id]);
        $cli = $st->fetch();
        if (!$cli) salida(['ok' => false, 'error' => 'cliente no existe'], 404);

        if (empty($in['purgar'])) {
            $pdo->prepare("UPDATE clientes SET estado = 'baja' WHERE id = ?")->execute([$id]);
            salida(['ok' => true, 'modo' => 'baja']);
        }

        $confirma = trim((string) ($in['confirmar_nombre'] ?? ''));
        if ($confirma !== (string) $cli['nombre']) {
            salida(['ok' => false, 'error' => 'el nombre no coincide'], 422);
        }
        try {
            $pdo->prepare('DELETE FROM clientes WHERE id = ?')->execute([$id]);
            // La base del cliente queda en el servidor, a proposito. Se informa
            // para que quien la quiera eliminar sepa cual es.
            salida(['ok' => true, 'modo' => 'purgado', 'db_huerfana' => $cli['db_nombre']]);
        } catch (PDOException $e) {
            salida(['ok' => false, 'error' => 'no se pudo borrar'], 500);
        }
    }

    case 'mp_config_ver':
        // Nunca devuelve el token en claro, solo si está configurado.
        $st = $pdo->prepare("SELECT valor FROM config_plataforma WHERE clave = 'MP_ACCESS_TOKEN'");
        $st->execute();
        $tok = $st->fetchColumn();
        salida(['ok' => true, 'configurado' => is_string($tok) && $tok !== '']);

    case 'mp_config_guardar':
        $tok = trim((string) ($in['token'] ?? ''));
        if ($tok === '') {
            salida(['ok' => false, 'error' => 'falta el token'], 422);
        }
        $pdo->prepare(
            'REPLACE INTO config_plataforma (clave, valor) VALUES (?, ?)'
        )->execute(['MP_ACCESS_TOKEN', $tok]);
        salida(['ok' => true]);

    // ================= HG Cash (pasarela de pagos, un token global) ==========

    case 'hg_config_ver':
        $claves = ['HG_API_TOKEN','HG_ACTIVO','HG_MODO','HG_ACCOUNT_ID',
                   'HG_WEBHOOK_SECRET','HG_COMISION_CLIENTE_PCT','HG_COSTO_HG_PCT'];
        $ph = implode(',', array_fill(0, count($claves), '?'));
        $st = $pdo->prepare("SELECT clave, valor FROM config_plataforma WHERE clave IN ($ph)");
        $st->execute($claves);
        $v = $st->fetchAll(PDO::FETCH_KEY_PAIR);
        // El token y el secret NUNCA vuelven en claro: solo si estan.
        salida(['ok' => true,
            'token_configurado'  => ($v['HG_API_TOKEN'] ?? '') !== '',
            'secret_configurado' => ($v['HG_WEBHOOK_SECRET'] ?? '') !== '',
            'activo'      => ($v['HG_ACTIVO'] ?? '0') === '1',
            'modo'        => $v['HG_MODO'] ?? 'prod',
            'account_id'  => $v['HG_ACCOUNT_ID'] ?? '',
            'comision'    => $v['HG_COMISION_CLIENTE_PCT'] ?? '3.5',
            'costo_hg'    => $v['HG_COSTO_HG_PCT'] ?? '2.0',
            // Esta URL se pega en el dashboard de HG (Settings -> Webhooks).
            'webhook_url' => 'https://ganamoscrm.online/gp-api/hg_webhook.php',
        ]);

    case 'hg_config_guardar':
        /* Guarda SOLO lo que vino: mandar los porcentajes no pisa el token,
           asi el dueño ajusta la comision sin re-pegar credenciales. */
        $mapa = [
            'token'      => 'HG_API_TOKEN',
            'secret'     => 'HG_WEBHOOK_SECRET',
            'activo'     => 'HG_ACTIVO',
            'modo'       => 'HG_MODO',
            'account_id' => 'HG_ACCOUNT_ID',
            'comision'   => 'HG_COMISION_CLIENTE_PCT',
            'costo_hg'   => 'HG_COSTO_HG_PCT',
        ];
        $rep = $pdo->prepare('REPLACE INTO config_plataforma (clave, valor) VALUES (?, ?)');
        $guardadas = 0;
        foreach ($mapa as $campo => $clave) {
            if (!array_key_exists($campo, $in)) { continue; }
            $val = trim((string)$in[$campo]);
            if ($campo === 'activo') { $val = ($val === '1' || $val === 'true') ? '1' : '0'; }
            if ($campo === 'modo' && !in_array($val, ['prod', 'dev'], true)) { $val = 'prod'; }
            if (in_array($campo, ['comision', 'costo_hg'], true)) {
                $f = (float)str_replace(',', '.', $val);
                if ($f < 0 || $f > 50) { salida(['ok' => false, 'error' => "porcentaje inválido en $campo"], 422); }
                $val = number_format($f, 2, '.', '');
            }
            // token/secret vacios NO se guardan (seria borrarlos sin querer).
            if (in_array($campo, ['token', 'secret'], true) && $val === '') { continue; }
            $rep->execute([$clave, $val]);
            $guardadas++;
        }
        salida(['ok' => true, 'guardadas' => $guardadas]);

    case 'hg_test':
        /* Prueba REAL contra la API: lista las cuentas del token. Confirma
           que el token vive y deja elegir el HG_ACCOUNT_ID de los retiros. */
        $st = $pdo->prepare("SELECT clave, valor FROM config_plataforma WHERE clave IN ('HG_API_TOKEN','HG_MODO')");
        $st->execute();
        $v = $st->fetchAll(PDO::FETCH_KEY_PAIR);
        $tok = $v['HG_API_TOKEN'] ?? '';
        if ($tok === '') { salida(['ok' => false, 'error' => 'primero guardá el token'], 422); }
        $base = ($v['HG_MODO'] ?? 'prod') === 'dev' ? 'http://dev.hg.cash/api/v1' : 'https://hg.cash/api/v1';

        $ch = curl_init($base . '/accounts');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $tok],
            CURLOPT_TIMEOUT        => 10,
        ]);
        $raw  = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        if ($raw === false) { salida(['ok' => false, 'error' => 'no pude conectar con hg.cash']); }
        if ($code === 401)  { salida(['ok' => false, 'error' => 'HG rechazó el token (401)']); }
        if ($code !== 200)  { salida(['ok' => false, 'error' => "HG contestó HTTP $code"]); }
        $cuentas = json_decode((string)$raw, true);
        salida(['ok' => true, 'cuentas' => is_array($cuentas) ? $cuentas : []]);

    case 'hg_resumen':
        /* La liquidacion: cuanto movio cada cliente y cuanto le toca a cada
           parte. Todo sale del libro (hg_transacciones); los porcentajes ya
           estan CONGELADOS por fila, aca solo se suma. */
        $dias = max(1, min(365, (int)($in['dias'] ?? 30)));
        try {
            $st = $pdo->prepare(
                "SELECT t.cliente_id, c.nombre,
                        SUM(t.tipo='deposito' AND t.estado='completado')                 depositos,
                        SUM(IF(t.tipo='deposito' AND t.estado='completado', t.monto, 0)) dep_bruto,
                        SUM(t.tipo='retiro' AND t.estado='pagado')                       retiros,
                        SUM(IF(t.tipo='retiro' AND t.estado='pagado', t.monto, 0))       ret_bruto,
                        SUM(IF(t.estado IN ('completado','pagado'), t.comision, 0))      comision,
                        SUM(IF(t.estado IN ('completado','pagado'), t.costo_hg, 0))      costo_hg,
                        SUM(IF(t.estado IN ('completado','pagado'), t.margen, 0))        margen,
                        SUM(IF(t.tipo='deposito' AND t.estado='completado', t.neto, 0))  neto_liquidar,
                        SUM(t.estado='pendiente')                                        pendientes
                   FROM hg_transacciones t
                   JOIN clientes c ON c.id = t.cliente_id
                  WHERE t.creado_en >= DATE_SUB(NOW(), INTERVAL ? DAY)
                  GROUP BY t.cliente_id, c.nombre
                  ORDER BY dep_bruto DESC"
            );
            $st->execute([$dias]);
            salida(['ok' => true, 'dias' => $dias, 'clientes' => $st->fetchAll(PDO::FETCH_ASSOC)]);
        } catch (PDOException $e) {
            salida(['ok' => false, 'error' => 'falta correr panel/sql/05_hgcash.sql'], 409);
        }

    default:
        salida(['ok' => false, 'error' => 'acción desconocida'], 400);
}
