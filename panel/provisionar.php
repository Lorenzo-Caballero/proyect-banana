<?php
/**
 * Provisionador de clientes. Lo corre ROOT (por cron), NUNCA el panel.
 *
 * Dos pasadas en cada corrida:
 *   1) Clientes nuevos (aprovisionado=0): crea su base desde la plantilla y su
 *      subdominio en Cloudflare.
 *   2) Bots: para cada cliente activo con credenciales de agente, se asegura de
 *      que su contenedor de bot esté corriendo (1 por cliente, con SUS
 *      credenciales, escribiendo en SU base vía su dominio).
 *
 * Corre como root: conecta a MySQL por socket (CREATE DATABASE/GRANT) y maneja
 * Docker. El panel (www-data) nunca tiene ese poder. Mismo patrón que el worker
 * de acciones_saldo.
 *
 * Cron:
 *   * * * * *  cd /var/www/panel && php provisionar.php >> /var/log/gp-provisionar.log 2>&1
 *
 * Requisitos en el VPS:
 *   - /root/plantilla_esquema.sql  (esquema, solo estructura; sin --databases)
 *   - imagen docker  ganamos-bot:latest  (se construye en ~/Bot-python)
 */

$cfg = require __DIR__ . '/panel_config.php';

$PLANTILLA = '/root/plantilla_esquema.sql';

// Carpeta de migraciones versionadas (api/sql/). Por defecto la que sirve la
// API en el VPS (symlink a /opt/goldpaw/api/sql). Se puede pisar en
// panel_config.php con 'SQL_DIR' si tu layout es otro.
$SQL_DIR = $cfg['SQL_DIR'] ?? '/var/www/api/sql';

if (!is_file($PLANTILLA)) {
    fwrite(STDERR, "[" . date('c') . "] falta la plantilla $PLANTILLA\n");
    exit(1);
}

