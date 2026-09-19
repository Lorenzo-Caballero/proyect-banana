<?php
/**
 * t_bono_pendiente.php — El bono pendiente se aplica CON la carga, entre
 * POR DONDE entre la carga, y la ficha no muestra pendientes fantasma.
 *
 * Sale de la auditoría del 18/09/2026 ("a veces la Info de jugador muestra
 * cualquier cosa como bono pendiente"), que encontró dos agujeros:
 *
 *  1. Un giro de cortesía USADO nunca marcaba su fila de `bonos_pendientes`
 *     como aplicada (el único UPDATE a 'aplicado' filtra fichas/pct), así que
 *     cada giro usado sumaba "1×giro" pendiente en la ficha PARA SIEMPRE.
 *
 *  2. Los bonos pendientes solo se aplicaban en rl_acreditar() (camino B).
 *     El que ganaba en la ruleta y después cargaba por el botón «Depósitos»
 *     del juego (camino A) o con una carga manual del CRM no cobraba NUNCA.
 *
 *     T_PORT=3399 php t_bono_pendiente.php
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
if (!function_exists('cfg')) { function cfg($c, $d = '') { return $d; } }
require_once __DIR__ . '/api/config_crm.php';
require_once __DIR__ . '/api/fichas_lib.php';
require_once __DIR__ . '/api/crm_lib.php';
require_once __DIR__ . '/api/crm_notificaciones.php';

$ok = 0; $fail = 0;
function chequear(string $q, bool $c, string $d = ''): void {
    global $ok, $fail;
    if ($c) { $ok++;  printf("  OK    %s\n", $q); }
    else     { $fail++; printf("  FALLA %s   %s\n", $q, $d); }
}
$U = 'test_bpend';
function limpiar(PDO $pdo, string $u): void {
    foreach (['acciones_saldo', 'movimientos', 'bonos_pendientes',
              'ruleta_giros_cortesia', 'notificaciones'] as $t) {
        try { $pdo->prepare("DELETE FROM $t WHERE usuario = ?")->execute([$u]); }
        catch (Throwable $e) {}
    }
    $pdo->prepare("DELETE FROM usuarios WHERE username = ?")->execute([$u]);
    $pdo->prepare("INSERT INTO usuarios (id, username, coins, bonus, balance) VALUES (987654393, ?, 0, 0, 0)")
        ->execute([$u]);
}
function fila(PDO $pdo, string $sql, array $p = []): array {
    $st = $pdo->prepare($sql); $st->execute($p);
    return $st->fetch() ?: [];
}

// ===========================================================================
echo "=== 1. El premio de la ruleta entra como PENDIENTE, no acreditado ===\n";
limpiar($pdo, $U);
$r = crmnotif_bono_crear($pdo, $U, 'fichas', 1000, 'ruleta');
chequear('se crea el bono pendiente', !empty($r['ok']), json_encode($r));
$u = fila($pdo, "SELECT bonus FROM usuarios WHERE username = ?", [$U]);
chequear('y usuarios.bonus sigue en 0 (nada se acredito al reclamar)', (int)$u['bonus'] === 0);
$b = fila($pdo, "SELECT * FROM bonos_pendientes WHERE usuario = ?", [$U]);
chequear("tipo fichas, valor 1000, prometido_por 'ruleta', pendiente",
         ($b['tipo'] ?? '') === 'fichas' && (int)($b['valor'] ?? 0) === 1000
         && ($b['prometido_por'] ?? '') === 'ruleta' && ($b['estado'] ?? '') === 'pendiente');

/* Y ruleta.php de verdad hace esto (posicional: es un endpoint, no se puede
   requerir). El fallback a crm_cargar queda para bases sin la migracion 33. */
$srcRul = file_get_contents(__DIR__ . '/api/ruleta.php');
chequear('ruleta.php crea el pendiente en el reclamo',
         substr_count($srcRul, "crmnotif_bono_crear(") >= 2,
         'tienen que llamarlo el reclamo Y la cortesia');
chequear('el widget avisa "con tu próxima carga" cuando es pendiente',
         str_contains(file_get_contents(__DIR__ . '/landing/widget.js'),
                      'Se acreditan solos junto con tu próxima carga'));

// ===========================================================================
echo "\n=== 2. La carga del camino A / manual TAMBIEN aplica el pendiente ===\n";
/* Antes solo rl_acreditar (camino B) los aplicaba: este es el agujero 2. */
$monto = crmnotif_bono_aplicar_fuera_de_recarga($pdo, $U, 5000);
chequear('devuelve el monto aplicado (1000)', $monto === 1000, "monto=$monto");
$b = fila($pdo, "SELECT estado, recarga_id FROM bonos_pendientes WHERE usuario = ?", [$U]);
chequear("queda 'aplicado' con recarga_id NULL (no hubo recarga nuestra)",
         ($b['estado'] ?? '') === 'aplicado' && $b['recarga_id'] === null, json_encode($b));
