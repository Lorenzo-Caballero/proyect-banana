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
         . '-e ' . escapeshellarg('LOGIN_URL=https://agents.ganamos7.com/') . ' '
         . '-e ' . escapeshellarg('PANEL_URL=https://agents.ganamos7.com/user/create-player') . ' '
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
}