try {
    $pdo = new PDO(
        "mysql:host={$cfg['DB_HOST']};dbname={$cfg['DB_NAME']};charset=utf8mb4",
        $cfg['DB_USER'], $cfg['DB_PASS'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );
} catch (PDOException $e) {
    fwrite(STDERR, "[" . date('c') . "] no pude conectar a la maestra\n");
    exit(1);
}

function marcar($pdo, $id, $ok, $detalle) {
    $st = $pdo->prepare('UPDATE clientes SET aprovisionado = ?, aprov_detalle = ? WHERE id = ?');
    $st->execute([$ok ? 1 : 0, substr($detalle, 0, 255), $id]);
}

// Migraciones LEGACY que NO hay que correr: la 01 y la 02 crean la tabla
// `jugadores`, que la migración 07 borró y unificó en `usuarios` (ver
// CLAUDE.md). Correrlas recrearía esquema muerto. La plantilla base ya trae
// el esquema actual completo; estas quedan solo por historia en el repo.
const MIGRACIONES_LEGACY = ['01_migracion.sql', '02_recargas.sql'];

/**
 * Corre las migraciones de $SQL_DIR (api/sql/*.sql) contra una base, en orden
 * numérico, salteando las legacy. Son idempotentes (CREATE TABLE IF NOT
 * EXISTS / ADD COLUMN IF NOT EXISTS): sobre una base que ya salió de la
 * plantilla al día no hacen nada; sobre una vieja, la ponen al día. Red de
 * seguridad — la fuente principal del esquema es la plantilla. Best-effort:
 * si una falla no corta el resto, solo lo reporta. '' si no había nada.
 */
function aplicar_migraciones($db) {
    global $SQL_DIR;
    if (!is_dir($SQL_DIR)) { return 'sin carpeta ' . $SQL_DIR; }

    $files = glob(rtrim($SQL_DIR, '/') . '/*.sql');
    if (!$files) { return ''; }
    // Orden por el prefijo numérico (01_, 02_, ... 26_), no alfabético crudo.
    natsort($files);

    $okN = 0; $fail = [];
    foreach ($files as $f) {
        if (in_array(basename($f), MIGRACIONES_LEGACY, true)) { continue; }
        exec('mariadb ' . escapeshellarg($db) . ' < ' . escapeshellarg($f) . ' 2>&1', $out, $rc);
        if ($rc === 0) { $okN++; }
        else { $fail[] = basename($f) . ': ' . implode(' ', array_slice($out, 0, 2)); }
        $out = [];
    }
    if ($fail) { return $okN . ' ok, ' . count($fail) . ' con error -> ' . implode(' | ', $fail); }
    return $okN . ' aplicadas ok';
}

/**
 * Huella del CONTENIDO de api/sql/. Cambia solo cuando se agrega o edita una
 * migracion -- no con cada deploy (por eso el hash del contenido y no el
 * mtime, que el rsync pisa siempre).
 */
function migraciones_huella() {
    global $SQL_DIR;
    if (!is_dir($SQL_DIR)) { return ''; }
    $files = glob(rtrim($SQL_DIR, '/') . '/*.sql');
    if (!$files) { return ''; }
    natsort($files);
    $partes = [];
    foreach ($files as $f) {
        if (in_array(basename($f), MIGRACIONES_LEGACY, true)) { continue; }
        $partes[] = basename($f) . ':' . md5_file($f);
    }
    return md5(implode('|', $partes));
}

/**
 * Que huella de migraciones tiene aplicada esta base. En un archivo y no en
 * una columna a proposito: agregar una columna a `clientes` necesitaria una
 * migracion a mano en goldpaw_control, y esto tiene que funcionar SIN que
 * nadie corra nada -- es justamente el problema que viene a resolver.
 */
/* 0755 y no 0700: acá adentro se deja `salud.json`, que lo LEE panel.php --
   otro proceso y otro usuario. provisionar.php corre como root por cron, asi
   que con 0700 el directorio quedaba drwx------ root:root y www-data no podia
   ni entrar. El archivo en si es 0644, pero eso no alcanza: sin permiso de
   traverse en el directorio, is_file() da false.

   El sintoma era el peor posible para un panel de salud: mostraba "Todavia no
   corrio el chequeo. Revisa que el cron de provisionar.php este activo" con el
   cron corriendo cada minuto y el archivo escrito hacia 40 segundos. O sea que
   justo cuando algo andaba mal, mandaba a revisar el lugar equivocado.

   Acá no hay secretos -- hashes de migraciones y una lista de problemas por
   slug -- asi que 0755 no expone nada que el archivo 0644 no expusiera ya. */
function huella_dir() {
    $d = '/var/lib/goldpaw';
    if (!is_dir($d)) { @mkdir($d, 0755, true); }
    // Repara el permiso de las instalaciones que ya lo crearon con 0700.
    // Barato e idempotente: si ya esta bien, chmod no hace nada.
    if (is_dir($d) && (fileperms($d) & 0077) === 0) { @chmod($d, 0755); }
    return is_dir($d) ? $d : sys_get_temp_dir();
}
function huella_leer($db)      { $f = huella_dir() . '/mig-' . $db . '.hash';
                                 return is_file($f) ? trim((string) @file_get_contents($f)) : ''; }
function huella_guardar($db, $h) { @file_put_contents(huella_dir() . '/mig-' . $db . '.hash', $h); }

/**
 * Crea (o confirma) el A record del dominio en Cloudflare, proxied. Best-effort.
 * No aplica a clientes por-path (path_tenant=1): comparten el dominio del
 * operador, que ya tiene su propio A record. Ver asegurar_bot() y la pasada 1.
 */
function cf_dns_upsert($dominio, $cfg) {
    $token = $cfg['CF_API_TOKEN'] ?? '';
    $zone  = $cfg['CF_ZONE_ID'] ?? '';
    $ip    = $cfg['VPS_IP'] ?? '';
    $zname = $cfg['CF_ZONE_NAME'] ?? '';

    if ($token === '' || $zone === '' || $ip === '') {
        return [true, 'DNS sin configurar (skip)'];
    }
    if ($zname !== '' && $dominio !== $zname && substr($dominio, -strlen('.' . $zname)) !== '.' . $zname) {
        return [true, 'dominio propio del cliente (DNS lo pone él)'];
    }

    $payload = json_encode(['type' => 'A', 'name' => $dominio, 'content' => $ip, 'proxied' => true, 'ttl' => 1]);
    $ch = curl_init("https://api.cloudflare.com/client/v4/zones/$zone/dns_records");
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . trim($token), 'Content-Type: application/json'],
        CURLOPT_TIMEOUT        => 30,
    ]);
    $resp = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $j = json_decode((string) $resp, true);
    if ($code === 200 && !empty($j['success'])) {
        return [true, 'subdominio creado en Cloudflare'];
    }
    foreach (($j['errors'] ?? []) as $e) {
        if (in_array((int) ($e['code'] ?? 0), [81057, 81058], true)) {
            return [true, 'el subdominio ya existía en Cloudflare'];
        }
    }
    $msg = $j['errors'][0]['message'] ?? ('http ' . $code);
    return [false, 'Cloudflare falló: ' . $msg];
}

