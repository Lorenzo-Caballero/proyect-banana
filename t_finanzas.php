<?php
/**
 * t_finanzas.php — Finanzas cuenta las DOS vías de carga, no solo la del chat.
 *
 * POR QUÉ EXISTE (13/09/2026). Nueve consultas de `crm_finanzas.php` miraban
 * únicamente la tabla `recargas`, o sea el camino del chatbot. La carga que el
 * jugador pide con el botón "Depósitos" de adentro del juego no crea ninguna
 * fila ahí: la acredita la plataforma sobre el saldo real y de este lado queda
 * solo la línea en `movimientos` (origen='peticion').
 *
 * Medido en la base de producción ese día: $88.901 por transferencia contra
 * $10.100 desde el juego. Un 10% de la plata que no figuraba en los ingresos,
 * ni en la ganancia, ni en los activos, ni en la retención, ni en un gráfico.
 *
 * CÓMO SE PRUEBA. `crm_finanzas.php` es un endpoint, no una librería: al
 * incluirlo se ejecuta. Así que se le recorta la parte de arriba —las
 * funciones— y se la evalúa con stubs. Es más frágil que un require normal,
 * pero es la única forma de correr las consultas DE VERDAD contra MySQL, que
 * es lo que hace falta: `php -l` no detecta un choque de collations, y esta
 * base los tiene (ver CLAUDE.md).
 *
 *     php t_finanzas.php
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

function cfg($clave, $default = '') { return $default; }
require __DIR__ . '/api/publicidad_lib.php';

/* Se queda con todo lo que hay ANTES del despacho HTTP: ahí viven las
   funciones y nada que mande headers o corte la ejecución. */
$src = file_get_contents(__DIR__ . '/api/crm_finanzas.php');
$corte = strpos($src, '$metodo = $_SERVER');
if ($corte === false) { fwrite(STDERR, "No encontré el corte en crm_finanzas.php\n"); exit(1); }
$src = substr($src, 0, $corte);
$src = preg_replace('/^\s*<\?php/', '', $src, 1);
$src = preg_replace('/^\s*declare\(strict_types=1\);/m', '', $src, 1);
$src = preg_replace('/^\s*require(_once)?\s+__DIR__[^;]+;/m', '', $src);
$src = preg_replace('/^\s*\$operador\s*=\s*exigir_operador\(\);/m', '', $src);
eval($src);

$ok = 0; $fail = 0;
function chequear(string $q, bool $c, string $d = ''): void {
    global $ok, $fail;
    if ($c) { $ok++;  printf("  OK    %s\n", $q); }
    else     { $fail++; printf("  FALLA %s   %s\n", $q, $d); }
}

const U = 't_fin_';
$limpiar = function () use ($pdo) {
    $pdo->exec("DELETE FROM recargas    WHERE usuario LIKE '" . U . "%'");
    $pdo->exec("DELETE FROM movimientos WHERE usuario LIKE '" . U . "%'");
    $pdo->exec("DELETE FROM acciones_saldo WHERE usuario LIKE '" . U . "%'");
};
$limpiar();

$recarga = function (string $u, string $cuando, float $base, ?float $pedido = null) use ($pdo) {
    $pedido = $pedido ?? $base;
    $pdo->prepare(
        "INSERT INTO recargas (referencia, usuario, coins, monto_base, monto_pedido,
                               estado, creada_en, vence_en, acreditada_en, metodo)
         VALUES (?, ?, ?, ?, ?, 'acreditada', ?, ?, ?, 'transferencia')"
    )->execute([substr(md5($u . $cuando), 0, 12), $u, (int)$base, $base, $pedido,
                $cuando, $cuando, $cuando]);
};
$enJuego = function (string $u, string $cuando, float $monto) use ($pdo) {
    $pdo->prepare(
        "INSERT INTO movimientos (usuario, tipo, monto, motivo, origen, creado_en)
         VALUES (?, 'saldo', ?, 'test', 'peticion', ?)"
    )->execute([$u, (int)$monto, $cuando]);
};
$retiro = function (string $u, string $cuando, float $monto) use ($pdo) {
    $pdo->prepare(
        "INSERT INTO acciones_saldo (usuario, tipo, monto, estado, creada_en, ejecutada_en)
         VALUES (?, 'retirar', ?, 'hecha', ?, ?)"
    )->execute([$u, $monto, $cuando, $cuando]);
};

/* Rango en un año sin datos reales: estas funciones NO filtran por usuario
   —miden todo el negocio— así que la única forma de aislar el test es la
   ventana de tiempo. */
$D = '2019-05-01'; $H = '2019-05-31';

// ===========================================================================
echo "\n=== 1. Los ingresos suman las dos vias ===\n";
$recarga(U . 'chat',  '2019-05-10 10:00:00', 5000.0);
$enJuego(U . 'juego', '2019-05-10 11:00:00', 3000.0);

$i = fn_ingresos($pdo, $D, $H);
chequear('suma transferencia + juego', abs($i['monto'] - 8000.0) < 0.01, 'monto=' . $i['monto']);
chequear('y cuenta las dos cargas',    $i['cantidad'] === 2, 'cant=' . $i['cantidad']);
chequear('el promedio sale sobre las dos', abs($i['promedio'] - 4000.0) < 0.01,
         'prom=' . $i['promedio']);

// ===========================================================================
echo "\n=== 2. Suma monto_pedido, no monto_base ===\n";
/* Es la plata que ENTRO a la caja. En las recargas viejas difieren hasta en 99
   centavos (los centavos unicos, ya sacados) y Finanzas siempre uso la
   primera; el cambio de hoy no puede haberla movido. */
