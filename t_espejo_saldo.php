<?php
/**
 * t_espejo_saldo.php — El saldo del jugador en el CRM: que se actualice, y que
 *                      cuando no, SE NOTE.
 *
 * EL BUG (reportado por Nahuel el 15/09/2026): "el saldo del jugador tarda en
 * actualizarse o no se actualiza". `usuarios.balance` es un espejo del saldo
 * real en ganamos, y lo escribia UNA sola cosa: el contenedor
 * `ganamos-bot-sync`, que en el compose esta detras de `profiles: ["sync"]`
 * -- o sea APAGADO salvo que alguien lo levante a mano. Con eso apagado, el
 * numero de la ficha solo se movia cuando lo moviamos nosotros (el ajuste
 * optimista de acciones_cola), y todo lo que el jugador ganaba o perdia
 * jugando no llegaba nunca.
 *
 * Y no habia forma de darse cuenta mirando la pantalla: "$4.280" se veia igual
 * con dos segundos de antiguedad que con dos dias. Sobre ese numero se decide
 * cuanto pagarle en un retiro.
 *
 * Lo que blinda este test:
 *   - la migracion 68 es idempotente (provisionar.php la corre en cada deploy);
 *   - usuarios_sync.php marca CUANDO se leyo el saldo, incluso si el balance
 *     vino igual -- que es el caso que `actualizado_en` no cubre;
 *   - sin la migracion corrida, el espejo entra igual (nada de 500);
 *   - ficha_usuario() le pasa la edad al CRM, y null cuando nunca se leyo.
 *
 *     php t_espejo_saldo.php
 */
declare(strict_types=1);

$pdo = new PDO(
    'mysql:host=' . (getenv('T_HOST') ?: '127.0.0.1')
        . ';port=' . (getenv('T_PORT') ?: '3306')
        . ';dbname=' . (getenv('T_DB') ?: 'goldpaw_demo') . ';charset=utf8mb4',
    getenv('T_USER') ?: 'root', getenv('T_PASS') ?: '',
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
);
$GLOBALS['pdo'] = $pdo;

$ok = 0; $fallas = 0;
function ok(bool $cond, string $que, string $detalle = ''): void {
    global $ok, $fallas;
    if ($cond) { $ok++; echo "  OK    $que\n"; }
    else { $fallas++; echo "  FALLA $que" . ($detalle !== '' ? " -- $detalle" : '') . "\n"; }
}

/* ------------------------------------------------------------------ */
/* El endpoint de verdad, no una copia.
 *
 * Se evalua el ARCHIVO dentro de una funcion, con tres retoques: los
 * `require __DIR__` (que traerian la config real), el `php://input` (en CLI
 * viene vacio) y los `exit;` -- que dentro de una funcion pasan a ser
 * `return;` y asi no matan el proceso del test.
 *
 * Vale la pena la gimnasia: probar una copia del SQL no habria agarrado nada.
 * Lo que hay que saber es si ESE archivo, tal como esta desplegado, escribe la
 * columna. */
const CLAVE_TEST = 'clave-de-prueba-larguisima-1234567890';
function cfg($clave, $default = '') { return $clave === 'BOT_API_KEY' ? CLAVE_TEST : $default; }

function correr_sync(array $usuarios): array {
    global $pdo;
    $src = file_get_contents(__DIR__ . '/api/usuarios_sync.php');
    $src = preg_replace('/^\s*<\?php/', '', $src, 1);
    $src = preg_replace('/^\s*declare\(strict_types=1\);/m', '', $src, 1);
    $src = preg_replace('/^\s*require(_once)?\s+__DIR__[^;]+;/m', '', $src);
    $src = str_replace("file_get_contents('php://input')",
                       'json_encode($GLOBALS["__cuerpo"])', $src);
    $src = preg_replace('/^(\s*)exit;/m', '$1return;', $src);
    /* header() en CLI, con salida ya emitida, tira un warning que cae DENTRO
       del buffer y rompe el json_decode. Son cabeceras HTTP: no hay nada de
       este archivo que probar ahi. */
    /* La linea ENTERA: el Content-Type lleva un ";" adentro del string
       ("application/json; charset=utf-8"), asi que cortar en el primer punto y
       coma parte la llamada al medio y deja basura que no compila. */
    $src = preg_replace('/^[ 	]*header\(.*\);[ 	]*$/m', '', $src);

    $GLOBALS['__cuerpo'] = ['usuarios' => $usuarios];
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_SERVER['HTTP_X_API_KEY'] = CLAVE_TEST;

    $correr = function () use ($src, $pdo) {
        ob_start();
        try { eval($src); }
        finally { $salida = ob_get_clean(); }
        return json_decode($salida ?: '{}', true) ?: [];
    };
    return $correr();
}