/**
 * Asegura el contenedor de bot de un cliente. 1 por cliente, con SUS
 * credenciales de agente, espejando usuarios contra SU base (por su dominio).
 * Idempotente: si el contenedor ya existe, no hace nada. Best-effort.
 */
function asegurar_bot($c, $cfg) {
    $slug = preg_replace('/[^a-z0-9_-]/i', '', (string) $c['slug']);
    $user = (string) ($c['agente_usuario'] ?? '');
    $pass = (string) ($c['agente_password'] ?? '');
    if ($slug === '' || $user === '' || $pass === '') {
        return 'sin bot (faltan credenciales de agente)';
    }

    // Cliente por-path: la API vive bajo /<slug>/gp-api/, no en la raíz del
    // dominio (que es compartida entre varios clientes de este tipo).
    $base = 'https://' . $c['dominio'] . (!empty($c['path_tenant']) ? '/' . $slug : '');

    $name = 'bot-' . $slug;
    $existe = trim((string) shell_exec('docker ps -aq --filter ' . escapeshellarg('name=^' . $name . '$') . ' 2>/dev/null'));
    if ($existe !== '') {
        return 'bot ya existía';
    }

    // ¿está la imagen?
    $img = trim((string) shell_exec("docker images -q ganamos-bot:latest 2>/dev/null"));
    if ($img === '') {
        return 'bot NO: falta la imagen ganamos-bot:latest (construíla en ~/Bot-python)';
    }

    // La API valida con la BOT_API_KEY global (el tenant lo decide el dominio).
    $apiKey = '';
    if (is_file('/var/www/api/config.local.php')) {
        $a = require '/var/www/api/config.local.php';
        $apiKey = is_array($a) ? ($a['BOT_API_KEY'] ?? '') : '';
    }

    @mkdir('/opt/bots/' . $slug, 0750, true);

    $cmd = 'docker run -d '
         . '--name ' . escapeshellarg($name) . ' '
         . '--restart unless-stopped --shm-size 1g --init '
         . '-e ' . escapeshellarg('PANEL_USER=' . $user) . ' '
         . '-e ' . escapeshellarg('PANEL_PASS=' . $pass) . ' '
         . '-e ' . escapeshellarg('LOGIN_URL=https://agents.ganamosonline.com/') . ' '
         . '-e ' . escapeshellarg('PANEL_URL=https://agents.ganamosonline.com/user/create-player') . ' '
         . '-e ' . escapeshellarg('API_URL=' . $base . '/gp-api/usuarios_sync.php') . ' '
         . '-e ' . escapeshellarg('API_KEY=' . $apiKey) . ' '
         . '-v ' . escapeshellarg('/opt/bots/' . $slug . ':/datos') . ' '
         . 'ganamos-bot:latest python /app/sync_usuarios.py --headless --loop 300 2>&1';

    $out = trim((string) shell_exec($cmd));
    if (preg_match('/^[0-9a-f]{12,}$/i', $out)) {
        return 'bot levantado';
    }
    return 'bot NO arrancó: ' . substr($out, 0, 120);
}

