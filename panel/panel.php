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
           activo TINYINT(1) NOT NULL DEFAULT 1,
           ultimo_login DATETIME DEFAULT NULL,
           creado TIMESTAMP DEFAULT CURRENT_TIMESTAMP
         ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
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
            'SELECT id,nombre,slug,dominio,path_tenant,cobro_alias,coins_por_peso,estado,creado
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
                  cobro_titular,coins_por_peso,cohere_key,bot_api_key,notas)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
            );
            $st->execute([
                $nombre, $slug, $dominio, $pathTenant, $dbNombre,
                $in['agente_usuario'] ?? null, $in['agente_password'] ?? null,
                $in['cobro_alias'] ?? null, $in['cobro_cbu'] ?? null, $in['cobro_titular'] ?? null,
                (float) ($in['coins_por_peso'] ?? 1),
                $in['cohere_key'] ?? null, $botKey,
                $in['notas'] ?? null,
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
            $ops = $cpdo->query('SELECT id,username,activo,ultimo_login FROM operadores ORDER BY username')->fetchAll();
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
                $cpdo->prepare('UPDATE operadores SET password_hash = ?, activo = 1 WHERE username = ?')
                     ->execute([$hash, $usr]);
                salida(['ok' => true, 'usuario' => $usr, 'accion' => 'actualizado']);
            }
            $cpdo->prepare('INSERT INTO operadores (username,password_hash,activo) VALUES (?,?,1)')
                 ->execute([$usr, $hash]);
            salida(['ok' => true, 'usuario' => $usr, 'accion' => 'creado']);
        } catch (PDOException $e) {
            salida(['ok' => false, 'error' => 'no se pudo crear el operador'], 500);
        }
    }

    default:
        salida(['ok' => false, 'error' => 'acción desconocida'], 400);
}
