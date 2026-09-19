<?php
/**
 * t_retencion.php — Cohortes y pérdida de clientes, con casos armados a mano.
 *
 * POR QUÉ ESTOS CASOS Y NO OTROS. Cada uno es una forma concreta de que un
 * número de retención mienta, y todas son fáciles de cometer:
 *
 *   1. Contar dos veces al mismo jugador porque cargó quince veces en el mes.
 *   2. Contar dos veces la MISMA carga, una por `recargas` y otra por el
 *      depósito que nuestro worker hace en el panel para meter esas fichas al
 *      juego. Eso duplicaba la plata de septiembre de 2026 (el libro decía
 *      $624.399 contra $361.380) y habría duplicado jugadores.
 *   3. Saltear un mes sin actividad, que es el dato más fuerte de todos.
 *   4. Poner a alguien en la cohorte del mes en que se REGISTRÓ en vez del mes
 *      en que cargó por primera vez: el que nunca cargó no entró al negocio y
 *      hunde la cohorte por algo que no es retención.
 *   5. Calcular la tasa de pérdida sobre los activos de ESTE mes en vez del
 *      anterior -- así el número BAJA cuando entra gente nueva, o sea que
 *      mejora justo cuando no debería.
 *
 *     T_PORT=3399 php t_retencion.php
 */
declare(strict_types=1);

/* BASE PROPIA Y VACIA, y no es capricho.
   Estas funciones cuentan JUGADORES DE TODA LA BASE -- no hay un filtro por
   prefijo que las acote. Corriendo sobre `goldpaw_demo`, que comparten todas
   las suites, cada activo que deja otro test se suma a estos numeros y las
   aserciones absolutas ("julio tiene 2 activos") fallan por algo que no tiene
   nada que ver con la retencion. Paso literalmente al escribir este archivo.
   De paso, esto prueba de verdad lo multi-cliente: la libreria corre contra una
   base que no es la de nadie y se arregla sola con lo que encuentra. */
$HOST = getenv('T_HOST') ?: '127.0.0.1';
$PORT = getenv('T_PORT') ?: '3306';
$USER = getenv('T_USER') ?: 'root';
$PASS = getenv('T_PASS') ?: '';
$BASE = getenv('T_DB')   ?: 'goldpaw_demo';
$MIA  = $BASE . '_ret';