/**
 * Asegura el contenedor de ALTAS de un cliente (bot_crear_jugador.py). Es el
 * hermano de asegurar_bot() de arriba (mismo patrón: 1 por cliente, SUS
 * credenciales, idempotente) pero para OTRO trabajo: sondea `altas_cola.php`
 * (tabla `altas`, la cola que llena registro.html/crear_cuenta.php) y da de
 * alta jugadores nuevos en el panel de agentes de ESE cliente, en vez de solo
 * espejar los que ya existen.
 *
 * Mismo binario/imagen que asegurar_bot() (ganamos-bot:latest ya trae
 * bot_crear_jugador.py, bot_cargar_fichas.py y sync_usuarios.py copiados,
 * ver bot/Dockerfile) -- de hecho el CMD default de la imagen YA es
 * bot_crear_jugador.py --headless; acá se lo pasa explícito igual, por
 * claridad y para no depender de que el default de la imagen no cambie.
 *
 * Contenedor separado de sync_usuarios (nombre `altas-<slug>`, no
 * `bot-<slug>`): son dos procesos con ciclos de vida y logs distintos, y así
 * un fallo en uno no tira abajo al otro.
 */
function asegurar_bot_altas($c, $cfg) {
    $slug = preg_replace('/[^a-z0-9_-]/i', '', (string) $c['slug']);
    $user = (string) ($c['agente_usuario'] ?? '');
    $pass = (string) ($c['agente_password'] ?? '');
    if ($slug === '' || $user === '' || $pass === '') {
        return 'sin bot de altas (faltan credenciales de agente)';
    }

    $base = 'https://' . $c['dominio'] . (!empty($c['path_tenant']) ? '/' . $slug : '');

    $name = 'altas-' . $slug;
    $existe = trim((string) shell_exec('docker ps -aq --filter ' . escapeshellarg('name=^' . $name . '$') . ' 2>/dev/null'));
    if ($existe !== '') {
        return 'bot de altas ya existía';
    }

    $img = trim((string) shell_exec("docker images -q ganamos-bot:latest 2>/dev/null"));
    if ($img === '') {
        return 'bot de altas NO: falta la imagen ganamos-bot:latest (construíla en ~/Bot-python)';
    }

    $apiKey = '';
    if (is_file('/var/www/api/config.local.php')) {
        $a = require '/var/www/api/config.local.php';
        $apiKey = is_array($a) ? ($a['BOT_API_KEY'] ?? '') : '';
    }

    // Volumen propio (distinto del de sync_usuarios): cada proceso guarda su
    // propia sesión de Playwright (estado_sesion.json) en su carpeta, no se
    // pisan entre sí aunque compartan las mismas credenciales de agente.
    @mkdir('/opt/bots-altas/' . $slug, 0750, true);

    $cmd = 'docker run -d '
         . '--name ' . escapeshellarg($name) . ' '
         . '--restart unless-stopped --shm-size 1g --init '
         . '-e ' . escapeshellarg('PANEL_USER=' . $user) . ' '
         . '-e ' . escapeshellarg('PANEL_PASS=' . $pass) . ' '
         . '-e ' . escapeshellarg('LOGIN_URL=https://agents.ganamosonline.com/') . ' '
         . '-e ' . escapeshellarg('PANEL_URL=https://agents.ganamosonline.com/user/create-player') . ' '
         . '-e ' . escapeshellarg('API_URL=' . $base . '/gp-api/altas_cola.php') . ' '
         . '-e ' . escapeshellarg('API_KEY=' . $apiKey) . ' '
         . '-v ' . escapeshellarg('/opt/bots-altas/' . $slug . ':/datos') . ' '
         . 'ganamos-bot:latest python /app/bot_crear_jugador.py --headless 2>&1';

    $out = trim((string) shell_exec($cmd));
    if (preg_match('/^[0-9a-f]{12,}$/i', $out)) {
        return 'bot de altas levantado';
    }
    return 'bot de altas NO arrancó: ' . substr($out, 0, 120);
}

// ---------------------------------------------------------------------------
// Pasada 1: provisionar clientes nuevos (base + DNS)
// ---------------------------------------------------------------------------
$pend = $pdo->query(
    "SELECT id, slug, dominio, db_nombre, path_tenant FROM clientes
     WHERE aprovisionado = 0 AND estado = 'activo' AND db_nombre IS NOT NULL"
)->fetchAll();