/** ficha_usuario() sale de crm.php, que es un endpoint: se extrae la funcion
 *  sola en vez de requerir el archivo (eso dispararia el despacho HTTP). */
function cargar_ficha_usuario(): void {
    $src = file_get_contents(__DIR__ . '/api/crm.php');
    $ini = strpos($src, 'function ficha_usuario(PDO $pdo, string $usuario): ?array');
    if ($ini === false) { fwrite(STDERR, "No encontre ficha_usuario() en crm.php\n"); exit(1); }
    $fin = strpos($src, "\n}", $ini);
    eval(substr($src, $ini, $fin - $ini + 2));
}
// Lo unico que ficha_usuario() llama de afuera.
function bono_pendiente_total(PDO $pdo, string $u): array {
    return ['fichas' => 0, 'pct_cantidad' => 0, 'giro_cantidad' => 0];
}
cargar_ficha_usuario();

$U = 't_espejo_' . substr((string)time(), -6);
$ID = 990000000 + (int)substr((string)time(), -6);
$pdo->prepare("DELETE FROM usuarios WHERE username = ? OR id = ?")->execute([$U, $ID]);

$visto = function () use ($pdo, $U) {
    $st = $pdo->prepare("SELECT saldo_visto_en FROM usuarios WHERE username = ?");
    $st->execute([$U]);
    return $st->fetchColumn();
};
$hayColumna = function () use ($pdo): bool {
    try { $pdo->query("SELECT saldo_visto_en FROM usuarios LIMIT 0"); return true; }
    catch (Throwable $e) { return false; }
};

echo "\n=== 1. La migracion 68 ===\n";
/* provisionar.php corre TODAS las migraciones en cada deploy, asi que correrla
   dos veces tiene que dar lo mismo: un ALTER que falla la deja figurando como
   rota para siempre en el reporte, y un reporte siempre en rojo deja de leerse. */
$sql = file_get_contents(__DIR__ . '/api/sql/68_saldo_visto.sql');
$pdo->exec($sql);
ok($hayColumna(), 'la columna saldo_visto_en existe despues de migrar');
$reventó = false;
try { $pdo->exec($sql); } catch (Throwable $e) { $reventó = true; }
ok(!$reventó, 'correrla de nuevo no explota (provisionar.php la corre en cada deploy)');

echo "\n=== 2. El sync marca CUANDO se leyo el saldo ===\n";
$r = correr_sync([['id' => $ID, 'username' => $U, 'balance' => 4280, 'total_deposits' => 0,
                   'role' => 'player', 'is_banned' => false, 'creation_date' => '2026-01-02T03:04:05']]);
ok(!empty($r['ok']) && (int)($r['guardados'] ?? 0) === 1,
   'el espejo guarda al jugador', json_encode($r));
$st = $pdo->prepare("SELECT balance, saldo_visto_en FROM usuarios WHERE username = ?");
$st->execute([$U]);
$fila = $st->fetch();
ok($fila && (float)$fila['balance'] === 4280.0, 'con su saldo');
ok($fila && $fila['saldo_visto_en'] !== null, 'y con la marca de cuando lo leimos');

