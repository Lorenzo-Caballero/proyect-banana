<?php
/**
 * Despacha la campaña a cada CRM activo usando su ruta pública de tenant.
 * Solo CLI. No ejecuta campañas con una consulta directa a las bases: cada
 * tenant entra por api/db.php, que aplica sus propias reglas de aislamiento.
 * El endpoint solo actúa si ese CRM tiene fid_activa=1.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$configPath = getenv('GP_CONFIG_FILE') ?: '';
if ($configPath === '') {
    $configCandidates = [dirname(__DIR__) . '/api/config.local.php', '/var/www/api/config.local.php'];
    foreach ($configCandidates as $candidate) {
        if (is_file($candidate)) { $configPath = $candidate; break; }
    }
}
if ($configPath !== '' && is_file($configPath)) {
    $localConfig = require $configPath;
    if (is_array($localConfig)) {
        // config.php resuelve primero el entorno; así el dispatcher comparte
        // los secretos de la app servida desde /var/www sin copiar config.local.
        foreach (['DB_HOST', 'DB_USER', 'DB_PASS', 'CONTROL_DB_NAME', 'BOT_API_KEY'] as $name) {
            if (getenv($name) === false && isset($localConfig[$name]) && is_scalar($localConfig[$name])) {
                putenv($name . '=' . (string)$localConfig[$name]);
            }
        }
    }
}
require_once dirname(__DIR__) . '/api/config.php';

$log = static function (array $row): void {
    fwrite(STDOUT, json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL);
};
$lockPath = sys_get_temp_dir() . '/gp-fidelizacion-multicliente.lock';
$lock = fopen($lockPath, 'c');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
    $log(['ok' => false, 'estado' => 'ya_en_ejecucion', 'fecha' => date(DATE_ATOM)]);
    exit(0);
}

try {
    $host = cfg('DB_HOST', 'localhost');
    $user = cfg('DB_USER');
    $pass = cfg('DB_PASS');
    $control = cfg('CONTROL_DB_NAME', 'goldpaw_control');
    $key = cfg('BOT_API_KEY');
    if ($user === '' || strlen($key) < 16) {
        throw new RuntimeException('Falta DB_USER o BOT_API_KEY válido en config.local.php');
    }
    if (!extension_loaded('curl')) { throw new RuntimeException('La extensión PHP cURL no está instalada'); }

    $ctl = new PDO("mysql:host=$host;dbname=$control;charset=utf8mb4", $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    $columns = $ctl->query("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA="
        . $ctl->quote($control) . " AND TABLE_NAME='clientes'")->fetchAll(PDO::FETCH_COLUMN);
    $has = array_fill_keys($columns, true);
    foreach (['db_nombre', 'dominio', 'estado', 'slug'] as $required) {
        if (!isset($has[$required])) { throw new RuntimeException("Falta clientes.$required en la base de control"); }
    }
    $routeExpr = isset($has['ruta_slug']) ? "COALESCE(NULLIF(c.ruta_slug,''),c.slug)" : 'c.slug';
    $pathExpr = isset($has['path_tenant']) ? 'COALESCE(c.path_tenant,0)' : '0';
    $productoWhere = isset($has['producto']) ? " AND c.producto='crm'" : '';
    $sql = "SELECT c.id,c.slug,c.db_nombre,c.dominio,$routeExpr ruta_slug,$pathExpr path_tenant
              FROM clientes c WHERE c.estado='activo' AND c.db_nombre<>'' AND c.dominio<>''$productoWhere
             ORDER BY c.id";
    $clients = $ctl->query($sql)->fetchAll();
    $owners = $ctl->query("SELECT db_nombre,COUNT(*) n FROM clientes WHERE estado='activo' GROUP BY db_nombre")
        ->fetchAll(PDO::FETCH_KEY_PAIR);

    $count = ['total' => count($clients), 'ok' => 0, 'error' => 0, 'omitidos' => 0];
    foreach ($clients as $client) {
        $slug = (string)$client['slug'];
        $db = (string)$client['db_nombre'];
        $domain = strtolower(trim((string)$client['dominio']));
        $route = trim((string)$client['ruta_slug'], '/');
        if ((int)$client['path_tenant'] === 1 && $route === '') {
            $count['omitidos']++;
            $log(['ok' => false, 'estado' => 'ruta_vacia', 'cliente' => $slug]);
            continue;
        }
        // Una base compartida es una falla de aislamiento; jamás se despacha a
        // una ruta arbitraria cuando hay dos tenants que podrían modificarla.
        if ((int)($owners[$db] ?? 0) !== 1) {
            $count['omitidos']++;
            $log(['ok' => false, 'estado' => 'base_compartida', 'cliente' => $slug]);
            continue;
        }
        if (!preg_match('/^(?=.{1,190}$)[a-z0-9](?:[a-z0-9.-]*[a-z0-9])?$/', $domain)
            || str_contains($domain, '..')) {
            $count['omitidos']++;
            $log(['ok' => false, 'estado' => 'dominio_invalido', 'cliente' => $slug]);
            continue;
        }
        $base = 'https://' . $domain . '/';
        if ((int)$client['path_tenant'] === 1) { $base .= rawurlencode($route) . '/'; }
        $url = $base . 'gp-api/fidelizacion.php';
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => '',
            CURLOPT_HTTPHEADER => ['X-Api-Key: ' . $key, 'Accept: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);
        $body = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        $json = is_string($body) ? json_decode($body, true) : null;
        $ok = $status === 200 && is_array($json) && !empty($json['ok']);
        $count[$ok ? 'ok' : 'error']++;
        $log([
            'ok' => $ok,
            'cliente' => $slug,
            'ruta' => (int)$client['path_tenant'] === 1 ? $route : '/',
            'http' => $status,
            'avisados' => (int)($json['avisados'] ?? 0),
            'bonos_vencidos' => (int)($json['bonos_vencidos'] ?? 0),
            'error' => $ok ? null : ($error !== '' ? $error : ($json['error'] ?? 'respuesta_invalida')),
        ]);
    }
    $log(['ok' => $count['error'] === 0, 'resumen' => $count, 'fecha' => date(DATE_ATOM)]);
    exit($count['error'] > 0 ? 1 : 0);
} catch (Throwable $e) {
    $log(['ok' => false, 'error' => $e->getMessage(), 'fecha' => date(DATE_ATOM)]);
    exit(1);
} finally {
    if (is_resource($lock)) { flock($lock, LOCK_UN); fclose($lock); }
}