foreach ($pend as $c) {
    $db = $c['db_nombre'];

    if (!preg_match('/^[a-z0-9_]+$/i', $db)) {
        marcar($pdo, $c['id'], false, 'nombre de base inválido: ' . $db);
        echo date('c') . " ERROR {$c['slug']}: nombre inválido\n";
        continue;
    }

    // crear base + grant (root por socket)
    $sql = "CREATE DATABASE IF NOT EXISTS `$db` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci; "
         . "GRANT ALL PRIVILEGES ON `$db`.* TO '{$cfg['DB_USER']}'@'localhost'; FLUSH PRIVILEGES;";
    exec('mariadb -e ' . escapeshellarg($sql) . ' 2>&1', $o1, $r1);
    if ($r1 !== 0) {
        marcar($pdo, $c['id'], false, 'crear/grant: ' . implode(' ', $o1));
        echo date('c') . " ERROR {$c['slug']}: " . implode(' ', $o1) . "\n";
        continue;
    }

    // esquema solo si la base está vacía (idempotente)
    $yaTiene = trim((string) shell_exec(
        'mariadb ' . escapeshellarg($db) . " -N -e \"SHOW TABLES LIKE 'usuarios'\" 2>/dev/null"
    ));
    if ($yaTiene === '') {
        exec('mariadb ' . escapeshellarg($db) . ' < ' . escapeshellarg($PLANTILLA) . ' 2>&1', $o2, $r2);
        if ($r2 !== 0) {
            marcar($pdo, $c['id'], false, 'cargar esquema: ' . implode(' ', $o2));
            echo date('c') . " ERROR {$c['slug']}: esquema " . implode(' ', $o2) . "\n";
            continue;
        }
    }

    // Migraciones versionadas (api/sql/*.sql): la plantilla es el esquema
    // base, pero las tablas/columnas que se fueron agregando después viven en
    // git como migraciones. Se corren SIEMPRE (son idempotentes:
    // CREATE TABLE IF NOT EXISTS / ADD COLUMN IF NOT EXISTS), así un cliente
    // nuevo queda al día sin mantener la plantilla a mano. git es la fuente
    // de verdad.
    //
    // A los clientes YA aprovisionados los actualiza la pasada 1.5, no esta
    // linea: aca adentro solo entran los que tienen aprovisionado = 0.
    $migMsg = aplicar_migraciones($db);
    if ($migMsg !== '') { echo date('c') . " {$c['slug']} migraciones: $migMsg\n"; }

    // Por-path: comparte el dominio del operador, que ya tiene DNS. No hay
    // subdominio propio que crear.
    if (!empty($c['path_tenant'])) {
        list($dnsOk, $dnsMsg) = array(true, 'cliente por-path, sin DNS propio');
    } else {
        list($dnsOk, $dnsMsg) = cf_dns_upsert($c['dominio'], $cfg);
    }
    marcar($pdo, $c['id'], true, 'base ok; ' . $dnsMsg);
    echo date('c') . " OK {$c['slug']} -> base $db; $dnsMsg\n";
}

// ---------------------------------------------------------------------------
// Pasada 1.5: mantener AL DIA las migraciones de los clientes que YA estan
// aprovisionados.
//
// POR QUE EXISTE ESTA PASADA (el bug que arregla)
//
// aplicar_migraciones() se llamaba UNICAMENTE dentro de la pasada 1, que
// selecciona `WHERE aprovisionado = 0`. Un cliente ya aprovisionado no vuelve
// a entrar ahi NUNCA, asi que las migraciones nuevas llegaban solo a los
// clientes NUEVOS. El comentario de la pasada 1 decia lo contrario ("uno viejo
// se actualiza solo en la proxima corrida") y DEPLOY.md lo repetia, asi que
// todos dabamos por hecho que se aplicaban solas.
//
// El resultado se pagaba siempre igual: se desplegaba codigo que usaba una
// columna nueva, la columna no existia en la base del cliente, y el endpoint
// tiraba 500 o la vista quedaba vacia -- sin que nada dijera por que. Paso con
// las migraciones 35, 36, 37 y 40.
//
// Las migraciones son idempotentes (CREATE TABLE IF NOT EXISTS / ADD COLUMN IF
// NOT EXISTS), asi que correrlas de mas no cuesta nada; igual solo se corren
// cuando la HUELLA del contenido de api/sql/ cambio respecto de lo ya aplicado
// en esa base, para no gastar 45 statements por cliente por minuto.
// ---------------------------------------------------------------------------
$huellaHoy = migraciones_huella();
if ($huellaHoy !== '') {
    $puestos = $pdo->query(
        "SELECT slug, db_nombre FROM clientes
          WHERE aprovisionado = 1 AND estado = 'activo' AND db_nombre IS NOT NULL"
    )->fetchAll();

    foreach ($puestos as $c) {
        $db = (string) $c['db_nombre'];
        if (!preg_match('/^[a-z0-9_]+$/i', $db)) { continue; }
        if (huella_leer($db) === $huellaHoy) { continue; }   // ya esta al dia

        $msg = aplicar_migraciones($db);
        echo date('c') . " migraciones {$c['slug']}: $msg\n";
        // La huella se guarda SOLO si no hubo errores: si una migracion fallo,
        // la proxima corrida tiene que volver a intentarlo en vez de dar por
        // hecho que quedo al dia.
        if (strpos($msg, 'error') === false) {
            huella_guardar($db, $huellaHoy);
        }
    }
}