/* EL CASO QUE `actualizado_en` NO CUBRE, y por el que existe esta columna:
   el jugador no movio un peso, asi que la fila queda igual. `actualizado_en`
   es ON UPDATE CURRENT_TIMESTAMP y MySQL no lo dispara si nada cambio -- o
   sea que un saldo quieto figuraba visto por ultima vez hace una semana,
   aunque lo hubieramos leido recien. La edad tiene que medir la LECTURA. */
$pdo->prepare("UPDATE usuarios SET saldo_visto_en = DATE_SUB(NOW(), INTERVAL 3 HOUR) WHERE username = ?")
    ->execute([$U]);
$viejo = $visto();
correr_sync([['id' => $ID, 'username' => $U, 'balance' => 4280, 'total_deposits' => 0,
              'role' => 'player', 'is_banned' => false, 'creation_date' => '2026-01-02T03:04:05']]);
ok($visto() !== $viejo && strtotime((string)$visto()) > strtotime((string)$viejo),
   'releer el MISMO saldo tambien refresca la marca', "antes=$viejo despues=" . $visto());

echo "\n=== 3. Sin la migracion, el espejo entra igual ===\n";
/* Una base a la que todavia no le llego la migracion tiene que seguir
   sincronizando. Si no, el deploy del CRM rompe el espejo de cada cliente
   hasta que corra el cron de provisionar.php. */
$pdo->exec("ALTER TABLE usuarios DROP COLUMN saldo_visto_en");
$pdo->prepare("DELETE FROM usuarios WHERE username = ?")->execute([$U]);
$r = correr_sync([['id' => $ID, 'username' => $U, 'balance' => 777, 'total_deposits' => 0,
                   'role' => 'player', 'is_banned' => false, 'creation_date' => null]]);
ok(!empty($r['ok']) && (int)($r['guardados'] ?? 0) === 1,
   'sin la columna, el sync sigue guardando', json_encode($r));

$f = ficha_usuario($pdo, $U);
ok(is_array($f) && array_key_exists('saldo_visto_hace', $f) && $f['saldo_visto_hace'] === null,
   'y la ficha sale igual, con la edad en null',
   json_encode($f['saldo_visto_hace'] ?? 'sin clave'));
$pdo->exec($sql);   // la volvemos a poner

echo "\n=== 4. La edad que ve el CRM ===\n";
$pdo->prepare("UPDATE usuarios SET saldo_visto_en = NULL WHERE username = ?")->execute([$U]);
$f = ficha_usuario($pdo, $U);
ok($f['saldo_visto_hace'] === null, 'nunca leido -> null (el CRM lo pinta como "sin leer")');

$pdo->prepare("UPDATE usuarios SET saldo_visto_en = DATE_SUB(NOW(), INTERVAL 900 SECOND) WHERE username = ?")
    ->execute([$U]);
$f = ficha_usuario($pdo, $U);
ok(is_int($f['saldo_visto_hace']) && abs($f['saldo_visto_hace'] - 900) <= 5,
   'leido hace 15 min -> ~900 segundos', (string)($f['saldo_visto_hace'] ?? 'null'));

$pdo->prepare("UPDATE usuarios SET saldo_visto_en = NOW() WHERE username = ?")->execute([$U]);
$f = ficha_usuario($pdo, $U);
ok(is_int($f['saldo_visto_hace']) && $f['saldo_visto_hace'] <= 5,
   'recien leido -> casi cero', (string)($f['saldo_visto_hace'] ?? 'null'));

/* Y el saldo crudo, que es de donde sale "Retirar todo" en el CRM: tiene que
   viajar como numero y no como el texto formateado de la tarjeta. */
ok(isset($f['saldo']) && is_float($f['saldo']) && $f['saldo'] === 777.0,
   'el saldo viaja crudo (lo necesita el boton "Retirar todo")',
   var_export($f['saldo'] ?? null, true));

$pdo->prepare("DELETE FROM usuarios WHERE username = ?")->execute([$U]);

echo "\n---------------------------------------\n";
echo "$ok OK, $fallas fallas\n";
exit($fallas ? 1 : 0);
