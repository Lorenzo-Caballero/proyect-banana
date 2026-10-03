<?php
/**
 * t_limpiar_cola.php — Vaciar la cola de altas sin llevarse puesto a nadie.
 *
 * El script borra filas de `altas`, y CADA FILA ES UNA PERSONA que se registró
 * y está esperando su cuenta. Borrar el pedido de un jugador real lo deja
 * afuera para siempre y sin rastro: él no se entera de nada, simplemente nunca
 * puede entrar.
 *
 * LO QUE ESTOS CHEQUEOS CUIDAN:
 *   1. QUE LA VISTA PREVIA NO TOQUE NADA. Es la única defensa antes de un
 *      borrado, y una que escribe no es una vista previa.
 *   2. QUE --solo-prueba RESPETE LO QUE NO PARECE PRUEBA.
 *   3. QUE LAS ALTAS YA HECHAS ('ok') NO SE TOQUEN: son historia, y el espejo
 *      y los movimientos se apoyan en ellas.
 *
 *     T_PORT=3399 php t_limpiar_cola.php
 */
declare(strict_types=1);

$ok = 0; $fail = 0;
function chequear(string $q, bool $c, string $d = ''): void {
    global $ok, $fail;
    if ($c) { $ok++;  printf("  OK    %s\n", $q); }
    else     { $fail++; printf("  FALLA %s   %s\n", $q, $d); }
}

$port = getenv('T_PORT') ?: '';
if ($port === '') { echo "Hace falta MySQL: T_PORT=3399 php t_limpiar_cola.php\n"; exit(0); }
try {
    $pdo = new PDO("mysql:host=127.0.0.1;port=$port;charset=utf8mb4", 'root', '',
                   [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
} catch (Throwable $e) { echo "No pude conectar a MySQL\n"; exit(0); }

const CTL = 't_lc_ctl';
const CLI = 't_lc_cliente';

function montar(PDO $pdo): string
{
    $pdo->exec('DROP DATABASE IF EXISTS `' . CTL . '`');
    $pdo->exec('DROP DATABASE IF EXISTS `' . CLI . '`');
    $pdo->exec('CREATE DATABASE `' . CTL . '`');
    $pdo->exec('CREATE DATABASE `' . CLI . '`');
    $pdo->exec('CREATE TABLE `' . CTL . '`.clientes (
        id INT AUTO_INCREMENT PRIMARY KEY, slug VARCHAR(60), nombre VARCHAR(120),
        db_nombre VARCHAR(120), estado VARCHAR(20) DEFAULT \'activo\')');
    $pdo->exec('INSERT INTO `' . CTL . '`.clientes (slug, nombre, db_nombre)
                VALUES (\'cliente\', \'Un cliente\', \'' . CLI . '\')');
    $pdo->exec('CREATE TABLE `' . CLI . '`.altas (
        id INT AUTO_INCREMENT PRIMARY KEY, usuario VARCHAR(64), password VARCHAR(128),
        estado VARCHAR(20) DEFAULT \'pendiente\', intentos INT DEFAULT 0,
        origen VARCHAR(32) DEFAULT \'landing\',
        pedido_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP)');
    // Dos de prueba, un jugador real, y una YA CREADA que no se debe tocar.
    $pdo->exec('INSERT INTO `' . CLI . '`.altas (usuario, password, estado) VALUES
        (\'holaPrueba256\',\'x\',\'pendiente\'),
        (\'holatest99\',\'x\',\'pendiente\'),
        (\'holaMariana431\',\'x\',\'pendiente\'),
        (\'holaCarlos777\',\'x\',\'ok\')');

    $cfg = sys_get_temp_dir() . '/t_lc_config.php';
    file_put_contents($cfg, "<?php return ['DB_HOST'=>'127.0.0.1;port=" . getenv('T_PORT')
        . "','DB_NAME'=>'" . CTL . "','DB_USER'=>'root','DB_PASS'=>''];\n");
    return $cfg;
}

function correr(string $cfg, string $args): string
{
    putenv('GP_PANEL_CONFIG=' . $cfg);
    return (string) shell_exec('php ' . escapeshellarg(__DIR__ . '/scripts/limpiar-cola-altas.php')
                             . ' --slug=cliente ' . $args . ' 2>&1');
}

function enCola(PDO $pdo): int
{
    return (int) $pdo->query('SELECT COUNT(*) FROM `' . CLI . "`.altas
                               WHERE estado IN ('pendiente','procesando')")->fetchColumn();
}

// ===========================================================================
echo "=== 1. La vista previa muestra y no toca ===\n";
$cfg = montar($pdo);
$out = correr($cfg, '');
chequear('lista a los que esperan', str_contains($out, 'holaMariana431'), $out);
chequear('marca cuáles parecen de prueba', str_contains($out, '(parece de prueba)'));
chequear('avisa que hay alguien que NO parece de prueba',
         str_contains($out, 'no parece(n) de prueba'),
         'es el aviso que evita borrar a un jugador real sin verlo');
chequear('no borró nada', enCola($pdo) === 3, 'una vista previa que escribe no es una vista previa');

// ===========================================================================
echo "\n=== 2. --solo-prueba respeta al jugador real ===\n";
$out = correr($cfg, '--solo-prueba --aplicar');
chequear('borró las dos de prueba', enCola($pdo) === 1, $out);
$q = $pdo->query('SELECT usuario FROM `' . CLI . '`.altas ORDER BY id')->fetchAll(PDO::FETCH_COLUMN);
chequear('y el jugador real sigue esperando', in_array('holaMariana431', $q, true));
/* Las ya creadas son historia: el espejo y los movimientos se apoyan en ellas. */
chequear('la que ya estaba creada no se tocó', in_array('holaCarlos777', $q, true),
         "borrar las 'ok' rompe el historial del alta");

// ===========================================================================
echo "\n=== 3. Sin --solo-prueba se va todo lo pendiente ===\n";
$cfg = montar($pdo);
$out = correr($cfg, '--aplicar');
chequear('la cola queda vacía', enCola($pdo) === 0, $out);
chequear('pero la ya creada sigue ahí',
         (int) $pdo->query('SELECT COUNT(*) FROM `' . CLI . "`.altas WHERE estado='ok'")->fetchColumn() === 1);
/* Que no se lo venda como un arreglo: el bot que no procesaba sigue sin
   procesar. Es el motivo por el que el usuario pidió vaciarla. */
chequear('aclara que esto NO destraba al bot', str_contains($out, 'NO destraba al bot'), $out);

// ===========================================================================
echo "\n=== 4. Un slug que no existe no borra nada ===\n";
$cfg = montar($pdo);
putenv('GP_PANEL_CONFIG=' . $cfg);
$out = (string) shell_exec('php ' . escapeshellarg(__DIR__ . '/scripts/limpiar-cola-altas.php')
                         . ' --slug=noexiste --aplicar 2>&1');
chequear('avisa que no lo encuentra', str_contains($out, 'No hay un cliente activo'), $out);
chequear('y la cola del cliente de verdad quedó intacta', enCola($pdo) === 3);

$pdo->exec('DROP DATABASE IF EXISTS `' . CTL . '`');
$pdo->exec('DROP DATABASE IF EXISTS `' . CLI . '`');
@unlink(sys_get_temp_dir() . '/t_lc_config.php');

printf("\n%s\n%d OK, %d fallas\n", str_repeat('-', 39), $ok, $fail);
exit($fail > 0 ? 1 : 0);
