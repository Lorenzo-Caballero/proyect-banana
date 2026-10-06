<?php
/**
 * migrar-a-subdominio.php — Pasa un cliente de /<slug>/ a <slug>.<dominio>.
 *
 * EL PROBLEMA QUE RESUELVE (05/10/2026). Nahuel: *"intento entrar al casino de
 * mi cliente y me sigue redirigiendo a /home"*. Medido: el servidor devuelve
 * 200 sin redirect y el tenant resuelve bien — el salto lo hace el SPA de la
 * plataforma, que no reconoce /<slug>/ como una de sus rutas y la normaliza.
 * Es código de ellos; forzarle el prefijo sería pelearle el routing.
 *
 * CON SUBDOMINIO EL PROBLEMA NO EXISTE, porque lo que identifica al cliente
 * deja de ser el path (que el SPA pisa) y pasa a ser el HOST (que sobrevive a
 * toda la navegación). Y de yapa resuelve el otro límite del path-tenant:
 * `localStorage` es por origen, así que con subdominio cada cliente tiene el
 * suyo y un mismo navegador puede usar dos sin pisarse.
 *
 * ============================================================================
 * EL ORDEN DE LOS PASOS ES LA PARTE QUE IMPORTA:
 *
 *   1. crear el DNS
 *   2. ESPERAR a que el subdominio conteste de verdad
 *   3. recién entonces tocar la base
 *
 * Si se cambiara la base primero y el DNS fallara, el cliente quedaría
 * inaccesible por los dos lados a la vez: el subdominio no resuelve y el path
 * viejo ya no lo reconoce. Haciéndolo en este orden, un fallo en 1 o 2 deja
 * todo exactamente como estaba.
 *
 * LO QUE SÍ SE PIERDE, y hay que decirlo antes: el link viejo
 * (dominio.com/<slug>/) deja de funcionar. Hay que darle el nuevo al cliente.
 *
 *   php scripts/migrar-a-subdominio.php --slug=oromaris            # muestra
 *   php scripts/migrar-a-subdominio.php --slug=oromaris --aplicar  # lo hace
 */

declare(strict_types=1);

$opts    = getopt('', ['slug:', 'aplicar']);
$slug    = strtolower(trim((string)($opts['slug'] ?? '')));
$aplicar = isset($opts['aplicar']);

if ($slug === '' || !preg_match('/^[a-z0-9-]{2,60}$/', $slug)) {
    fwrite(STDERR, "Falta --slug=<cliente>. Ej: php scripts/migrar-a-subdominio.php --slug=oromaris\n");
    exit(1);
}

$cfgPath = getenv('GP_PANEL_CONFIG') ?: (__DIR__ . '/../panel/panel_config.php');
$cfg = require $cfgPath;

function line(string $m = ''): void { echo $m . "\n"; }