$a = fila($pdo, "SELECT monto, bono_debitado FROM acciones_saldo WHERE usuario = ? ORDER BY id DESC LIMIT 1", [$U]);
chequear('y encola el deposito SOLO-BONO para que llegue al juego',
         (int)($a['monto'] ?? -1) === 1000 && (int)($a['bono_debitado'] ?? -1) === 1000,
         json_encode($a));
$u = fila($pdo, "SELECT bonus FROM usuarios WHERE username = ?", [$U]);
chequear('el contador quedo debitado (no se paga dos veces)', (int)$u['bonus'] === 0);

$monto2 = crmnotif_bono_aplicar_fuera_de_recarga($pdo, $U, 5000);
chequear('una segunda carga NO lo vuelve a aplicar', $monto2 === 0, "monto=$monto2");

/* Los DOS callers estan conectados (posicional). */
foreach (['api/peticiones_cola.php', 'api/crm_lib.php'] as $arch) {
    chequear("$arch aplica el pendiente al acreditar",
             str_contains(file_get_contents(__DIR__ . '/' . $arch),
                          'crmnotif_bono_aplicar_fuera_de_recarga('));
}

// ===========================================================================
echo "\n=== 3. El giro de cortesia usado deja de figurar pendiente ===\n";
limpiar($pdo, $U);
$r = crmnotif_bono_crear($pdo, $U, 'giro', 0, 'notif');
chequear('se crea el giro prometido', !empty($r['ok']));
$g = fila($pdo, "SELECT id, bono_pendiente_id FROM ruleta_giros_cortesia WHERE usuario = ?", [$U]);
chequear('con su fila de cortesia atada al bono', (int)($g['bono_pendiente_id'] ?? 0) > 0);

/* Lo que hace ruleta.php al usarlo (replicado aca porque es un endpoint):
   cortesia -> usado, y la fila madre -> aplicado. */
$pdo->prepare("UPDATE ruleta_giros_cortesia SET estado='usado', usado_en=NOW() WHERE id = ?")
    ->execute([(int)$g['id']]);