$raiz = new PDO("mysql:host=$HOST;port=$PORT;charset=utf8mb4", $USER, $PASS,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$raiz->exec("DROP DATABASE IF EXISTS `$MIA`");
$raiz->exec("CREATE DATABASE `$MIA` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");

$pdo = new PDO("mysql:host=$HOST;port=$PORT;dbname=$MIA;charset=utf8mb4", $USER, $PASS,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$GLOBALS['pdo'] = $pdo;

/* Se COPIAN los esquemas reales en vez de escribirlos a mano: una tabla
   inventada se separa de la de verdad y el test empieza a fallar por sus
   propias columnas, no por lo que vino a probar. */
foreach (['recargas', 'movimientos', 'operaciones_panel', 'usuarios'] as $tb) {
    $pdo->exec("CREATE TABLE `$MIA`.`$tb` LIKE `$BASE`.`$tb`");
}
if (!function_exists('cfg')) { function cfg($c, $d = '') { return $d; } }
require_once __DIR__ . '/api/publicidad_lib.php';
require_once __DIR__ . '/api/retencion_lib.php';

$ok = 0; $fail = 0;
function chequear(string $q, bool $c, string $d = ''): void {
    global $ok, $fail;
    if ($c) { $ok++;  printf("  OK    %s\n", $q); }
    else     { $fail++; printf("  FALLA %s   %s\n", $q, $d); }
}

/* ------------------------------------------------------------------ fixture */
const PRE = 't_ret_';
$limpiar = function () use ($pdo): void {
    $like = PRE . '%';
    foreach (['recargas' => 'usuario', 'movimientos' => 'usuario',
              'usuarios' => 'username'] as $t => $col) {
        try { $pdo->prepare("DELETE FROM $t WHERE $col LIKE ?")->execute([$like]); } catch (Throwable $e) {}
    }
    try { $pdo->prepare("DELETE FROM operaciones_panel WHERE username LIKE ?")->execute([$like]); }
    catch (Throwable $e) {}
};
$limpiar();

/* Los meses son relativos a HOY: si fueran fijos, el test empezaría a fallar
   solo con que pase el tiempo -- y fallaría por el calendario, no por el
   código, que es la peor clase de test roto. */
$mes = fn(int $atras) => date('Y-m', strtotime("first day of -$atras month"));
$dia = fn(int $atras) => date('Y-m-d 12:00:00', strtotime($mes($atras) . '-05 12:00:00'));

$nref = 0;
/** Una carga por el camino canónico (recargas acreditadas). */
$carga = function (string $u, int $atras) use ($pdo, $dia, &$nref): void {
    $pdo->prepare(
        "INSERT INTO recargas (usuario, coins, monto_pedido, monto_base, estado,
                               referencia, creada_en, acreditada_en)
         VALUES (?, 1000, 1000, 1000, 'acreditada', ?, ?, ?)"
    )->execute([$u, PRE . (++$nref), $dia($atras), $dia($atras)]);
};
/** Un depósito del libro. $propio=true -> lo pidió el jugador; false -> 'direct deposit'. */
$libro = function (string $u, int $atras, bool $propio = true) use ($pdo, $dia, &$nref): void {
    $pdo->prepare(
        "INSERT INTO operaciones_panel (payment_id, tipo, username, monto, comentario, cuando)
         VALUES (?, 0, ?, 1000, ?, ?)"
    )->execute([900000 + (++$nref), $u, $propio ? '' : 'direct deposit', $dia($atras)]);
};

/* =========================================================================
   1. UN JUGADOR CUENTA UNA VEZ POR MES
   ========================================================================= */
echo "\n=== 1. Cargar quince veces no son quince jugadores ===\n";
$u1 = PRE . 'repetidor';
for ($i = 0; $i < 5; $i++) { $carga($u1, 3); }

$serie = ret_mes_a_mes($pdo, 6);
$m3 = null;
foreach ($serie as $s) { if ($s['mes'] === $mes(3)) { $m3 = $s; } }
chequear('cinco cargas del mismo jugador = 1 activo',
         $m3 !== null && (int)$m3['activos'] === 1, json_encode($m3));

/* =========================================================================
   2. LA MISMA CARGA POR LAS DOS FUENTES NO SON DOS
   ========================================================================= */
echo "\n=== 2. El worker metiendo las fichas al juego no es otra carga ===\n";
/* El recorrido real: el jugador transfiere (queda en `recargas`) y después
   nuestro worker deposita esas fichas en el panel, lo que el libro registra
   como 'direct deposit'. Son UNA operación del jugador. */
$libro($u1, 3, false);
$serie = ret_mes_a_mes($pdo, 6);
foreach ($serie as $s) { if ($s['mes'] === $mes(3)) { $m3 = $s; } }
chequear('el depósito "direct deposit" NO agrega un activo',
         (int)$m3['activos'] === 1,
         'si cuenta, cada transferencia aparece dos veces: ' . json_encode($m3));

/* Y el que SÍ nació de un pedido del jugador, sí cuenta. */
$u2 = PRE . 'delpanel';
$libro($u2, 3, true);
$serie = ret_mes_a_mes($pdo, 6);
foreach ($serie as $s) { if ($s['mes'] === $mes(3)) { $m3 = $s; } }
chequear('el depósito pedido por el jugador SÍ cuenta',
         (int)$m3['activos'] === 2, json_encode($m3));

/* =========================================================================
   3. NUEVOS / RETENIDOS / PERDIDOS / RECUPERADOS
   ========================================================================= */
echo "\n=== 3. Las cuatro piezas del mes ===\n";
$limpiar();
$a = PRE . 'sigue';      // hace 3 y hace 2 -> retenido
$b = PRE . 'sepierde';   // solo hace 3     -> perdido
$c = PRE . 'llega';      // solo hace 2     -> nuevo
$d = PRE . 'vuelve';     // hace 3 y hace 1 -> recuperado en el mes 1
$carga($a, 3); $carga($a, 2);
$carga($b, 3);
$carga($c, 2);
$carga($d, 3); $carga($d, 1);

$serie = ret_mes_a_mes($pdo, 6);
$por = [];
foreach ($serie as $s) { $por[$s['mes']] = $s; }

$x = $por[$mes(2)] ?? null;
chequear('mes -2: 1 retenido (el que siguió)', $x && (int)$x['retenidos'] === 1, json_encode($x));
chequear('mes -2: 1 nuevo (el que llegó)',     $x && (int)$x['nuevos'] === 1, json_encode($x));
chequear('mes -2: 2 perdidos (el que se fue y el que faltó ese mes)',
         $x && (int)$x['perdidos'] === 2, json_encode($x));

$y = $por[$mes(1)] ?? null;
chequear('mes -1: el que volvió cuenta como RECUPERADO, no como nuevo',
         $y && (int)$y['recuperados'] === 1 && (int)$y['nuevos'] === 0, json_encode($y));

/* LA TASA ES SOBRE EL MES ANTERIOR. En el mes -2 había 3 activos el mes previo
   (a, b, d) y se perdieron 2 -> 66,7%. Si se calculara sobre los activos de
   ESTE mes (2), daría 100% -- y peor: bajaría al entrar gente nueva. */
chequear('la tasa de pérdida se mide sobre la base del mes ANTERIOR (66.7%)',
         $x && abs((float)$x['pct_perdidos'] - 66.7) < 0.1,
         'dio ' . var_export($x['pct_perdidos'] ?? null, true));

/* =========================================================================
   4. UN MES EN CERO ES UN DATO, NO UN MES QUE NO EXISTE
   ========================================================================= */
echo "\n=== 4. El mes sin nadie aparece igual ===\n";
$limpiar();
$e = PRE . 'unico';
$carga($e, 4);            // solo hace 4 meses: los de al lado quedan vacíos
$serie = ret_mes_a_mes($pdo, 6);
$meses = array_column($serie, 'mes');
chequear('el mes siguiente al último activo figura en la serie',
         in_array($mes(3), $meses, true),
         'saltearlo esconde la caída y hace que el mes siguiente se compare '
         . 'contra uno viejo: ' . implode(',', $meses));
foreach ($serie as $s) { if ($s['mes'] === $mes(3)) { $z = $s; } }
chequear('y figura con 0 activos y 1 perdido',
         isset($z) && (int)$z['activos'] === 0 && (int)$z['perdidos'] === 1, json_encode($z ?? null));

/* =========================================================================
   5. COHORTES: la cohorte es el mes de la PRIMERA CARGA
   ========================================================================= */
echo "\n=== 5. Cohortes ===\n";
$limpiar();
/* Dos llegan hace 3 meses; uno sigue hace 2, el otro no. Retención +1 = 50%. */
$p = PRE . 'coh_queda';
$q = PRE . 'coh_seva';
$carga($p, 3); $carga($p, 2);
$carga($q, 3);
/* Y uno que SE REGISTRÓ hace 3 meses pero nunca cargó: no tiene que estar en
   ninguna cohorte. */
$pdo->prepare("INSERT INTO usuarios (id, username, balance, coins, bonus)
               VALUES (990900, ?, 0, 0, 0)")->execute([PRE . 'nunca_cargo']);

$c = ret_cohortes($pdo, 6);
$coh = null;
foreach ($c['cohortes'] as $x) { if ($x['cohorte'] === $mes(3)) { $coh = $x; } }
chequear('la cohorte tiene 2 jugadores, no 3', $coh && (int)$coh['nuevos'] === 2,
         'el que se registró y nunca cargó no entró al negocio: ' . json_encode($coh));
chequear('retención +0 = 100%', $coh && (float)$coh['serie'][0]['pct'] === 100.0);
chequear('retención +1 = 50% (uno de los dos siguió)',
         $coh && abs((float)$coh['serie'][1]['pct'] - 50.0) < 0.1,
         json_encode($coh['serie'][1] ?? null));
chequear('retención +2 = 0%', $coh && (float)$coh['serie'][2]['pct'] === 0.0);

/* La cohorte del mes en curso viene marcada: su último punto todavía va a
   subir, y sin la marca la pantalla la dibuja como una caída que no existe. */
$carga(PRE . 'reciencito', 0);
$c = ret_cohortes($pdo, 6);
$hoyCoh = null;
foreach ($c['cohortes'] as $x) { if ($x['cohorte'] === date('Y-m')) { $hoyCoh = $x; } }
chequear('la cohorte del mes en curso se marca como tal',
         $hoyCoh !== null && !empty($hoyCoh['en_curso']), json_encode($hoyCoh));
chequear('y como incompleta (no tiene un +1 todavía)',
         $hoyCoh !== null && empty($hoyCoh['completa']));

/* =========================================================================
   6. MULTI-CLIENTE: sin el libro del panel tiene que seguir andando
   ========================================================================= */
echo "\n=== 6. Un cliente sin el libro (migración 67 sin correr) ===\n";
$sql = ret_sql_actividad($pdo);
chequear('con libro, la consulta lo incluye', str_contains($sql, 'operaciones_panel'));
chequear('y filtra los direct deposit', str_contains($sql, "COALESCE(o.comentario, '') = ''"),
         'sin ese filtro se duplica cada transferencia');
chequear('la definición canónica de carga se usa tal cual',
         str_contains($sql, 'recargas') && str_contains($sql, 'monto_pedido'),
         'la plata sigue saliendo de publicidad_sql_cargas(), no de una copia');

$pdo = null;
$raiz->exec("DROP DATABASE IF EXISTS `$MIA`");
printf("\n---------------------------------------\n%d OK, %d fallas\n", $ok, $fail);
exit($fail > 0 ? 1 : 0);