try {
    $pdo = new PDO(
        "mysql:host={$cfg['DB_HOST']};dbname={$cfg['DB_NAME']};charset=utf8mb4",
        $cfg['DB_USER'], $cfg['DB_PASS'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );
} catch (PDOException $e) {
    fwrite(STDERR, 'No pude conectar al control: ' . $e->getMessage() . "\n");
    exit(1);
}

$q = $pdo->prepare("SELECT id, slug, nombre, dominio, path_tenant, db_nombre
                      FROM clientes WHERE slug = ? AND estado = 'activo'");
$q->execute([$slug]);
$filas = $q->fetchAll();

if (count($filas) !== 1) {
    fwrite(STDERR, count($filas) === 0
        ? "No hay un cliente activo con slug '$slug'.\n"
        : "Hay " . count($filas) . " clientes activos con ese slug: resolvelo antes.\n");
    exit(1);
}
$c = $filas[0];

$zona  = trim((string)($cfg['CF_ZONE_NAME'] ?? ''));
$base  = $zona !== '' ? $zona : (string)$c['dominio'];
$nuevo = $slug . '.' . $base;

line($aplicar ? '=== APLICANDO ===' : '=== VISTA PREVIA (no se toca nada) ===');
line('');
line("Cliente : {$c['nombre']} ($slug)");
line("Ahora   : https://{$c['dominio']}/" . ((int)$c['path_tenant'] === 1 ? $slug . '/' : ''));
line("Queda   : https://$nuevo/");
line('');

if ((int)$c['path_tenant'] === 0 && (string)$c['dominio'] === $nuevo) {
    line('Ya está en subdominio. No hay nada que hacer.');
    exit(0);
}

line('EL LINK VIEJO DEJA DE FUNCIONAR: hay que darle el nuevo al cliente.');
line('');

if (!$aplicar) {
    line('Para aplicarlo, repetí el comando con --aplicar');
    exit(0);
}

// ---------------------------------------------------------------- 1. DNS
line('1. DNS en Cloudflare...');
$token = (string)($cfg['CF_API_TOKEN'] ?? '');
$zoneId = (string)($cfg['CF_ZONE_ID'] ?? '');
$ip     = (string)($cfg['VPS_IP'] ?? '');

if ($token === '' || $zoneId === '' || $ip === '') {
    line('   Cloudflare no está configurado en panel_config.php (CF_API_TOKEN /');
    line('   CF_ZONE_ID / VPS_IP). Creá el registro A a mano:');
    line("      $nuevo  ->  (la IP del VPS), proxied");
    line('   y volvé a correr esto.');
    exit(1);
}

$ch = curl_init("https://api.cloudflare.com/client/v4/zones/$zoneId/dns_records");
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => json_encode(['type' => 'A', 'name' => $nuevo,
                                           'content' => $ip, 'proxied' => true, 'ttl' => 1]),
    CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . trim($token), 'Content-Type: application/json'],
    CURLOPT_TIMEOUT        => 30,
]);
$resp = curl_exec($ch);
$code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);
$j = json_decode((string)$resp, true);

$dnsOk = ($code === 200 && !empty($j['success']));
if (!$dnsOk) {
    foreach (($j['errors'] ?? []) as $e) {
        if (in_array((int)($e['code'] ?? 0), [81057, 81058], true)) {
            $dnsOk = true;
            line('   el registro ya existía');
        }
    }
}
if (!$dnsOk) {
    line('   FALLÓ: ' . ($j['errors'][0]['message'] ?? ('http ' . $code)));
    line('   No se tocó la base: el cliente sigue funcionando como hasta ahora.');
    exit(1);
}
line('   ok');

// ------------------------------------------------- 2. que conteste de verdad
/* SE ESPERA A QUE RESPONDA ANTES DE TOCAR LA BASE. Un registro recién creado
   tarda en propagar, y cambiar la base antes dejaría al cliente sin ninguno de
   los dos caminos: el subdominio todavía no resuelve y el path viejo ya no lo
   reconoce. */
line('2. Esperando a que el subdominio conteste...');
$anda = false;
for ($i = 0; $i < 20; $i++) {
    $ch = curl_init("https://$nuevo/gp-api/tenant_info.php");
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 8,
                            CURLOPT_FOLLOWLOCATION => true]);
    $r  = curl_exec($ch);
    $hc = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    /* 404 "Dominio no registrado" TAMBIÉN sirve: prueba que el DNS resuelve y
       que nginx y PHP contestan. Que todavía no conozca el host es justo lo
       que el paso 3 arregla. */
    if ($hc > 0 && $hc < 500) { $anda = true; break; }
    line('   todavía no (' . ($i + 1) . '/20)...');
    sleep(6);
}
if (!$anda) {
    line('   El subdominio no contesta. NO se tocó la base: el cliente sigue igual.');
    line('   Revisá el DNS en Cloudflare y volvé a correr esto.');
    exit(1);
}
line('   contesta');

// ------------------------------------------------------------- 3. la base
line('3. Apuntando al cliente a su subdominio...');
$up = $pdo->prepare('UPDATE clientes SET dominio = ?, path_tenant = 0 WHERE id = ?');
$up->execute([$nuevo, (int)$c['id']]);
line('   ok');

line('');
line("LISTO. El casino del cliente ahora es:  https://$nuevo/");
line('');
line('Lo que sigue:');
line('  1. Dale el link nuevo al cliente (el viejo ya no funciona).');
line('  2. provisionar.php (cada minuto) le recrea el bot: su env cambió de dominio.');
line('  3. Probá que el chat del jugador caiga en SU CRM: entrá, escribí, y mirá');
line("     https://$nuevo/crm.html");
exit(0);