$pdo->prepare("UPDATE bonos_pendientes SET estado='aplicado', aplicado_en=NOW()
                WHERE id = ? AND tipo = 'giro' AND estado = 'pendiente'")
    ->execute([(int)$g['bono_pendiente_id']]);
$b = fila($pdo, "SELECT estado FROM bonos_pendientes WHERE usuario = ?", [$U]);
chequear("la fila madre queda 'aplicado': la ficha ya no muestra 1×giro fantasma",
         ($b['estado'] ?? '') === 'aplicado');

/* ruleta.php lo hace de verdad (posicional). */
chequear('ruleta.php marca el bono del giro al usarlo',
         str_contains($srcRul, "tipo = 'giro' AND estado = 'pendiente'"));
chequear('y lee bono_pendiente_id de la cortesia',
         str_contains($srcRul, 'SELECT id, bono_pendiente_id FROM ruleta_giros_cortesia'));

// ===========================================================================
echo "\n=== 4. La migracion 71 repara los giros viejos ===\n";
limpiar($pdo, $U);
$r = crmnotif_bono_crear($pdo, $U, 'giro', 0, 'notif');
$g = fila($pdo, "SELECT id, bono_pendiente_id FROM ruleta_giros_cortesia WHERE usuario = ?", [$U]);
// El estado ROTO que dejo el bug: cortesia usada, bono todavia pendiente.
$pdo->prepare("UPDATE ruleta_giros_cortesia SET estado='usado', usado_en=NOW() WHERE id = ?")
    ->execute([(int)$g['id']]);
$sql71 = file_get_contents(__DIR__ . '/api/sql/71_giro_cortesia_aplicado.sql');
$pdo->exec($sql71);
$b = fila($pdo, "SELECT estado, aplicado_en FROM bonos_pendientes WHERE usuario = ?", [$U]);
chequear('el giro usado-pero-pendiente queda aplicado', ($b['estado'] ?? '') === 'aplicado');
chequear('con la fecha del uso, no la de la migracion', !empty($b['aplicado_en']));
$pdo->exec($sql71);   // idempotente: la segunda pasada no puede fallar
chequear('correrla dos veces no rompe', true);

// ===========================================================================
echo "\n=== 5. La ficha: pendiente y contador separados ===\n";
$srcCrm = file_get_contents(__DIR__ . '/landing/crm.html');
chequear('el numero grande ya NO concatena "sin depositar"',
         !str_contains($srcCrm, 'partesBono.push(`${nf.format(u.bonus)} sin depositar`)'));
chequear('el contador va aparte, con su propio nombre',
         str_contains($srcCrm, 'acreditados sin mandar al juego'));

limpiar($pdo, $U);
$pdo->prepare("DELETE FROM usuarios WHERE username = ?")->execute([$U]);

// ===========================================================================
echo "\n=== LOS BONOS NO SE ACUMULAN ===\n";

/* Esta suite usa chequear(pregunta, condicion); el bloque de abajo se escribio
   con ok(condicion, pregunta), que es el orden de las OTRAS suites. Un puente
   de una linea en vez de dar vuelta veinte llamadas a mano y arriesgar que una
   quede al reves -- una asercion invertida pasa siempre y no protege nada. */
if (!function_exists('ok')) {
    function ok(bool $c, string $q, string $d = ''): void { chequear($q, $c, $d); }
}

/* EL PEDIDO (Nahuel, 19/09/2026): *"si a una persona le mandamos un bono del
   20% y no lo usa, al siguiente dia cuando le mandamos uno del 25%, ese debe
   ser el utilizable, no el anterior. El anterior debe ser dado de baja antes
   de enviarle uno, porque si no las personas tardarian una semana en volver y
   sumarian bonos superiores al 100%, cosa que no nos seria muy rentable"*.

   Y tenia razon en preocuparse: sin esto la plata se apilaba de DOS maneras.
   Una, `crmnotif_bono_aplicar_en_recarga` tomaba el MAS VIEJO (creado_en ASC),
   asi que el 20% de ayer le ganaba al 25% de hoy. Dos, el que no se aplicaba
   quedaba pendiente para la carga siguiente, asi que al final se pagaban
   todos, de a uno por carga. */
$W = 't_nocum_1';
$limpiarW = function () use ($pdo, $W): void {
    foreach (['usuarios' => 'username', 'bonos_pendientes' => 'usuario',
              'movimientos' => 'usuario', 'ruleta_giros_cortesia' => 'usuario'] as $tb => $col) {
        try { $pdo->prepare("DELETE FROM $tb WHERE $col = ?")->execute([$W]); } catch (Throwable $e) {}
    }
};
$limpiarW();
$pdo->prepare("INSERT INTO usuarios (id, username, balance, coins, bonus)
               VALUES (990601, ?, 0, 0, 0)")->execute([$W]);

$pendientes = function () use ($pdo, $W): array {
    $st = $pdo->prepare("SELECT tipo, valor FROM bonos_pendientes
                          WHERE usuario = ? AND estado = 'pendiente' ORDER BY id");
    $st->execute([$W]);
    return $st->fetchAll(PDO::FETCH_ASSOC);
};

crmnotif_bono_crear($pdo, $W, 'pct', 20, 'fidelizacion');
$r = crmnotif_bono_crear($pdo, $W, 'pct', 25, 'fidelizacion');
$p = $pendientes();
ok(count($p) === 1 && (int)$p[0]['valor'] === 25,
   'el segundo bono REEMPLAZA al primero: queda solo el 25%');
ok((int)($r['reemplazados'] ?? 0) === 1, 'y se informa que dio de baja uno');

/* El anterior queda con rastro, no borrado: en la ficha y en Auditoria tiene
   que verse que se le prometio un 20% y que lo reemplazo un 25%. */
$st = $pdo->prepare("SELECT COUNT(*) FROM bonos_pendientes WHERE usuario = ? AND estado = 'cancelado'");
$st->execute([$W]);
ok((int)$st->fetchColumn() === 1, 'el anterior queda dado de baja, con rastro');

/* Tampoco se acumula entre ORIGENES distintos: un bono manual del CRM sobre
   uno de la campaña es el mismo problema. */
crmnotif_bono_crear($pdo, $W, 'pct', 30, 'nahuel');
$p = $pendientes();
ok(count($p) === 1 && (int)$p[0]['valor'] === 30,
   'un bono manual tambien reemplaza al de la campaña');

/* Y fichas contra porcentaje: los dos son plata sobre la carga. */
crmnotif_bono_crear($pdo, $W, 'fichas', 500, 'nahuel');
$p = $pendientes();
ok(count($p) === 1 && $p[0]['tipo'] === 'fichas',
   'un bono de fichas tambien reemplaza al de porcentaje');

/* PERO EL GIRO DE LA RULETA NO ES DE ESA FAMILIA. Es una tirada gratis, no
   plata sobre la carga: no tiene por que perderse porque le llego un
   porcentaje, ni al reves. */
crmnotif_bono_crear($pdo, $W, 'giro', 0, 'fidelizacion');
$p = $pendientes();
ok(count($p) === 2, 'el giro de ruleta convive: no compite con la plata');
crmnotif_bono_crear($pdo, $W, 'pct', 40, 'fidelizacion');
$p = $pendientes();
$tipos = array_column($p, 'tipo');
ok(count($p) === 2 && in_array('giro', $tipos, true) && in_array('pct', $tipos, true),
   'y un porcentaje nuevo no le pisa el giro');

/* EL PREMIO DE LA RULETA ENTRA EN LA REGLA, y esto se dio vuelta una vez.
   El 19/09/2026 lo deje afuera razonando que un premio lo gano el jugador y
   no se lo mandamos nosotros; Nahuel lo corrigio ese mismo dia: *"los bonos
   que no se tienen que acumular son esos bonos clasicos, diarios y de ruleta
   y de juegos"*. La familia es UNA: todo lo que sea plata sobre la proxima
   carga compite por el mismo lugar, lo haya ganado o se lo hayamos dado. */
$limpiarW();
$pdo->prepare("INSERT INTO usuarios (id, username, balance, coins, bonus)
               VALUES (990601, ?, 0, 0, 0)")->execute([$W]);
crmnotif_bono_crear($pdo, $W, 'fichas', 800, 'ruleta');
$r = crmnotif_bono_crear($pdo, $W, 'pct', 25, 'fidelizacion');
$p = $pendientes();
ok(count($p) === 1 && (int)$p[0]['valor'] === 25,
   'el bono de campaña reemplaza al premio de ruleta pendiente');
ok((int)($r['reemplazados'] ?? 0) === 1, 'y lo informa');

$r = crmnotif_bono_crear($pdo, $W, 'fichas', 150, 'ruleta_cortesia');
$p = $pendientes();
ok(count($p) === 1 && (int)$p[0]['valor'] === 150 && (int)($r['reemplazados'] ?? 0) === 1,
   'y un premio de ruleta tambien reemplaza al bono prometido');

/* EL QUE SE APLICA ES EL ULTIMO PROMETIDO, no el mas viejo. Es el que el
   jugador acaba de leer en el aviso. */
$limpiarW();
$pdo->prepare("INSERT INTO usuarios (id, username, balance, coins, bonus)
               VALUES (990601, ?, 0, 0, 0)")->execute([$W]);
$pdo->prepare("INSERT INTO bonos_pendientes (usuario, tipo, valor, prometido_por, creado_en)
               VALUES (?, 'pct', 20, 'test', NOW() - INTERVAL 2 DAY)")->execute([$W]);
$pdo->prepare("INSERT INTO bonos_pendientes (usuario, tipo, valor, prometido_por, creado_en)
               VALUES (?, 'pct', 25, 'test', NOW())")->execute([$W]);
$monto = crmnotif_bono_aplicar_en_recarga($pdo, $W, null, 1000);
ok($monto === 250, 'con dos pendientes se aplica el MAS NUEVO (250 = 25% de 1000), dio ' . var_export($monto, true));

/* =========================================================================
   LOS BONOS PROMETIDOS VENCEN
   =========================================================================
   Habia 629 pendientes y ninguno vencia nunca. Hoy no duele --el mas viejo era
   de tres dias antes-- pero es una deuda que solo crece: quien vuelve dentro de
   seis meses cobra igual el porcentaje que la campaña le prometio una tarde.

   La guarda que protege la plata es el filtro del APPLIER, no la barrida: un
   bono vencido no se paga aunque siga figurando 'pendiente'. Eso es lo primero
   que se prueba. */
echo "\n=== LOS BONOS VENCEN ===\n";

$limpiarW();
$pdo->prepare("INSERT INTO usuarios (id, username, balance, coins, bonus)
               VALUES (990601, ?, 0, 0, 0)")->execute([$W]);

/* Un bono ya vencido, puesto a mano con fecha de ayer: la barrida todavia no
   corrio, asi que sigue 'pendiente'. Es el caso peligroso. */
$pdo->prepare("INSERT INTO bonos_pendientes (usuario, tipo, valor, prometido_por, vence_en)
               VALUES (?, 'pct', 50, 'test', NOW() - INTERVAL 1 DAY)")->execute([$W]);
$monto = crmnotif_bono_aplicar_en_recarga($pdo, $W, null, 1000);
ok($monto === 0 || $monto === null,
   'un bono VENCIDO no se paga, aunque la barrida no haya corrido todavia: dio '
   . var_export($monto, true));

/* Y sigue sin pagarse despues de la barrida, ahora ademas marcado. */
$n = crmnotif_bonos_vencer($pdo);
ok($n >= 1, 'la barrida lo marca, dio ' . $n);
$st = $pdo->prepare("SELECT estado FROM bonos_pendientes WHERE usuario = ? ORDER BY id DESC LIMIT 1");
$st->execute([$W]);
ok((string)$st->fetchColumn() === 'vencido',
   "queda 'vencido' y no 'cancelado': uno es que se le paso el tiempo y el otro "
   . "que se lo reemplazamos, y son dos problemas distintos");

/* UNO VIGENTE SI SE PAGA. Sin esto el test anterior pasaria igual con un
   applier roto que no paga nada. */
$limpiarW();
$pdo->prepare("INSERT INTO usuarios (id, username, balance, coins, bonus)
               VALUES (990601, ?, 0, 0, 0)")->execute([$W]);
$pdo->prepare("INSERT INTO bonos_pendientes (usuario, tipo, valor, prometido_por, vence_en)
               VALUES (?, 'pct', 50, 'test', NOW() + INTERVAL 5 DAY)")->execute([$W]);
$monto = crmnotif_bono_aplicar_en_recarga($pdo, $W, null, 1000);
ok($monto === 500, 'uno vigente si se paga (500 = 50% de 1000), dio ' . var_export($monto, true));

/* LOS DE ANTES DE LA MIGRACION NO VENCEN. `vence_en IS NULL` son los 629 que ya
   estaban: ponerles fecha de golpe seria anular bonos ya prometidos por chat y
   por push, y el jugador no tiene por que pagar un cambio de reglas que no vio. */
$limpiarW();
$pdo->prepare("INSERT INTO usuarios (id, username, balance, coins, bonus)
               VALUES (990601, ?, 0, 0, 0)")->execute([$W]);
$pdo->prepare("INSERT INTO bonos_pendientes (usuario, tipo, valor, prometido_por, creado_en)
               VALUES (?, 'pct', 20, 'test', NOW() - INTERVAL 300 DAY)")->execute([$W]);
crmnotif_bonos_vencer($pdo);
$st->execute([$W]);
ok((string)$st->fetchColumn() === 'pendiente',
   'uno viejo SIN fecha de vencimiento no se toca, por mas antiguo que sea');
$monto = crmnotif_bono_aplicar_en_recarga($pdo, $W, null, 1000);
ok($monto === 200, 'y se le sigue pagando, dio ' . var_export($monto, true));

/* LA FECHA SE CONGELA AL CREARLO. Si se calculara al leer, bajar el plazo en la
   config anularia de golpe bonos ya prometidos. */
$limpiarW();
$pdo->prepare("INSERT INTO usuarios (id, username, balance, coins, bonus)
               VALUES (990601, ?, 0, 0, 0)")->execute([$W]);
cfg_crm_guardar($pdo, ['bono_vence_dias' => '10'], 'test');
crmnotif_bono_crear($pdo, $W, 'pct', 30, 'test');
$st2 = $pdo->prepare("SELECT vence_en, TIMESTAMPDIFF(DAY, NOW(), vence_en) dias
                        FROM bonos_pendientes WHERE usuario = ? ORDER BY id DESC LIMIT 1");
$st2->execute([$W]);
$f = $st2->fetch(PDO::FETCH_ASSOC);
ok((int)$f['dias'] === 9 || (int)$f['dias'] === 10,
   'al crearlo le queda la fecha de la config (10 dias), dio ' . var_export($f['dias'], true));

cfg_crm_guardar($pdo, ['bono_vence_dias' => '1'], 'test');
$st2->execute([$W]);
$f2 = $st2->fetch(PDO::FETCH_ASSOC);
ok((string)$f2['vence_en'] === (string)$f['vence_en'],
   'y bajar el plazo en la config NO le mueve la fecha al que ya existia');

/* Con el vencimiento apagado (0) se sigue comportando como antes. */
cfg_crm_guardar($pdo, ['bono_vence_dias' => '0'], 'test');
crmnotif_bono_crear($pdo, $W, 'pct', 40, 'test');
$st2->execute([$W]);
ok($st2->fetch(PDO::FETCH_ASSOC)['vence_en'] === null,
   'con bono_vence_dias en 0 el bono no vence, como antes de la migracion 75');
cfg_crm_guardar($pdo, ['bono_vence_dias' => '30'], 'test');

$limpiarW();

printf("\n---------------------------------------\n%d OK, %d fallas\n", $ok, $fail);
exit($fail > 0 ? 1 : 0);
