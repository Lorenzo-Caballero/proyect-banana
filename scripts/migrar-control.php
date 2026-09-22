<?php
/**
 * migrar-control.php — Aplica las migraciones de `panel/sql/` a goldpaw_control.
 *
 * POR QUÉ EXISTE (22/09/2026). `scripts/migrar.php` pone al día la base de un
 * CLIENTE (api/sql/) y `panel/provisionar.php` hace lo mismo por cada cliente
 * nuevo. Las del CONTROL —la base maestra, donde viven los clientes, su método
 * de cobro y ahora su casilla de mail— no las corría NADIE: había que acordarse
 * de tirar el .sql a mano en el VPS.
 *
 * El síntoma fue el de siempre: la pantalla «Cómo cobro» mostraba "la lectura
 * de casilla todavía no está habilitada en este servidor, escribinos" sobre un
 * feature que ya estaba desplegado y funcionando. El código llegaba; la columna
 * no. Nadie se enteraba salvo el cliente que se quedaba sin poder cobrar.
 *
 * SON IDEMPOTENTES (CREATE TABLE IF NOT EXISTS / ADD COLUMN IF NOT EXISTS), así
 * que correrlas todas en cada deploy no hace nada cuando ya están. Eso es lo
 * que permite llamarlo desde deploy.sh sin llevar cuenta de cuál falta.
 *
 *   php /opt/goldpaw/scripts/migrar-control.php          aplica todas
 *   php /opt/goldpaw/scripts/migrar-control.php --ver    qué haría, sin tocar
 */

declare(strict_types=1);

$raiz = dirname(__DIR__);
$soloVer = in_array('--ver', array_slice($argv, 1), true);

/* Las credenciales salen de api/config.local.php, igual que migrar.php: es
   donde ya están y evita tipear una contraseña en la línea de comandos (que
   además queda en el historial del shell). */
$cfgLocal = $raiz . '/api/config.local.php';
if (!is_file($cfgLocal)) {
    fwrite(STDERR, "No encuentro $cfgLocal\n");
    exit(1);
}
require $raiz . '/api/config.php';

$host = cfg('DB_HOST', 'localhost');
$base = cfg('CONTROL_DB_NAME', 'goldpaw_control');
$usr  = cfg('DB_USER');
$pass = cfg('DB_PASS');

$dir = $raiz . '/panel/sql';
if (!is_dir($dir)) {
    fwrite(STDERR, "No encuentro $dir\n");
    exit(1);
}
$archivos = glob($dir . '/*.sql') ?: [];
/* Por número, no alfabético: sin esto la 10 correría antes que la 9 y una
   migración que depende de la anterior fallaría por orden. */
usort($archivos, static function ($a, $b) {
    return (int)basename($a) <=> (int)basename($b) ?: strcmp($a, $b);
});
if (!$archivos) {
    echo "No hay migraciones en $dir\n";
    exit(0);
}

echo "Control: $base en $host — " . count($archivos) . " migración(es)\n";
if ($soloVer) {
    foreach ($archivos as $f) { echo "   (vería) " . basename($f) . "\n"; }
    exit(0);
}

try {
    $pdo = new PDO(
        "mysql:host=$host;dbname=$base;charset=utf8mb4", $usr, $pass,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
} catch (Throwable $e) {
    fwrite(STDERR, "No pude conectar a $base: " . $e->getMessage() . "\n");
    exit(1);
}

$malas = 0;
foreach ($archivos as $f) {
    $sql = (string)file_get_contents($f);
    $nom = basename($f);
    try {
        $pdo->exec($sql);
        echo "   ok  $nom\n";
    } catch (Throwable $e) {
        /* Los duplicados NO son un error: son el precio de correr todas las
           veces, y es justamente lo que hace seguro llamarlo desde el deploy.
           MySQL tira 1060/1061/1050 cuando la columna, el índice o la tabla ya
           existen -- con algunas sintaxis el IF NOT EXISTS no alcanza. */
        $msg = $e->getMessage();
        if (str_contains($msg, 'Duplicate column') || str_contains($msg, 'Duplicate key')
            || str_contains($msg, 'already exists')) {
            echo "   ya estaba  $nom\n";
            continue;
        }
        $malas++;
        fwrite(STDERR, "   !! $nom: $msg\n");
    }
}

if ($malas) {
    fwrite(STDERR, "\n$malas migración(es) del control fallaron.\n");
    exit(1);
}
echo "Control al día.\n";
exit(0);