// ---------------------------------------------------------------------------
// Pasada 2: asegurar el bot de cada cliente activo con credenciales de agente.
// Corre siempre (idempotente): si agregás las credenciales después, el bot
// arranca en la próxima corrida sin re-provisionar nada.
// ---------------------------------------------------------------------------
$activos = $pdo->query(
    "SELECT slug, dominio, path_tenant, agente_usuario, agente_password FROM clientes
     WHERE estado = 'activo' AND agente_usuario IS NOT NULL AND agente_usuario <> ''"
)->fetchAll();

foreach ($activos as $c) {
    $msg = asegurar_bot($c, $cfg);
    // Solo se loguea cuando hay novedad (arrancó o falló), no el 'ya existía'.
    if (strpos($msg, 'levantado') !== false || strpos($msg, 'NO') !== false) {
        echo date('c') . " bot {$c['slug']}: $msg\n";
    }

    // Bot de altas (registro.html / crear_cuenta.php): mismo criterio, mismas
    // credenciales de agente, contenedor y trabajo distintos -- ver
    // asegurar_bot_altas().
    $msgAltas = asegurar_bot_altas($c, $cfg);
    if (strpos($msgAltas, 'levantado') !== false || strpos($msgAltas, 'NO') !== false) {
        echo date('c') . " bot-altas {$c['slug']}: $msgAltas\n";
    }
}

// ---------------------------------------------------------------------------
// Pasada 3: gritar cuando algo se rompio en silencio.
//
// El patron que mas caro salio en este proyecto no es que algo falle: es que
// falle SIN QUE NADIE SE ENTERE. Un cliente sin bot encola altas que nadie
// atiende y el jugador ve "creando tu cuenta..." para siempre; nos enteramos
// cuando alguien se queja, no cuando pasa.
//
// Esta pasada no arregla nada -- avisa. Corre cada minuto junto con el resto y
// escribe en el mismo log; `panel.php?accion=salud` lee lo que deja aca para
// mostrarlo en el panel del dueño sin tener que entrar por SSH.
// ---------------------------------------------------------------------------
$ALTA_ATASCADA_MIN = 15;   // una alta sana se resuelve en ~2 min
$problemas = [];

$todos = $pdo->query(
    "SELECT slug, db_nombre, agente_usuario FROM clientes
      WHERE estado = 'activo' AND db_nombre IS NOT NULL"
)->fetchAll();

