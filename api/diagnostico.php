<?php
/**
 * Diagnostico de la instalacion. Subilo a /api/ y abrilo en el navegador:
 *   https://TU-DOMINIO/api/diagnostico.php?clave=dejame-ver
 *
 * Escrito en PHP viejo a proposito, para que corra aunque el hosting tenga una
 * version antigua: si el resto tira "500 sin mensaje", este archivo igual anda
 * y te dice por que.
 *
 * BORRALO DEL SERVER cuando termines de diagnosticar.
 */

// Puerta minima para que no quede abierto a cualquiera que adivine la URL.
$permitido = isset($_GET['clave']) && $_GET['clave'] === 'dejame-ver';
if (!$permitido) {
    http_response_code(404);
    exit;
}

header('Content-Type: text/plain; charset=utf-8');

// Queremos VER los errores aca, aunque el hosting los oculte.
@ini_set('display_errors', '1');
@ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

echo "=== ENTORNO ===\n";
echo "PHP            : " . PHP_VERSION . "\n";
echo "SAPI           : " . PHP_SAPI . "\n";
echo "Carpeta        : " . __DIR__ . "\n";
echo "display_errors : " . ini_get('display_errors') . "\n";
echo "error_log      : " . ini_get('error_log') . "\n";

echo "\n=== EXTENSIONES ===\n";
$necesarias = array('pdo', 'pdo_mysql', 'json', 'mbstring');
foreach ($necesarias as $ext) {
    echo str_pad($ext, 15) . (extension_loaded($ext) ? "si\n" : "NO  <-- falta\n");
}

echo "\n=== ARCHIVOS ===\n";
$archivos = array(
    'config.php', 'config.local.php', 'db.php',
    'cola_panel.php', 'jugadores_crud.php', 'login.php', '.htaccess',
);
foreach ($archivos as $a) {
    $ruta = __DIR__ . '/' . $a;
    if (is_file($ruta)) {
        echo str_pad($a, 22) . "si   " . filesize($ruta) . " bytes\n";
    } else {
        echo str_pad($a, 22) . "NO EXISTE\n";
    }
}

echo "\n=== CARGA DE config.php ===\n";
$rutaConfig = __DIR__ . '/config.php';
if (!is_file($rutaConfig)) {
    echo "config.php no esta subido. Ese es el problema.\n";
} else {
    require_once $rutaConfig;
    if (!function_exists('cfg')) {
        echo "Se cargo pero cfg() no quedo definida. Revisa el contenido.\n";
    } else {
        echo "cfg() disponible: si\n";
        $clave = cfg('BOT_API_KEY');
        if ($clave === '') {
            echo "BOT_API_KEY      : VACIA  <-- crea api/config.local.php\n";
        } else {
            echo "BOT_API_KEY      : cargada, " . strlen($clave) . " caracteres";
            echo strlen($clave) < 16 ? "  <-- muy corta, minimo 16\n" : "  (ok)\n";
        }
        foreach (array('DB_HOST', 'DB_NAME', 'DB_USER') as $k) {
            $v = cfg($k);
            echo str_pad($k, 17) . ": " . ($v === '' ? "VACIA" : $v) . "\n";
        }
        echo "DB_PASS          : " . (cfg('DB_PASS') === '' ? "VACIA" : "cargada") . "\n";
    }
}

echo "\n=== CONEXION A LA BASE ===\n";
if (!function_exists('cfg')) {
    echo "No se puede probar: config.php no cargo.\n";
} else {
    try {
        $pdo = new PDO(
            'mysql:host=' . cfg('DB_HOST', 'localhost') . ';dbname=' . cfg('DB_NAME') . ';charset=utf8mb4',
            cfg('DB_USER'),
            cfg('DB_PASS'),
            array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION)
        );
        echo "Conexion         : OK\n";

        $cols = $pdo->query("SHOW COLUMNS FROM jugadores")->fetchAll(PDO::FETCH_COLUMN);
        echo "Columnas         : " . implode(', ', $cols) . "\n";

        $faltan = array();
        foreach (array('creado', 'panel_estado', 'panel_password', 'panel_intentos') as $c) {
            if (!in_array($c, $cols)) { $faltan[] = $c; }
        }
        echo "Migracion        : " . ($faltan ? "FALTAN: " . implode(', ', $faltan) : "completa") . "\n";

        if (!$faltan) {
            $q = $pdo->query("SELECT COUNT(*) t,
                                     SUM(creado = 1) hechos,
                                     SUM(creado = 0 AND panel_password IS NOT NULL) listos,
                                     SUM(panel_estado = 'procesando') tomados
                                FROM jugadores")->fetch(PDO::FETCH_ASSOC);
            echo "Jugadores        : " . (int)$q['t'] . " total, "
               . (int)$q['hechos'] . " ya en el panel, "
               . (int)$q['listos'] . " esperando al bot, "
               . (int)$q['tomados'] . " en proceso\n";
        }
    } catch (Exception $e) {
        echo "Conexion         : FALLO\n";
        echo "Detalle          : " . $e->getMessage() . "\n";
    }
}

echo "\nListo. Acordate de BORRAR este archivo del server.\n";
