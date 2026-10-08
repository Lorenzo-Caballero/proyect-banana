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

// CORS: panel.html puede vivir en OTRO dominio mientras panel.php se queda
// acá en el VPS (necesita Docker + MySQL local). Hoy los dos salen de
// ganamoscrm.online, o sea mismo origen y este header ni hace falta; la lista
// queda por si panel.html vuelve a servirse aparte. Nunca "*": este endpoint
// crea clientes y devuelve credenciales/API keys, así que el origen permitido
// está fijo a mano, no reflejado desde el request.
$__origenesPermitidos = ['https://ganamoscrm.online'];
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

/**
 * ¿Corrió ya la migración 07 del control (clientes.ia_key)?
 *
 * EL DEPLOY VA EN DOS PASOS Y ESTE ES EL HUECO: el código se publica con
 * `git pull` y las migraciones del control las corre una persona a mano. Entre
 * una cosa y la otra, un INSERT que nombre `ia_key` tira "Unknown column" y
 * CREAR UN CLIENTE deja de funcionar del todo — que es la acción principal de
 * este panel. Con este chequeo, mientras tanto sigue escribiendo en la columna
 * vieja y no se rompe nada; cuando la migración corre, pasa sola a la nueva
 * (la 07 copia los valores que hubiera).
 *
 * Mismo patrón y mismo porqué que crm_hay_derivada() del lado de la API. El
 * nombre sale de un par fijo, nunca del request: se interpola en SQL.
 */
function col_ia(PDO $pdo): string {
    static $col = null;
    if ($col === null) {
        try {
            $pdo->query('SELECT ia_key FROM clientes LIMIT 0');
            $col = 'ia_key';
        } catch (Throwable $e) {
            $col = 'cohere_key';
        }
    }
    return $col;
}

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
function ruta_slug_valida(string $s): bool {
    static $reservadas = ['home','slots','casino','games','game','poker','live','sports','sportsbook',
        'profile','account','deposit','withdraw','wallet','cashier','settings','history','support','help',
        'rewards','affiliate','promos','promotions','bonus','bonuses','roulette','user','signup',
        'forgot-password','login','logout','register','registration','chat','crm','admin','registro','configurar',
        'bono','lp','gp-api','api','panel','replica','assets','img','fonts','css','js','sw'];
    return strlen($s) >= 2 && strlen($s) <= 60
        && preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $s)
        && !in_array($s, $reservadas, true);
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

/* =====================================================================
 * LANDING PROPIA DEL OPERADOR. Este bloque solo edita la página de Ganamos.
 * Las landings de clientes CRM se guardan desde su propio CRM, y el producto
 * Landing independiente usa landing_portal.php. No usar este helper para
 * guardar datos de clientes: la base se resuelve exclusivamente a la del
 * operador de la plataforma.
 * ===================================================================== */

/** El slug del tenant propio del operador. Mismo marcador que usa
 *  provisionar.php (SLUGS_CON_BOT_PROPIO). Se puede pisar con 'LANDINGS_DB'
 *  en panel_config.php. */
const SLUG_PROPIO = 'ganamoscrm';

/**
 * La base donde viven las landings de cajero: LA NUESTRA.
 * Devuelve [db|null, comoSeResolvio, error].
 */
function landings_db_propia(PDO $pdo, array $cfg): array
{
    $forzada = trim((string) ($cfg['LANDINGS_DB'] ?? ''));
    if ($forzada !== '') {
        if (!preg_match('/^[a-z0-9_]+$/i', $forzada)) {
            return [null, '', "LANDINGS_DB ('{$forzada}') no es un nombre de base válido"];
        }
        return [$forzada, "LANDINGS_DB de panel_config.php", ''];
    }
    try {
        $st = $pdo->prepare('SELECT db_nombre FROM clientes WHERE slug = ? LIMIT 1');
        $st->execute([SLUG_PROPIO]);
        $db = (string) ($st->fetchColumn() ?: '');
    } catch (PDOException $e) {
        return [null, '', 'no pude leer la tabla clientes'];
    }
    if ($db === '' || !preg_match('/^[a-z0-9_]+$/i', $db)) {
        return [null, '', 'no encuentro nuestro propio tenant (slug "' . SLUG_PROPIO . '"). '
                        . 'Agregá LANDINGS_DB con el nombre de nuestra base a panel_config.php'];
    }
    return [$db, 'el cliente con slug "' . SLUG_PROPIO . '"', ''];
}

/** El link público de una landing nuestra. Sale de CF_ZONE_NAME, que es el
 *  dominio donde corre nuestra plataforma. */