$recarga(U . 'centavos', '2019-05-11 10:00:00', 1000.0, 1000.37);
$i = fn_ingresos($pdo, $D, $H);
chequear('usa el importe transferido', abs($i['monto'] - 9000.37) < 0.001,
         'monto=' . $i['monto']);

// ===========================================================================
echo "\n=== 3. Activos y retencion ven al que carga desde el juego ===\n";
/* El recorrido natural es cargar la primera vez por el chat y las siguientes
   con el boton de adentro del juego, que esta mas a mano. Mirando solo
   `recargas`, ese jugador -- el que mejor se retuvo -- figuraba como perdido. */
chequear('los tres jugadores cuentan como activos',
         fn_activos($pdo, $D, $H) === 3, 'activos=' . fn_activos($pdo, $D, $H));

/* Carga por chat en abril y por el juego en mayo: retenido. */
$recarga(U . 'vuelve', '2019-04-15 10:00:00', 2000.0);
$enJuego(U . 'vuelve', '2019-05-15 10:00:00', 2000.0);
$r = fn_retencion($pdo, '2019-05-01', '2019-05-31');
chequear('el que volvio por el juego cuenta como retenido',
         $r['retenidos'] >= 1, json_encode($r));

// ===========================================================================
echo "\n=== 4. Los graficos por dia y por hora ===\n";
$serie = fn_serie_por_dia($pdo, '2019-05-10', '2019-05-10', 0.20);
chequear('el dia trae las dos cargas juntas',
         abs($serie[0]['ingresos'] - 8000.0) < 0.01, json_encode($serie[0]));
chequear('y los dos jugadores como activos', $serie[0]['activos'] === 2,
         'activos=' . $serie[0]['activos']);

$horas = fn_serie_por_hora($pdo, '2019-05-10', '2019-05-10');
chequear('la carga por transferencia cae en su hora', ($horas[10] ?? 0) >= 1);
chequear('la carga del juego tambien',                ($horas[11] ?? 0) >= 1);

// ===========================================================================
echo "\n=== 5. Alertas: no acusar de ganador a quien paga por el juego ===\n";
/* Sin las dos vias, este jugador figuraba retirando 9.000 sin haber cargado
   nada: una alerta falsa sobre alguien que en realidad estaba dejando plata. */
$enJuego(U . 'paga', '2019-05-20 10:00:00', 30000.0);
$retiro( U . 'paga', '2019-05-21 10:00:00',  9000.0);
$alertas = fn_alertas($pdo, $D, $H, 50000.0, 200000.0, 1000.0);
$nombres = array_column(array_filter($alertas, fn($a) => $a['tipo'] === 'mas_retiros_que_recargas'), 'usuario');
chequear('no lo marca como ganador', !in_array(U . 'paga', $nombres, true), json_encode($nombres));

/* El que SI gana tiene que seguir apareciendo -- la alerta no se rompio. */
$recarga(U . 'gana', '2019-05-22 10:00:00',  1000.0);
$retiro( U . 'gana', '2019-05-23 10:00:00', 40000.0);
$alertas = fn_alertas($pdo, $D, $H, 50000.0, 200000.0, 1000.0);
$nombres = array_column(array_filter($alertas, fn($a) => $a['tipo'] === 'mas_retiros_que_recargas'), 'usuario');
chequear('el ganador real si aparece', in_array(U . 'gana', $nombres, true), json_encode($nombres));

// ===========================================================================
echo "\n=== 6. Top de jugadores ===\n";
$top = fn_top_jugadores($pdo, $D, $H);
$fila = null;
foreach ($top as $x) { if ($x['usuario'] === U . 'paga') { $fila = $x; } }
chequear('el que cargo por el juego aparece en el top', $fila !== null);
chequear('con su carga contada', $fila && abs($fila['recargas_monto'] - 30000.0) < 0.01,
         json_encode($fila));

// ===========================================================================
echo "\n=== 7. El CSV trae las dos vias y dice cual es cual ===\n";
$det = fn_detalle_periodo($pdo, '2019-05-10', '2019-05-10');
$vias = array_count_values(array_column(array_filter($det, fn($x) => $x['tipo'] === 'recarga'), 'estado'));
chequear('una fila por transferencia', ($vias['transferencia'] ?? 0) === 1, json_encode($vias));
chequear('y una del juego',            ($vias['juego'] ?? 0) === 1, json_encode($vias));

// ===========================================================================
echo "\n=== 8. La foto historica no tiene filtro de fecha ===\n";
$foto = fn_foto($pdo);
chequear('el efectivo neto sale sin explotar', is_float($foto['efectivo_neto']));
chequear('y el patrimonio tambien',            is_float($foto['patrimonio_neto']));

// ===========================================================================
echo "\n=== 9. Un rango vacio da cero en todo y no rompe ===\n";
$v = '2019-02-01';
chequear('ingresos 0',  fn_ingresos($pdo, $v, $v)['monto'] === 0.0);
chequear('activos 0',   fn_activos($pdo, $v, $v) === 0);
chequear('alertas []',  fn_alertas($pdo, $v, $v, 50000.0, 200000.0, 1000.0) === []);
chequear('top []',      fn_top_jugadores($pdo, $v, $v) === []);
chequear('detalle []',  fn_detalle_periodo($pdo, $v, $v) === []);

$limpiar();
echo "\n---------------------------------------\n$ok OK, $fail fallas\n";
exit($fail > 0 ? 1 : 0);