foreach ($todos as $c) {
    $db   = (string) $c['db_nombre'];
    $slug = (string) $c['slug'];
    if (!preg_match('/^[a-z0-9_]+$/i', $db)) { continue; }

    // 1. Cliente activo sin credenciales de agente: no puede tener bot, asi
    //    que sus altas se acumulan sin que nadie las toque.
    if (trim((string) ($c['agente_usuario'] ?? '')) === '') {
        $problemas[] = "$slug: sin credenciales de agente (no hay bot de altas)";
    }

    // 2. Altas encoladas hace rato. Es la señal mas directa de "el bot de ese
    //    cliente no esta corriendo o esta trabado".
    //
    // Los problemas se juntan TAMBIEN por cliente ($suyos) y no solo en la
    // lista global: el Telegram se manda con las credenciales de cada agencia,
    // asi que a cada una hay que contarle lo suyo y nada mas.
    $suyos = [];
    if (trim((string) ($c['agente_usuario'] ?? '')) === '') {
        $suyos[] = 'No hay credenciales de agente cargadas, asi que las altas de '
                 . 'jugadores nuevos no se procesan.';
    }
    try {
        $q = new PDO("mysql:host={$cfg['DB_HOST']};dbname=$db;charset=utf8mb4",
                     $cfg['DB_USER'], $cfg['DB_PASS'],
                     [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 4]);
        $st = $q->prepare(
            "SELECT COUNT(*) FROM altas
              WHERE estado = 'pendiente'
                AND pedido_en < DATE_SUB(NOW(), INTERVAL ? MINUTE)"
        );
        $st->execute([$ALTA_ATASCADA_MIN]);
        $atascadas = (int) $st->fetchColumn();
        if ($atascadas > 0) {
            $problemas[] = "$slug: $atascadas alta(s) sin atender hace mas de {$ALTA_ATASCADA_MIN} min";
            $suyos[] = "$atascadas alta(s) de jugadores sin resolver hace mas de "
                     . "{$ALTA_ATASCADA_MIN} minutos.";
        }

        // 3. Comprobantes que entraron y nadie resolvio.
        try {
            $rev = (int) $q->query("SELECT COUNT(*) FROM pagos WHERE estado='revision'")->fetchColumn();
            if ($rev > 0) {
                $suyos[] = "$rev transferencia(s) sin asignar en Comprobantes.";
            }
        } catch (Throwable $e) { /* sin tabla pagos: nada que mirar */ }

        // 4. NADIE dio señales de vida hace rato. Es la unica alarma que
        //    detecta "se cayo todo": si el sitio no responde o el chat esta
        //    roto, no hay mensajes NI recargas, y ningun otro chequeo lo ve.
        //    El umbral es generoso a proposito -- de madrugada no hay nadie
        //    jugando y eso es normal, no una falla.
        try {
            $hs = (int) ($q->query(
                "SELECT valor FROM config_crm WHERE clave='tg_sin_actividad_hs'"
            )->fetchColumn() ?: 6);
            if ($hs > 0) {
                $ult = $q->query(
                    "SELECT GREATEST(
                        COALESCE((SELECT MAX(creado_en) FROM mensajes), '2000-01-01'),
                        COALESCE((SELECT MAX(creada_en) FROM recargas), '2000-01-01'))"
                )->fetchColumn();
                $horas = $ult ? (time() - strtotime((string) $ult)) / 3600 : 0;
                if ($ult && $horas >= $hs) {
                    $suyos[] = sprintf('Hace %d horas que no hay NINGUNA actividad '
                        . '(ni mensajes ni recargas). Puede que el sitio o el chat esten caidos.',
                        (int) $horas);
                }
            }
        } catch (Throwable $e) { /* tablas distintas: se saltea */ }

        // El aviso va con la clave 'salud': si el problema sigue igual, no se
        // repite hasta que pase el tiempo configurado. Sin eso serian 1.440
        // mensajes por dia y el agente terminaria silenciando el bot.
        if ($suyos && is_file(__DIR__ . '/../api/telegram_lib.php')) {
            require_once __DIR__ . '/../api/telegram_lib.php';
            if (function_exists('tg_evento')) {
                tg_evento($q, 'salud', '🔧 Hay algo para revisar',
                          ['Problemas' => "\n· " . implode("\n· ", $suyos)], 'salud');
            }
        }
    } catch (Throwable $e) {
        // Una base que no abre es en si misma un problema que hay que ver.
        $problemas[] = "$slug: no pude consultar la base ($db)";
    }
}

// El estado se deja SIEMPRE, haya o no problemas: un archivo viejo significa
// que el cron dejo de correr, y eso tambien hay que poder verlo.
@file_put_contents(huella_dir() . '/salud.json', json_encode([
    'revisado_en' => date('c'),
    'problemas'   => $problemas,
], JSON_UNESCAPED_UNICODE));

foreach ($problemas as $p) {
    echo date('c') . " !! SALUD: $p\n";
}