function landing_url(array $cfg, string $slug): string
{
    $host = trim((string) ($cfg['CF_ZONE_NAME'] ?? '')) ?: 'ganamoscrm.online';
    return 'https://' . $host . '/lp.html?l=' . rawurlencode($slug);
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
            'SELECT id,nombre,slug,ruta_slug,dominio,path_tenant,db_nombre,producto,aprovisionado,aprov_detalle,cobro_alias,coins_por_peso,estado,creado,
                    saldo_usd,costo_diario_usd,suscripcion_estado,trial_hasta
             FROM clientes ORDER BY creado DESC'
        )->fetchAll();
        salida(['ok' => true, 'clientes' => $rows]);

    case 'detalle_cliente': {
        $id = (int) ($in['id'] ?? 0);
        $st = $pdo->prepare('SELECT id,nombre,slug,ruta_slug,dominio,path_tenant,db_nombre,producto,estado,creado,aprovisionado,aprov_detalle,notas FROM clientes WHERE id=?');
        $st->execute([$id]);
        $c = $st->fetch();
        if (!$c) salida(['ok' => false, 'error' => 'no existe'], 404);
        $dominio = rtrim((string)$c['dominio'], '/');
        $base = 'https://' . $dominio . '/' . ((int)$c['path_tenant'] ? rawurlencode((string)($c['ruta_slug'] ?: $c['slug'])) . '/' : '');
        $urls = ($c['producto'] ?? 'crm') === 'landing'
            ? ['configuracion' => 'https://' . $dominio . '/replica/configurar.html']
            : ['crm' => $base . 'crm.html', 'jugadores' => $base, 'registro' => $base . 'registro.html', 'bono' => $base . 'bono.html'];
        $rutasAnteriores = [];
        if ((int)$c['path_tenant'] === 1) {
            $qRutas = $pdo->prepare('SELECT ruta_slug FROM clientes_rutas_path WHERE cliente_id=? AND dominio=? AND ruta_slug<>? ORDER BY creada DESC');
            $qRutas->execute([$id,$c['dominio'],(string)($c['ruta_slug'] ?: $c['slug'])]);
            $rutasAnteriores = $qRutas->fetchAll(PDO::FETCH_COLUMN);
        }
        $diagnostico = ['base_datos' => 'no disponible', 'operadores' => [], 'chatbot_activo' => null, 'landings' => []];
        try {
            $db = cliente_db($pdo, $id);
            if ($db) {
                $cpdo = conectar_cliente($cfg, $db);
                $diagnostico['base_datos'] = 'conectada';
                $tables = $cpdo->query('SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE()')->fetchAll(PDO::FETCH_COLUMN);
                if (in_array('operadores', $tables, true)) {
                    $diagnostico['operadores'] = $cpdo->query('SELECT username,rol,activo,ultimo_login FROM operadores ORDER BY username')->fetchAll();
                }
                if (in_array('config_chatbot', $tables, true)) {
                    $row = $cpdo->query('SELECT activo FROM config_chatbot LIMIT 1')->fetch();
                    $diagnostico['chatbot_activo'] = $row ? (bool)$row['activo'] : null;
                }
                if (in_array('landings', $tables, true)) {
                    $diagnostico['landings'] = $cpdo->query('SELECT slug,nombre,bono_pct FROM landings WHERE activa=1 ORDER BY nombre')->fetchAll();
                    // El producto independiente publica su enlace al comprador
                    // cuando el portal confirma que el bot está en ejecución.
                    // No ofrecer acá un enlace directo que saltee esa comprobación.
                    if (($c['producto'] ?? 'crm') !== 'landing') {
                        foreach ($diagnostico['landings'] as $landing) $urls['campania:' . $landing['nombre']] = $base . 'lp.html?l=' . rawurlencode((string)$landing['slug']);
                    }
                }
            }
        } catch (Throwable $e) {
            $diagnostico['base_datos'] = 'sin conexión';
        }
        salida(['ok' => true, 'cliente' => $c, 'urls' => $urls, 'rutas_anteriores' => $rutasAnteriores, 'diagnostico' => $diagnostico]);
    }

    case 'ver':
        $id = (int) ($in['id'] ?? ($_GET['id'] ?? 0));
        $st = $pdo->prepare('SELECT * FROM clientes WHERE id = ?');
        $st->execute([$id]);
        $c = $st->fetch();
        if (!$c) salida(['ok' => false, 'error' => 'no existe'], 404);
        /* LOS SECRETOS NO SALEN DE ACA. El formulario de edicion ya decia
           'las claves NUNCA se precargan: el server no las devuelve'... y no
           era cierto: este SELECT * las mandaba todas al navegador (la
           password del agente en ganamos, la clave de IA, la API key del bot,
           el token y el webhook secret de HG Cash). Nadie las usaba del otro
           lado, asi que el unico efecto era exponerlas: quedan en el historial
           del navegador, en cualquier proxy corporativo y en las devtools de
           quien tenga la pantalla abierta.
           Se reemplazan por un booleano `<campo>_cargada`, que es lo unico que
           el formulario necesita saber para decir 'vacio = no cambiar'. */
        $secretos = ['agente_password', 'ia_key', 'cohere_key', 'bot_api_key', 'landing_portal_password_hash',
                     'crm_password_hash', 'hg_propio_token', 'hg_propio_webhook_secret'];
        foreach ($secretos as $sx) {
            if (!array_key_exists($sx, $c)) { continue; }
            $c[$sx . '_cargada'] = trim((string) $c[$sx]) !== '';
            unset($c[$sx]);
        }
        salida(['ok' => true, 'cliente' => $c]);

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
        if (!ruta_slug_valida($slug)) {
            salida(['ok' => false, 'error' => 'esa ruta no está disponible o coincide con una página del sistema'], 422);
        }
        $botKey = trim($in['bot_api_key'] ?? '') ?: bin2hex(random_bytes(24));
        // Nombre de la base del cliente: solo [a-z0-9_], porque va sin escapar
        // en un CREATE DATABASE del worker. gp_ de prefijo para no chocar con
        // otras bases del hosting.
        $dbNombre = 'gp_' . preg_replace('/[^a-z0-9]/', '_', $slug);
        $iaKey = trim((string) ($in['ia_key'] ?? ($in['cohere_key'] ?? '')));
        if ($iaKey === '') { $iaKey = null; }   // vacio = usa la clave del sistema

        // El producto Landing no entrega acceso a nuestro plano de control ni
        // al CRM. Solo recibe un usuario inicial para /replica/configurar.html;
        // desde ahí el comprador carga las credenciales de su panel Ganamos.
        $producto = (string)($in['producto'] ?? 'crm');
        if (!in_array($producto, ['crm','landing'], true)) {
            salida(['ok'=>false,'error'=>'producto inválido'],422);
        }
        $portalUser = trim((string)($in['landing_portal_usuario'] ?? ''));
        $portalPass = (string)($in['landing_portal_password'] ?? '');
        if ($producto === 'landing') {
            if (!preg_match('/^[a-zA-Z0-9_.-]{3,80}$/', $portalUser) || strlen($portalPass) < 10) {
                salida(['ok'=>false,'error'=>'Para el portal de landing, definí usuario y contraseña de al menos 10 caracteres.'],422);
            }
            try { $pdo->query('SELECT producto,landing_portal_usuario,landing_portal_password_hash FROM clientes LIMIT 0'); }
            catch (Throwable $e) {
                salida(['ok'=>false,'error'=>'Falta aplicar panel/sql/15_landing_autoservicio.sql antes de crear este producto.'],422);
            }
        }
        $portalHash = $producto === 'landing' ? password_hash($portalPass, PASSWORD_DEFAULT) : null;

        /* Acceso al CRM del cliente (migracion 08 del control): usuario + HASH
           de la clave. provisionar.php crea el operador admin en la base del
           cliente en la misma pasada que la crea -- asi el cliente nace
           PUDIENDO entrar a su CRM, sin un segundo viaje al boton Operadores.
           La clave en claro no se guarda nunca: se hashea aca y viaja hash. */
        $crmUser = trim((string) ($in['crm_usuario'] ?? ''));
        $crmPass = (string) ($in['crm_password'] ?? '');
        if ($producto === 'landing') { $crmUser = ''; $crmPass = ''; }
        if ($crmUser !== '' || $crmPass !== '') {
            if ($crmUser === '' || strlen($crmPass) < 6) {
                salida(['ok' => false, 'error' => 'acceso al CRM: usuario y contraseña (mínimo 6) van juntos'], 422);
            }
            if (!preg_match('/^[a-zA-Z0-9_.\-]{3,60}$/', $crmUser)) {
                salida(['ok' => false, 'error' => 'el usuario del CRM: solo letras, números, punto, guión (3-60)'], 422);
            }
        }
        $crmHash = $crmPass !== '' ? password_hash($crmPass, PASSWORD_DEFAULT) : null;
        // Sin la migracion 08 no hay donde guardarlo. Perderlo en silencio
        // seria el campo decorativo de nuevo: se avisa y no se crea nada.
        $hayCrmCols = true;
        try { $pdo->query('SELECT crm_usuario, crm_password_hash FROM clientes LIMIT 0'); }
        catch (Throwable $e) { $hayCrmCols = false; }
        if ($crmUser !== '' && !$hayCrmCols) {
            salida(['ok' => false, 'error' => 'falta la migración 08 del control (panel/sql/08_crm_operador.sql): '
                . 'corrella o creá el cliente sin acceso al CRM y usá después el botón Operadores'], 422);
        }

        // Todo tenant creado desde el panel debe dar de alta en SU agente y
        // en SU cola. Si la migración no existe, fallar cerrado: omitir este
        // dato haría que el bot use la cuenta global por compatibilidad.
        try { $pdo->query('SELECT altas_propias FROM clientes LIMIT 0'); }
        catch (Throwable $e) {
            salida(['ok' => false, 'error' => 'falta la migración 13 de altas aisladas (panel/sql/13_altas_propias.sql); no se creó el cliente'], 422);
        }

        $colsCrm = $hayCrmCols ? 'crm_usuario,crm_password_hash,' : '';
        $phCrm   = $hayCrmCols ? '?,?,' : '';
        $hayPortalCols = false;
        try { $pdo->query('SELECT producto,landing_portal_usuario,landing_portal_password_hash FROM clientes LIMIT 0'); $hayPortalCols = true; }
        catch (Throwable $e) {}
        $colsPortal = $hayPortalCols ? 'producto,landing_portal_usuario,landing_portal_password_hash,' : '';
        $phPortal = $hayPortalCols ? '?,?,?,' : '';
        try {
            $pdo->beginTransaction();
            $st = $pdo->prepare(
                'INSERT INTO clientes
                 (nombre,slug,ruta_slug,dominio,path_tenant,db_nombre,agente_usuario,agente_password,altas_propias,' . $colsPortal . $colsCrm . 'cobro_alias,cobro_cbu,
                  cobro_titular,coins_por_peso,' . col_ia($pdo) . ',bot_api_key,notas,suscripcion_estado,trial_hasta)
                 VALUES (?,?,?,?,?,?,?,?,1,' . $phPortal . $phCrm . '?,?,?,?,?,?,?,?,?)'
            );
            // Todo cliente nuevo arranca con 14 días de cortesía: el cron de
            // consumo (panel/consumo_diario.php) no le descuenta saldo ni lo
            // bloquea mientras siga en 'trial' y no haya pasado trial_hasta.
            $params = [
                $nombre, $slug, $slug, $dominio, $pathTenant, $dbNombre,
                $in['agente_usuario'] ?? null, $in['agente_password'] ?? null,
            ];
            if ($hayPortalCols) {
                $params[] = $producto;
                $params[] = $producto === 'landing' ? $portalUser : null;
                $params[] = $portalHash;
            }
            if ($hayCrmCols) {
                $params[] = $crmUser !== '' ? $crmUser : null;
                $params[] = $crmHash;
            }
            $st->execute(array_merge($params, [
                $in['cobro_alias'] ?? null, $in['cobro_cbu'] ?? null, $in['cobro_titular'] ?? null,
                (float) ($in['coins_por_peso'] ?? 1),
                // `ia_key`: la clave del proveedor de IA del chatbot (hoy Qwen).
                // Se acepta `cohere_key` como nombre viejo del MISMO campo para
                // no romper a un llamador que no se actualizo -- el nombre del
                // proveedor salio de la columna a proposito (migracion 07 del
                // control): este campo ya cambio de proveedor una vez.
                $iaKey, $botKey,
                $in['notas'] ?? null,
                'trial', date('Y-m-d', strtotime('+14 days')),
            ]));
            $idNuevo = (int)$pdo->lastInsertId();
            $pdo->prepare('INSERT INTO clientes_rutas_path (dominio,ruta_slug,cliente_id) VALUES (?,?,?)')
                ->execute([$dominio, $slug, $idNuevo]);
            $pdo->commit();
            // aprovisionado queda en 0 (default): el worker le crea la base en < 1 min.
            $url = $pathTenant ? ('https://' . $dominio . '/' . $slug . '/crm.html')
                                : ('https://' . $dominio . '/crm.html');
            $out = ['ok' => true, 'id' => $idNuevo, 'slug' => $slug, 'ruta_slug' => $slug,
                    'producto' => $producto, 'db_nombre' => $dbNombre];
            if ($producto === 'landing') {
                $out['portal_url'] = 'https://' . $dominio . '/replica/configurar.html';
                $out['portal_usuario'] = $portalUser;
                $out['portal_password'] = $portalPass; // se devuelve una sola vez; solo se guarda el hash.
            } else {
                $out['bot_api_key'] = $botKey;
                $out['url'] = $url;
            }
            salida($out);
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $dup = $e->getCode() === '23000';
            $msgDup = 'ya existe un cliente con ese dominio y slug';
            if ($dup && $producto === 'landing' && $portalUser !== '') {
                try {
                    $qDup = $pdo->prepare("SELECT 1 FROM clientes WHERE landing_portal_usuario=? LIMIT 1");
                    $qDup->execute([$portalUser]);
                    if ($qDup->fetchColumn()) { $msgDup = 'ya existe un acceso al portal con ese usuario'; }
                } catch (Throwable $ignored) {}
            }
            salida(['ok' => false, 'error' => $dup ? $msgDup : 'no se pudo crear'], $dup ? 409 : 500);
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
        $tipo = $pdo->prepare("SELECT producto FROM clientes WHERE id=?");
        $tipo->execute([$id]);
        if ($tipo->fetchColumn() === 'landing') salida(['ok'=>false,'error'=>'Este cliente no tiene CRM ni usuarios de CRM; solo administra su portal de landing.'],422);
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
        $tipo = $pdo->prepare("SELECT producto FROM clientes WHERE id=?");
        $tipo->execute([$id]);
        if ($tipo->fetchColumn() === 'landing') salida(['ok'=>false,'error'=>'Este cliente compró solo Landing y no tiene acceso al CRM.'],422);
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

    case 'landing_ver':
        salida(['ok' => false, 'error' => 'La landing de cada cliente se configura desde su propio CRM o desde /replica/configurar.html.'], 410);

    case 'landing_guardar': {
        // Esta acción queda solo para la landing de nuestro propio tenant.
        $id        = (int) ($in['id'] ?? 0);
        $idLandingIn = (int) ($in['landing_id'] ?? 0);
        $nombreIn  = trim((string) ($in['nombre'] ?? ''));
        $whatsapp  = trim((string) ($in['whatsapp'] ?? ''));
        $waTexto   = trim((string) ($in['wa_texto'] ?? ''));
        if ($id > 0) {
            salida(['ok' => false, 'error' => 'No configures la landing de un cliente desde el panel administrador. El cliente la administra desde su CRM o desde /replica/configurar.html.'], 410);
        }
        /* AUSENTE NO ES CERO. La pantalla de Landings no muestra el bono, así
           que no lo manda; si acá se leyera como 0, guardar un cambio de
           WhatsApp le apagaría el bono a una landing que lo tenía — sin que
           nadie lo pida y sin que se vea en ninguna parte. Ausente = dejalo
           como está (y 0 al crear, que es el default razonable). */
        $bonoDado  = array_key_exists('bono_pct', $in);
        $bonoPct   = max(0, min(200, (int) ($in['bono_pct'] ?? 0)));
        if ($id <= 0 && $idLandingIn <= 0 && $nombreIn === '') {
            salida(['ok' => false, 'error' => 'ponele un nombre para reconocerla'], 422);
        }
        if ($whatsapp === '') { salida(['ok' => false, 'error' => 'poné el WhatsApp'], 422); }

        require_once __DIR__ . '/../api/landings_lib.php';

        /* QUE EL api/ DESPLEGADO CONOZCA LA PLANTILLA. landings_guardar() cae
           a 'oro' cuando no la reconoce, en silencio: con un api/ viejo la
           landing se crearía igual, publicada y linda, y no derivaría a nadie
           -- el fallo más caro posible, porque todo parece haber salido bien.
           Pasa si el panel se desplegó y api/ no. */
        if (!isset(landings_plantillas()['wa'])) {
            salida(['ok' => false, 'error' => 'el api/ desplegado no conoce la plantilla "wa": '
                                            . 'actualizá /var/www/api (git pull + deploy.sh)'], 500);
        }

        /* SE VALIDA ANTES DE ESCRIBIR NADA. Acá sí es un error y no un aviso
           (a diferencia del CRM, donde la landing ya existe y se está
           retocando): una landing de cajero SIN WhatsApp que funcione no
           tiene ningún sentido -- es lo único que la distingue de una común,
           y publicarla igual sería entregarle al cliente un link que manda
           sus jugadores a nuestro casino sin avisarle a nadie. */
        if (landings_wa_numero($whatsapp) === '') {
            salida(['ok' => false, 'error' => 'ese WhatsApp no sirve para un link: escribilo con '
                                            . 'el código de país y el + adelante, así: +54 9 11 2345-6789'], 422);
        }

        [$db, $comoSale, $err] = landings_db_propia($pdo, $cfg);
        if ($err !== '') { salida(['ok' => false, 'error' => $err], 500); }

        $c = null;
        if ($id > 0) {
            try {
                $st = $pdo->prepare('SELECT nombre, slug, landing_id FROM clientes WHERE id = ?');
                $st->execute([$id]);
            } catch (PDOException $e) {
                salida(['ok' => false, 'error' => 'falta la migración 14 del control '
                                                . '(panel/sql/14_landing_cajero.sql)'], 500);
            }
            $c = $st->fetch();
            if (!$c) { salida(['ok' => false, 'error' => 'cliente no existe'], 404); }
        }

        try {
            $cpdo = conectar_cliente($cfg, $db);

            /* El id guardado puede estar muerto (borrada desde el CRM). Se
               verifica antes de pasarlo: landings_guardar() con un id que no
               existe haría un UPDATE de 0 filas y devolvería "guardado" sin
               que exista ninguna landing. */
            $idLanding = $c ? (int) ($c['landing_id'] ?? 0) : $idLandingIn;
            if ($idLanding > 0) {
                $q = $cpdo->prepare('SELECT id FROM landings WHERE id = ?');
                $q->execute([$idLanding]);
                if (!$q->fetchColumn()) {
                    /* NO ES LO MISMO SEGÚN DE DÓNDE VINO EL id, aunque el
                       síntoma sea idéntico (la landing no está):

                       del CONTROL es un puntero viejo -- alguien la borró
                       desde el CRM y la fila del cliente quedó apuntando a la
                       nada. Ahí rehacerla es exactamente lo que se quiere.

                       del REQUEST es el operador editando una landing
                       concreta que, mientras tanto, alguien borró. Rehacerla
                       en silencio le devolvería "guardado" sobre algo que no
                       es lo que estaba editando. Se le dice. */
                    if (!$c) {
                        salida(['ok' => false, 'error' => 'esa landing ya no existe '
                                                        . '(¿la borraron desde otra pantalla?)'], 409);
                    }
                    $idLanding = 0;
                }
            }

            /* Se guarda SOLO lo que elegimos, igual que lp_config_sanear() desde
               el CRM: los defaults de la plantilla se aplican al leer. Guardar
               la config entera congelaría los textos de hoy en cada landing. */
            $config = ['whatsapp' => ['numero' => $whatsapp]];
            if ($waTexto !== '') { $config['whatsapp']['texto'] = $waTexto; }
            /* El nombre de una suelta lo pone el operador (el del que la
               compró). Editando una suelta sin tocarlo, se conserva el que
               ya tenía: mandar vacío no puede renombrarla a "". */
            if ($c) {
                $nombre = 'Cajero: ' . $c['nombre'];
            } elseif ($nombreIn !== '') {
                $nombre = $nombreIn;
            } else {
                $q = $cpdo->prepare('SELECT nombre FROM landings WHERE id = ?');
                $q->execute([$idLanding]);
                $nombre = (string) ($q->fetchColumn() ?: 'Landing');
            }
            if (!$bonoDado && $idLanding > 0) {
                $q = $cpdo->prepare('SELECT bono_pct FROM landings WHERE id = ?');
                $q->execute([$idLanding]);
                $bonoPct = (int) ($q->fetchColumn() ?: 0);
            }
            $r = landings_guardar($cpdo, $idLanding ?: null, $nombre, 'wa', $bonoPct, $config);
            if (!$r) {
                salida(['ok' => false, 'error' => 'no se pudo guardar en ' . $db
                                                . ' (¿corrió la migración 52 en esa base?)'], 500);
            }
        } catch (Throwable $e) {
            salida(['ok' => false, 'error' => 'no pude escribir la landing en ' . $db], 500);
        }

        /* Solo las de un cliente se anotan en el control. Una suelta no tiene
           dónde anotarse -- ni lo necesita: vive sola en nuestra base y el
           panel la lista desde ahí. */
        if ($id > 0) {
            $pdo->prepare('UPDATE clientes SET landing_id = ?, landing_slug = ? WHERE id = ?')
                ->execute([$r['id'], $r['slug'], $id]);
        }

        salida(['ok' => true, 'slug' => $r['slug'], 'url' => landing_url($cfg, $r['slug']),
                'base' => $db, 'base_como' => $comoSale]);
    }

    /* Todas las landings de cajero de nuestra base, con el cliente dueño de
       cada una si lo tiene. Las SUELTAS --las que se venden por separado--
       son las que no aparecen en esa lista: no es un estado distinto, es
       simplemente que nadie las reclama. */
    case 'landing_listar': {
        [$db, $comoSale, $err] = landings_db_propia($pdo, $cfg);
        if ($err !== '') { salida(['ok' => false, 'error' => $err], 500); }

        require_once __DIR__ . '/../api/landings_lib.php';

        // Quién es dueño de qué, del lado del control.
        $duenos = [];
        try {
            foreach ($pdo->query('SELECT id, nombre, landing_id FROM clientes WHERE landing_id IS NOT NULL') as $f) {
                $duenos[(int) $f['landing_id']] = ['id' => (int) $f['id'], 'nombre' => $f['nombre']];
            }
        } catch (PDOException $e) {
            /* Sin la migración 14 no hay dueños que mostrar, pero las
               landings existen igual: se listan sin atribuir en vez de
               dejar la pantalla vacía. */
        }

        $out = [];
        try {
            $cpdo = conectar_cliente($cfg, $db);
            foreach (landings_listar($cpdo) as $l) {
                // El panel de control solo administra landings de Ganamos.
                // Las landings ya vinculadas a un cliente quedan fuera de esta
                // lista; cada cliente las gestiona desde su propio espacio.
                if (isset($duenos[(int)$l['id']])) { continue; }
                if ((string) $l['plantilla'] !== 'wa') { continue; }   // las de promo son del CRM
                $cfgL = landings_config_completa('wa', $l['config']);
                $out[] = [
                    'id'       => (int) $l['id'],
                    'slug'     => (string) $l['slug'],
                    'nombre'   => (string) $l['nombre'],
                    'activa'   => (int) $l['activa'],
                    'bono_pct' => (int) $l['bono_pct'],
                    'whatsapp' => (string) ($cfgL['whatsapp']['numero'] ?? ''),
                    'wa_texto' => (string) ($cfgL['whatsapp']['texto'] ?? ''),
                    'url'      => landing_url($cfg, (string) $l['slug']),
                    'cliente'  => $duenos[(int) $l['id']] ?? null,
                ];
            }
        } catch (Throwable $e) {
            salida(['ok' => false, 'error' => 'no pude leer las landings en ' . $db], 500);
        }
        salida(['ok' => true, 'base' => $db, 'base_como' => $comoSale, 'landings' => $out]);
    }

    case 'landing_estado': {
        $idL = (int) ($in['landing_id'] ?? 0);
        if ($idL <= 0) { salida(['ok' => false, 'error' => 'falta la landing'], 422); }
        [$db, , $err] = landings_db_propia($pdo, $cfg);
        if ($err !== '') { salida(['ok' => false, 'error' => $err], 500); }
        require_once __DIR__ . '/../api/landings_lib.php';
        try {
            $nuevo = landings_toggle(conectar_cliente($cfg, $db), $idL);
        } catch (Throwable $e) {
            salida(['ok' => false, 'error' => 'no pude cambiarle el estado'], 500);
        }
        if ($nuevo === null) { salida(['ok' => false, 'error' => 'esa landing no existe'], 404); }
        salida(['ok' => true, 'activa' => $nuevo ? 1 : 0]);
    }

    case 'landing_borrar': {
        $idL = (int) ($in['landing_id'] ?? 0);
        if ($idL <= 0) { salida(['ok' => false, 'error' => 'falta la landing'], 422); }
        [$db, , $err] = landings_db_propia($pdo, $cfg);
        if ($err !== '') { salida(['ok' => false, 'error' => $err], 500); }
        require_once __DIR__ . '/../api/landings_lib.php';
        try {
            $cpdo = conectar_cliente($cfg, $db);
            /* landings_borrar() se niega sola si la landing ya trajo
               registros: borrarla dejaría esos jugadores sin dueño en
               Publicidad. El mensaje que devuelve ya explica que se pausa
               en vez de borrarse, así que se pasa tal cual. */
            $r = landings_borrar($cpdo, $idL);
        } catch (Throwable $e) {
            salida(['ok' => false, 'error' => 'no pude borrarla'], 500);
        }
        if (empty($r['ok'])) { salida(['ok' => false, 'error' => $r['error'] ?? 'no se pudo'], 409); }

        /* Y SE SUELTA EL VÍNCULO DEL CONTROL. Sin esto, el botón de la fila
           de ese cliente seguiría apuntando a una landing que ya no existe. */
        try {
            $pdo->prepare('UPDATE clientes SET landing_id = NULL, landing_slug = NULL WHERE landing_id = ?')
                ->execute([$idL]);
        } catch (PDOException $e) { /* sin migración 14 no hay vínculo que soltar */ }
        salida(['ok' => true]);
    }

    /* ===================================================================
     * CRÉDITOS PREPAGOS (migración 11). El modelo con el que se vende el
     * servicio: el cajero carga créditos en pesos con USDT y se le descuenta
     * un % de cada carga que procesa.
     *
     * La billetera y la cotización son de LA PLATAFORMA, no de un cliente:
     * viven en config_plataforma, igual que el token de MercadoPago.
     * =================================================================== */
    case 'cred_config_ver': {
        $out = [];
        foreach (['usdt_wallet', 'usdt_red', 'usdt_cotizacion_ars', 'usdt_min_confirmaciones'] as $k) {
            $st = $pdo->prepare('SELECT valor FROM config_plataforma WHERE clave = ?');
            $st->execute([$k]);
            $out[$k] = (string) ($st->fetchColumn() ?: '');
        }
        salida(['ok' => true, 'config' => $out]);
    }

    case 'cred_config_guardar': {
        $wallet = trim((string) ($in['usdt_wallet'] ?? ''));
        $red    = trim((string) ($in['usdt_red'] ?? 'TRC20'));
        $cotiz  = (float) ($in['usdt_cotizacion_ars'] ?? 0);
        $conf   = (int) ($in['usdt_min_confirmaciones'] ?? 19);

        /* UNA DIRECCIÓN MAL COPIADA MANDA LA PLATA DEL CLIENTE A LA NADA, sin
           vuelta atrás. Se valida la forma acá porque es el único momento en
           que alguien puede corregirla: después, lo que hay es una
           transferencia perdida y un cliente que la pagó. */
        if ($wallet !== '' && !preg_match('/^T[1-9A-HJ-NP-Za-km-z]{33}$/', $wallet)) {
            salida(['ok' => false, 'error' => 'Esa no parece una dirección de Tron (TRC20). '
                  . 'Empiezan con T y tienen 34 caracteres.'], 422);
        }
        if ($cotiz < 0) { salida(['ok' => false, 'error' => 'la cotización no puede ser negativa'], 422); }
        if ($conf < 1)  { $conf = 19; }

        $up = $pdo->prepare(
            'INSERT INTO config_plataforma (clave, valor) VALUES (?,?)
             ON DUPLICATE KEY UPDATE valor = VALUES(valor)'
        );
        $up->execute(['usdt_wallet', $wallet]);
        $up->execute(['usdt_red', $red !== '' ? $red : 'TRC20']);
        $up->execute(['usdt_cotizacion_ars', (string) $cotiz]);
        $up->execute(['usdt_min_confirmaciones', (string) $conf]);
        salida(['ok' => true]);
    }

    case 'cred_cliente_guardar': {
        // El modelo de cobro y los números de ESTE cliente.
        $id     = (int) ($in['id'] ?? 0);
        $modelo = (string) ($in['cobro_modelo'] ?? '');
        if ($id <= 0 || !in_array($modelo, ['suscripcion', 'transaccion'], true)) {
            salida(['ok' => false, 'error' => 'faltan id o cobro_modelo'], 422);
        }
        $pct    = (float) ($in['comision_pct'] ?? 2);
        $umbral = (float) ($in['aviso_umbral_ars'] ?? 0);
        if ($pct <= 0 || $pct > 100) { salida(['ok' => false, 'error' => 'el % tiene que estar entre 0 y 100'], 422); }

        /* AL PASAR A «TRANSACCIÓN» SE FIJA `creditos_desde` EN AHORA, si no
           tenía. Sin ese corte, el primer cron le cobraría de una todas las
           cargas viejas que encuentre en la ventana -- a un cliente con
           movimiento eso le vacía el saldo en la primera pasada, y la primera
           impresión del producto sería un robo. */
        $sql = 'UPDATE clientes SET cobro_modelo = ?, comision_pct = ?, aviso_umbral_ars = ?';
        $par = [$modelo, $pct, $umbral];
        if ($modelo === 'transaccion') {
            $sql .= ', creditos_desde = COALESCE(creditos_desde, NOW())';
        }
        $sql .= ' WHERE id = ?';
        $par[] = $id;

        try {
            $st = $pdo->prepare($sql);
            $st->execute($par);
            salida(['ok' => true]);
        } catch (PDOException $e) {
            salida(['ok' => false, 'error' => 'no se pudo guardar (¿corriste la migración 11?)'], 500);
        }
    }

    case 'cred_ajustar': {
        /* Cargarle o descontarle créditos a mano: es el respaldo de cuando el
           cliente transfirió por un camino que el verificador no puede leer
           (otra red, un exchange que no da hash), y la forma de devolverle
           algo mal cobrado. Queda auditado como cualquier otro ajuste. */
        $id     = (int) ($in['id'] ?? 0);
        $delta  = (float) ($in['delta_ars'] ?? 0);
        $motivo = trim((string) ($in['motivo'] ?? ''));
        if ($id <= 0 || $delta == 0.0) {
            salida(['ok' => false, 'error' => 'faltan id o delta_ars (no puede ser 0)'], 422);
        }
        if ($motivo === '') {
            // Un ajuste sin motivo es un número que en un mes no se puede explicar.
            salida(['ok' => false, 'error' => 'poné el motivo del ajuste'], 422);
        }
        try {
            $pdo->beginTransaction();
            $st = $pdo->prepare(
                "UPDATE clientes SET creditos_ars = creditos_ars + ?,
                        suscripcion_estado = IF(creditos_ars + ? > 0 AND suscripcion_estado = 'sin_saldo',
                                                'activa', suscripcion_estado)
                  WHERE id = ?"
            );
            $st->execute([$delta, $delta, $id]);
            if ($st->rowCount() < 1) {
                $pdo->rollBack();
                salida(['ok' => false, 'error' => 'cliente no existe'], 404);
            }
            $pdo->prepare(
                'INSERT INTO ajustes_saldo_plataforma (cliente_id, delta_usd, motivo, operador) VALUES (?,?,?,?)'
            )->execute([$id, 0, 'créditos ARS ' . ($delta > 0 ? '+' : '') . $delta . ': ' . $motivo, $oper]);
            $pdo->commit();

            $q = $pdo->prepare('SELECT creditos_ars FROM clientes WHERE id = ?');
            $q->execute([$id]);
            salida(['ok' => true, 'saldo' => (float) $q->fetchColumn()]);
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
            salida(['ok' => false, 'error' => 'no se pudo ajustar (¿corriste la migración 11?)'], 500);
        }
    }

    case 'cred_resumen': {
        /* Lo que factura el negocio por este modelo: cuánto se cobró, a
           quiénes, y quién se está quedando sin créditos. */
        try {
            $filas = $pdo->query(
                "SELECT c.id, c.slug, c.nombre, c.creditos_ars, c.comision_pct,
                        c.aviso_umbral_ars, c.suscripcion_estado, c.creditos_desde,
                        COALESCE(SUM(k.comision_ars), 0) AS cobrado_30d,
                        COUNT(k.id) AS cargas_30d
                   FROM clientes c
                   LEFT JOIN consumos_plataforma k
                          ON k.cliente_id = c.id AND k.cobrado_en >= NOW() - INTERVAL 30 DAY
                  WHERE c.estado = 'activo' AND c.cobro_modelo = 'transaccion'
                  GROUP BY c.id ORDER BY cobrado_30d DESC"
            )->fetchAll();
            $tot = 0.0;
            foreach ($filas as $f) { $tot += (float) $f['cobrado_30d']; }
            salida(['ok' => true, 'clientes' => $filas, 'total_30d' => $tot]);
        } catch (PDOException $e) {
            salida(['ok' => true, 'clientes' => [], 'total_30d' => 0, 'sin_migracion' => true]);
        }
    }

    case 'editar': {
        // Edita los datos de un cliente ya creado.
        //
        // `slug` and `db_nombre` stay fixed internal identifiers. Only the
        // public path may change; its previous value remains an alias.
        $id = (int) ($in['id'] ?? 0);
        if ($id <= 0) salida(['ok' => false, 'error' => 'falta el id'], 422);

        $nombre = trim((string) ($in['nombre'] ?? ''));
        if ($nombre === '') salida(['ok' => false, 'error' => 'el nombre es obligatorio'], 422);

        // Solo estos campos son editables. Lo que no este en la lista no se
        // toca, aunque venga en el body.
        $campos = [
            'nombre' => $nombre,
            'notas'  => $in['notas'] ?? null,
        ];
        /* Credenciales del agente, cuenta de cobro y coins_por_peso: desde el
           15/09/2026 los gestiona EL CLIENTE desde su CRM y este panel ya no
           los manda. Se pisan SOLO si vienen en el request (un llamador viejo
           o un script): antes se seteaban siempre, y con el formulario nuevo
           sin esos campos cada "Guardar" hubiera borrado en silencio lo que
           el cliente cargo en su CRM -- su usuario de agente en null, su
           cuenta de cobro en null y su precio de vuelta a 1. */
        foreach (['agente_usuario', 'cobro_alias', 'cobro_cbu', 'cobro_titular'] as $cx) {
            if (array_key_exists($cx, $in)) { $campos[$cx] = $in[$cx]; }
        }
        if (array_key_exists('coins_por_peso', $in)) {
            $campos['coins_por_peso'] = (float) $in['coins_por_peso'];
        }
        if (array_key_exists('altas_propias', $in)) {
            $campos['altas_propias'] = !empty($in['altas_propias']) ? 1 : 0;
        }

        // Las claves solo se pisan si mandaron una nueva: el formulario las
        // muestra vacias (nunca se devuelven), y un vacio ahi significa
        // "dejala como esta", no "borrala".
        $agPass = (string) ($in['agente_password'] ?? '');
        if ($agPass !== '') $campos['agente_password'] = $agPass;
        // Idem en la edicion: nombre nuevo, y el viejo se sigue aceptando.
        $iaKey = trim((string) ($in['ia_key'] ?? ($in['cohere_key'] ?? '')));
        if ($iaKey !== '') $campos[col_ia($pdo)] = $iaKey;

        $sets = [];
        $vals = [];
        foreach ($campos as $col => $val) {
            $sets[] = "$col = ?";
            $vals[] = ($val === '') ? null : $val;
        }
        $vals[] = $id;

        try {
            $pdo->beginTransaction();
            $qCli = $pdo->prepare('SELECT id,dominio,path_tenant,slug,ruta_slug,agente_usuario,agente_password FROM clientes WHERE id=? FOR UPDATE');
            $qCli->execute([$id]);
            $cli = $qCli->fetch();
            if (!$cli) { $pdo->rollBack(); salida(['ok'=>false,'error'=>'cliente no existe'],404); }
            if (array_key_exists('altas_propias', $in) && !empty($in['altas_propias'])) {
                if ((int)$cli['path_tenant'] !== 1) {
                    $pdo->rollBack();
                    salida(['ok'=>false,'error'=>'las altas propias requieren una ruta de cliente'],422);
                }
                if (trim((string)$cli['agente_usuario']) === '' || trim((string)$cli['agente_password']) === '') {
                    $pdo->rollBack();
                    salida(['ok'=>false,'error'=>'el cliente debe guardar primero sus credenciales de agente'],422);
                }
            }
            if (array_key_exists('ruta_slug', $in)) {
                if ((int)$cli['path_tenant'] !== 1) {
                    $pdo->rollBack();
                    salida(['ok'=>false,'error'=>'la ruta se edita solo en clientes por ruta'],422);
                }
                $rutaNueva = strtolower(trim((string)$in['ruta_slug']));
                if (!ruta_slug_valida($rutaNueva)) {
                    $pdo->rollBack();
                    salida(['ok'=>false,'error'=>'ruta inválida o reservada por el sistema'],422);
                }
                $rutaVieja = (string)($cli['ruta_slug'] ?: $cli['slug']);
                if ($rutaNueva !== $rutaVieja) {
                    $qRuta = $pdo->prepare('SELECT cliente_id FROM clientes_rutas_path WHERE dominio=? AND ruta_slug=?');
                    $qRuta->execute([$cli['dominio'],$rutaNueva]);
                    $duenoRuta = $qRuta->fetchColumn();
                    if ($duenoRuta && (int)$duenoRuta !== $id) {
                        $pdo->rollBack();
                        salida(['ok'=>false,'error'=>'esa ruta ya está asignada o reservada por otro cliente'],409);
                    }
                    // Conservar tanto la ruta actual como el slug anterior
                    // evita perder accesos y hace que ambos resuelvan al mismo id.
                    $pdo->prepare('INSERT IGNORE INTO clientes_rutas_path (dominio,ruta_slug,cliente_id) VALUES (?,?,?)')
                        ->execute([$cli['dominio'],$rutaVieja,$id]);
                    $pdo->prepare('INSERT IGNORE INTO clientes_rutas_path (dominio,ruta_slug,cliente_id) VALUES (?,?,?)')
                        ->execute([$cli['dominio'],$rutaNueva,$id]);
                    $pdo->prepare('UPDATE clientes SET ruta_slug=? WHERE id=?')->execute([$rutaNueva,$id]);
                }
            }
            $st = $pdo->prepare('UPDATE clientes SET ' . implode(', ', $sets) . ' WHERE id = ?');
            $st->execute($vals);
            // rowCount 0 tambien pasa si guardaron sin cambiar nada, asi que
            // no alcanza para decir "no existe": se chequea aparte.
            $ex = $pdo->prepare('SELECT 1 FROM clientes WHERE id = ?');
            $ex->execute([$id]);
            if (!$ex->fetchColumn()) { $pdo->rollBack(); salida(['ok' => false, 'error' => 'cliente no existe'], 404); }
            $pdo->commit();
            salida(['ok' => true, 'ruta_slug' => $rutaNueva ?? ($cli['ruta_slug'] ?: $cli['slug'])]);
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $dup = $e->getCode() === '23000';
            salida(['ok' => false, 'error' => $dup ? 'esa ruta ya está asignada a este u otro cliente (también puede ser una ruta anterior reservada)' : 'no se pudo guardar'], $dup ? 409 : 500);
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
            $pdo->beginTransaction();
            $pdo->prepare('DELETE FROM clientes_rutas_path WHERE cliente_id=?')->execute([$id]);
            $pdo->prepare('DELETE FROM clientes WHERE id = ?')->execute([$id]);
            $pdo->commit();
            // La base del cliente queda en el servidor, a proposito. Se informa
            // para que quien la quiera eliminar sepa cual es.
            salida(['ok' => true, 'modo' => 'purgado', 'db_huerfana' => $cli['db_nombre']]);
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
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

    case 'salud':
        /* Lo que dejo la pasada 3 de provisionar.php (que corre cada minuto).
           Se lee el archivo en vez de recalcular: asi el panel no tiene que
           abrir la base de cada cliente en cada request, y ademas la FECHA del
           archivo delata si el cron dejo de correr -- que es justamente una de
           las fallas silenciosas que esto viene a destapar. */
        $f = '/var/lib/goldpaw/salud.json';
        if (!is_file($f)) {
            salida(['ok' => true, 'sin_datos' => true,
                    'nota' => 'Todavía no corrió el chequeo. Revisá que el cron de provisionar.php esté activo.']);
        }
        $j = json_decode((string) file_get_contents($f), true);
        $revisado = strtotime((string) ($j['revisado_en'] ?? '')) ?: 0;
        $minutos  = $revisado ? (int) round((time() - $revisado) / 60) : null;
        salida([
            'ok'          => true,
            'revisado_en' => $j['revisado_en'] ?? null,
            'hace_min'    => $minutos,
            // Mas de 10 min sin correr con un cron de 1 min = el cron murio.
            'cron_caido'  => $minutos !== null && $minutos > 10,
            'problemas'   => $j['problemas'] ?? [],
        ]);

    default:
        salida(['ok' => false, 'error' => 'acción desconocida'], 400);
}
