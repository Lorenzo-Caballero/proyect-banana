<?php
/**
 * t_bases.php — Devolverle su base a un cliente no puede dejar a nadie sin la suya.
 *
 * EL CASO REAL que reproduce (24/09/2026, todavía sin resolver el 03/10): el
 * cliente `ganamos` tenía `db_nombre = u722310012_fauno888`, que es la base de
 * `ganamoscrm` — la nuestra. Su CRM resolvía a nuestros jugadores, nuestra
 * plata y nuestras conversaciones. Lo único que contenía la fuga era que su
 * operador no existía en nuestra base.
 *
 * LO QUE ESTOS CHEQUEOS CUIDAN. El script mueve clientes entre bases: es de lo
 * más destructivo que se puede correr en este proyecto.
 *
 *   1. QUE SE MUEVA EL INTRUSO Y NO EL DUEÑO. Si mueve al que no era, el que
 *      venía trabajando pierde de vista sus datos.
 *   2. QUE NADIE QUEDE SIN BASE. Si se mueven TODOS los de un conflicto, la
 *      base con los datos queda sin dueño declarado.
 *   3. QUE NO INVENTE DESTINOS. Un cliente sin `gp_<slug>` creada no se toca.
 *   4. QUE SIN --aplicar NO ESCRIBA NADA.
 *
 *     T_PORT=3399 php t_bases.php
 */
declare(strict_types=1);

$ok = 0; $fail = 0;
function chequear(string $q, bool $c, string $d = ''): void {
    global $ok, $fail;
    if ($c) { $ok++;  printf("  OK    %s\n", $q); }
    else     { $fail++; printf("  FALLA %s   %s\n", $q, $d); }
}

