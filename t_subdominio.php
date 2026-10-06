<?php
/**
 * t_subdominio.php — Mover un cliente a su subdominio sin dejarlo inaccesible.
 *
 * EL ORDEN ES TODO. El script hace tres cosas —crear el DNS, esperar a que el
 * subdominio conteste, y recién ahí tocar la base— y si se invirtiera, un fallo
 * de DNS dejaría al cliente sin NINGUNO de los dos caminos: el subdominio no
 * resuelve todavía y el path viejo ya no lo reconoce.
 *
 * Estos chequeos cuidan justamente eso: que la base se toque última, y que
 * cualquier fallo anterior deje todo exactamente como estaba.
 *
 *     T_PORT=3399 php t_subdominio.php
 */
declare(strict_types=1);

$ok = 0; $fail = 0;
function chequear(string $q, bool $c, string $d = ''): void {
    global $ok, $fail;
    if ($c) { $ok++;  printf("  OK    %s\n", $q); }
    else     { $fail++; printf("  FALLA %s   %s\n", $q, $d); }
}

$src = file_get_contents(__DIR__ . '/scripts/migrar-a-subdominio.php');

// ===========================================================================
echo "=== 1. La base se toca ÚLTIMA ===\n";
$posDns  = strpos($src, '1. DNS en Cloudflare');
$posEsp  = strpos($src, '2. Esperando a que el subdominio conteste');
$posBase = strpos($src, 'UPDATE clientes SET dominio = ?, path_tenant = 0');
chequear('primero el DNS, después la espera, al final la base',
         $posDns !== false && $posEsp !== false && $posBase !== false
         && $posDns < $posEsp && $posEsp < $posBase,
         'al revés, un fallo de DNS deja al cliente sin los dos caminos a la vez');
chequear('si el DNS falla, no se toca la base',
         str_contains($src, 'No se tocó la base: el cliente sigue funcionando como hasta ahora'));
chequear('si el subdominio no contesta, tampoco',
         str_contains($src, 'NO se tocó la base: el cliente sigue igual'));

// ===========================================================================
echo "\n=== 2. El UPDATE es el que db.php sabe leer ===\n";
/* db.php resuelve por host con `WHERE dominio = ? AND path_tenant = 0`. Si el
   script dejara path_tenant en 1, el subdominio no resolvería a nadie. */
$db = file_get_contents(__DIR__ . '/api/db.php');
chequear('db.php resuelve el host con path_tenant = 0',
         str_contains($db, 'WHERE dominio = ? AND path_tenant = 0'));
chequear('y el script deja exactamente eso',
         str_contains($src, 'SET dominio = ?, path_tenant = 0'),
         'con path_tenant en 1 el subdominio no resolvería a ningún cliente');

// ===========================================================================
echo "\n=== 3. Un 404 cuenta como 'el subdominio ya contesta' ===\n";
/* Mientras la base no se tocó, el host nuevo todavía no es de nadie: PHP
   contesta 404 "Dominio no registrado". Eso NO es un fallo -- prueba que el
   DNS resuelve y que nginx y PHP responden, que es lo único que hay que
   confirmar antes del paso 3. Exigir un 200 dejaría el script trabado para
   siempre esperando algo que recién existe después. */
chequear('se acepta cualquier respuesta que no sea un 5xx',
         str_contains($src, 'if ($hc > 0 && $hc < 500) { $anda = true; break; }'),
         'exigir 200 traba el script esperando algo que recién existe después');

// ===========================================================================
echo "\n=== 4. Sin --aplicar no escribe nada ===\n";
chequear('la vista previa corta antes del DNS y del UPDATE',
         strpos($src, "Para aplicarlo, repetí el comando con --aplicar") < $posDns);
chequear('y avisa que el link viejo deja de andar ANTES de tocar nada',
         strpos($src, 'EL LINK VIEJO DEJA DE FUNCIONAR') < $posDns,
         'enterarse después es enterarse cuando los jugadores ya no entran');

// ===========================================================================
echo "\n=== 5. Contra MySQL: la vista previa es inofensiva ===\n";
$port = getenv('T_PORT') ?: '';
if ($port === '') {
    echo "  (saltado: sin MySQL. T_PORT=3399 php t_subdominio.php)\n";
} else {
    try {
        $pdo = new PDO("mysql:host=127.0.0.1;port=$port;charset=utf8mb4", 'root', '',
                       [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
        $pdo->exec('DROP DATABASE IF EXISTS t_sub');
        $pdo->exec('CREATE DATABASE t_sub');
        $pdo->exec("CREATE TABLE t_sub.clientes (
            id INT AUTO_INCREMENT PRIMARY KEY, slug VARCHAR(60), nombre VARCHAR(120),
            dominio VARCHAR(190), path_tenant TINYINT DEFAULT 1,
            db_nombre VARCHAR(120), estado VARCHAR(20) DEFAULT 'activo')");
        $pdo->exec("INSERT INTO t_sub.clientes (slug, nombre, dominio, db_nombre)
                    VALUES ('oromaris','Oromaris','ganamoscrm.online','gp_oromaris')");

        $cfg = sys_get_temp_dir() . '/t_sub_config.php';
        file_put_contents($cfg, "<?php return ['DB_HOST'=>'127.0.0.1;port=$port','DB_NAME'=>'t_sub',"
            . "'DB_USER'=>'root','DB_PASS'=>'','CF_ZONE_NAME'=>'ganamoscrm.online'];\n");
        putenv('GP_PANEL_CONFIG=' . $cfg);

        $out = (string) shell_exec('php ' . escapeshellarg(__DIR__ . '/scripts/migrar-a-subdominio.php')
                                 . ' --slug=oromaris 2>&1');
        chequear('muestra el antes y el después',
                 str_contains($out, 'oromaris.ganamoscrm.online'), $out);
        chequear('avisa lo del link viejo', str_contains($out, 'LINK VIEJO'));
        $f = $pdo->query("SELECT dominio, path_tenant FROM t_sub.clientes WHERE slug='oromaris'")->fetch();
        chequear('y NO tocó la base',
                 $f['dominio'] === 'ganamoscrm.online' && (int)$f['path_tenant'] === 1,
                 json_encode($f));

        $out2 = (string) shell_exec('php ' . escapeshellarg(__DIR__ . '/scripts/migrar-a-subdominio.php')
                                  . ' --slug=noexiste --aplicar 2>&1');
        chequear('un slug inexistente no hace nada', str_contains($out2, 'No hay un cliente activo'), $out2);

        $pdo->exec('DROP DATABASE IF EXISTS t_sub');
        @unlink($cfg);
    } catch (Throwable $e) {
        echo '  (saltado: ' . $e->getMessage() . ")\n";
    }
}

printf("\n%s\n%d OK, %d fallas\n", str_repeat('-', 39), $ok, $fail);
exit($fail > 0 ? 1 : 0);