$port = getenv('T_PORT') ?: '';
if ($port === '') {
    echo "Hace falta MySQL: T_PORT=3399 php t_bases.php\n";
    exit(0);
}
try {
    $pdo = new PDO("mysql:host=127.0.0.1;port=$port;charset=utf8mb4", 'root', '',
                   [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
} catch (Throwable $e) {
    echo "No pude conectar a MySQL en el puerto $port\n";
    exit(0);
}

$CTL = 't_ctl';
$NUESTRA = 't_fauno888';     // la base heredada: NO sigue el patrón gp_<slug>
$SUYA    = 'gp_cliente';     // la que le corresponde al intruso por su slug

/** Deja el escenario limpio y devuelve el path del config temporal. */
function montar(PDO $pdo, string $ctl, string $nuestra, string $suya, bool $conSuBase): string
{
    foreach ([$ctl, $nuestra, $suya] as $d) { $pdo->exec("DROP DATABASE IF EXISTS `$d`"); }
    $pdo->exec("CREATE DATABASE `$ctl`");
    $pdo->exec("CREATE DATABASE `$nuestra`");
    $pdo->exec("CREATE TABLE `$nuestra`.usuarios (id INT PRIMARY KEY)");
    if ($conSuBase) {
        $pdo->exec("CREATE DATABASE `$suya`");
        $pdo->exec("CREATE TABLE `$suya`.usuarios (id INT PRIMARY KEY)");
    }
    $pdo->exec("CREATE TABLE `$ctl`.clientes (
        id INT AUTO_INCREMENT PRIMARY KEY, slug VARCHAR(60), nombre VARCHAR(120),
        db_nombre VARCHAR(120), estado VARCHAR(20) DEFAULT 'activo')");
    // El nuestro primero (id menor), el intruso después: igual que en producción.
    $pdo->exec("INSERT INTO `$ctl`.clientes (slug, nombre, db_nombre) VALUES
        ('ganamoscrm','Nosotros','$nuestra'), ('cliente','Un cliente','$nuestra')");

    $cfg = sys_get_temp_dir() . '/t_panel_config.php';
    file_put_contents($cfg, "<?php return ['DB_HOST'=>'127.0.0.1;port=" . (getenv('T_PORT'))
        . "','DB_NAME'=>'$ctl','DB_USER'=>'root','DB_PASS'=>''];\n");
    return $cfg;
}

function correr(string $cfg, bool $aplicar): string
{
    /* putenv y no `set X=... &&`: en cmd eso se lleva el espacio anterior al
       `&&` adentro del valor, y el script buscaba un archivo con un espacio al
       final del nombre. El hijo hereda el entorno del padre. */
    putenv('GP_PANEL_CONFIG=' . $cfg);
    $cmd = 'php ' . escapeshellarg(__DIR__ . '/scripts/arreglar-bases-compartidas.php')
         . ($aplicar ? ' --aplicar' : '') . ' 2>&1';
    return (string) shell_exec($cmd);
}

function dbDe(PDO $pdo, string $ctl, string $slug): string
{
    $q = $pdo->prepare("SELECT db_nombre FROM `$ctl`.clientes WHERE slug = ?");
    $q->execute([$slug]);
    return (string) $q->fetchColumn();
}

// ===========================================================================
echo "=== 1. Vista previa: muestra y NO toca ===\n";
$cfg = montar($pdo, $CTL, $NUESTRA, $SUYA, true);
$out = correr($cfg, false);
chequear('dice que la base está compartida', str_contains($out, 'la comparten'), $out);
chequear('propone mover al CLIENTE, no a nosotros',
         str_contains($out, 'cliente: SE MUEVE a ' . $SUYA), $out);
chequear('y dice que nosotros nos quedamos',
         str_contains($out, 'ganamoscrm: SE QUEDA'),
         'moverlo al dueño le saca de vista los datos con los que trabaja');
chequear('sin --aplicar no escribió nada',
         dbDe($pdo, $CTL, 'cliente') === $NUESTRA,
         'la vista previa tiene que ser inofensiva');

// ===========================================================================
echo "\n=== 2. Aplicado: cada uno a su base ===\n";
$out = correr($cfg, true);
chequear('el cliente quedó en su propia base',
         dbDe($pdo, $CTL, 'cliente') === $SUYA, $out);
chequear('y NOSOTROS seguimos en la nuestra',
         dbDe($pdo, $CTL, 'ganamoscrm') === $NUESTRA,
         'si se mueve el dueño, el arreglo es peor que el problema');
chequear('lo informa', str_contains($out, 'OK  cliente -> ' . $SUYA), $out);

// ===========================================================================
echo "\n=== 3. Sin base propia, no se inventa un destino ===\n";
/* Si `gp_<slug>` no existe, no hay a dónde mandarlo: apuntarlo a una base
   inexistente lo deja sin CRM, que es peor que el conflicto. */
$cfg = montar($pdo, $CTL, $NUESTRA, $SUYA, false);
$out = correr($cfg, true);
chequear('no mueve a quien no tiene base propia creada',
         dbDe($pdo, $CTL, 'cliente') === $NUESTRA, $out);
chequear('y lo dice en vez de inventar',
         str_contains($out, 'no existe ' . $SUYA), $out);

// ===========================================================================
echo "\n=== 4. Nunca deja una base sin dueño ===\n";
/* Los DOS con base propia: moverlos a ambos dejaría la base compartida --donde
   están los datos reales-- sin ningún cliente declarado. */
$cfg = montar($pdo, $CTL, $NUESTRA, $SUYA, true);
$pdo->exec("DROP DATABASE IF EXISTS `gp_ganamoscrm`");
$pdo->exec("CREATE DATABASE `gp_ganamoscrm`");
$pdo->exec("CREATE TABLE `gp_ganamoscrm`.usuarios (id INT PRIMARY KEY)");
$out = correr($cfg, true);
chequear('frena si se moverían TODOS', str_contains($out, 'FRENO'), $out);
chequear('y no movió a ninguno',
         dbDe($pdo, $CTL, 'cliente') === $NUESTRA
         && dbDe($pdo, $CTL, 'ganamoscrm') === $NUESTRA,
         'esa base tiene los datos: alguien tiene que quedarse con ella');

// ---- limpieza
foreach ([$CTL, $NUESTRA, $SUYA, 'gp_ganamoscrm'] as $d) {
    $pdo->exec("DROP DATABASE IF EXISTS `$d`");
}
@unlink(sys_get_temp_dir() . '/t_panel_config.php');

printf("\n%s\n%d OK, %d fallas\n", str_repeat('-', 39), $ok, $fail);
exit($fail > 0 ? 1 : 0);
