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
/* Los `require __DIR__` del endpoint se recortan mas abajo, asi que las
   librerias que usa hay que traerlas aca. Sin esta, fn_stock() no encuentra
   cfg_crm(), lo captura como Throwable y devuelve null -- que es el degradado
   correcto en produccion, pero en el test tapaba lo que se queria probar. */
require __DIR__ . '/api/config_crm.php';

/* Se parte el archivo en dos: las FUNCIONES (todo lo que hay antes del
   despacho HTTP) y el DESPACHO. Las funciones se evaluan una sola vez; el
   despacho, una vez por cada accion que se quiera probar.

   POR QUE VALE LA PENA ESTA GIMNASIA. Hasta el 14/09/2026 este test solo
   probaba las funciones, y por eso se le escapo entera una: el bloque `rango`
   usaba $prevDesde/$prevHasta, que son variables LOCALES de otras dos
   funciones y no existen ahi. Con strict_types eso tira TypeError, el bloque
   se cae y la pantalla queda con los cuatro KPIs en "..." -- mientras los
   graficos, que salen de otro endpoint, cargan normal y hacen parecer que todo
   anda. `php -l` no lo ve y ningun test de funciones lo podia ver. */
$src = file_get_contents(__DIR__ . '/api/crm_finanzas.php');
$corte = strpos($src, '$metodo = $_SERVER');
if ($corte === false) { fwrite(STDERR, "No encontré el corte en crm_finanzas.php\n"); exit(1); }
$DESPACHO = substr($src, $corte);
$src = substr($src, 0, $corte);
$src = preg_replace('/^\s*<\?php/', '', $src, 1);
$src = preg_replace('/^\s*declare\(strict_types=1\);/m', '', $src, 1);
$src = preg_replace('/^\s*require(_once)?\s+__DIR__[^;]+;/m', '', $src);
$src = preg_replace('/^\s*\$operador\s*=\s*exigir_operador\(\);/m', '', $src);
/* salir() manda headers y hace exit: en un test mataria el proceso en la
   primera accion. Se saca del archivo y se pone una que devuelve el dato. */
$src = preg_replace('/function salir\(\$data, int \$code = 200\): void\s*\{.*?\n\}/s', '', $src, 1);
eval($src);

class FinSalir extends Exception {
    public $data; public $code;
    public function __construct($data, $code) { parent::__construct('salir'); $this->data = $data; $this->code = $code; }
}
/* ANOTA Y DESPUES LANZA, y el orden importa. El despacho envuelve todo en un
   try/catch(Throwable) que convierte cualquier excepcion en "Error al
   consultar": si salir() solo lanzara, ese catch se comeria la respuesta BUENA
   y despues llamaria a salir() otra vez con el error. Anotando primero, la
   primera salida -- la de verdad -- queda guardada igual. */
function salir($data, int $code = 200): void {
    $GLOBALS['__fin_salidas'][] = ['code' => $code, 'body' => $data];
    throw new FinSalir($data, $code);
}

/** Corre un endpoint de verdad y devuelve lo que habria contestado. */
function pedir(string $accion, array $params = []): array {
    global $DESPACHO, $pdo, $costoPorFicha, $umbralGrande, $umbralMuyGrande, $umbralGanador;
    $GLOBALS['__fin_salidas'] = [];
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_SESSION['finanzas_ok'] = true;
    $_GET = array_merge(['accion' => $accion], $params);
    $fatal = null;
    try {
        eval(preg_replace('/^\s*\$metodo = \$_SERVER[^;]+;/m', '$metodo = "GET";', $DESPACHO, 1));
    } catch (FinSalir $e) {
        // esperado
    } catch (Throwable $e) {
        $fatal = get_class($e) . ': ' . $e->getMessage();
    }
    if ($GLOBALS['__fin_salidas']) { return $GLOBALS['__fin_salidas'][0]; }
    return ['code' => 500, 'body' => ['ok' => false,
            'error' => $fatal ?? 'el endpoint no contesto nada']];
}

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
    try { $pdo->exec("DELETE FROM operaciones_panel WHERE username LIKE '" . U . "%'"); }
    catch (Throwable $e) { /* sin migración 67 */ }
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
$libro = function (string $u, string $cuando, float $monto, int $tipo = 1) use ($pdo) {
    static $id = 970000;
    $pdo->prepare(
        "INSERT INTO operaciones_panel (payment_id, tipo, username, monto, cuando)
         VALUES (?,?,?,?,?)"
    )->execute([++$id, $tipo, $u, $monto, $cuando]);
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

/* fn_serie_por_hora() devuelve [['hora'=>0,'cantidad'=>N], ...], no un mapa.
   Comparar $horas[10] (un array) contra 1 daba true siempre: en PHP un array
   es "mayor" que cualquier entero, asi que estas aserciones no probaban nada
   hasta que se corrigieron. */
$horas = fn_serie_por_hora($pdo, '2019-05-10', '2019-05-10');
$porHora = array_column($horas, 'cantidad', 'hora');
chequear('la carga por transferencia cae en su hora', ($porHora[10] ?? 0) >= 1,
         'h10=' . ($porHora[10] ?? 0));
chequear('la carga del juego tambien', ($porHora[11] ?? 0) >= 1,
         'h11=' . ($porHora[11] ?? 0));

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
echo "\n=== 8. La foto: patrimonio coherente y stock de verdad ===\n";
/* DOS COSAS ESTABAN MAL (14/09/2026):

   1. `stock_fichas` devolvia null con el comentario "pendiente M6.B" y la
      pantalla decia "N/A - pendiente integracion con bot" -- para un dato que
      `stock_agente.php` ya venia escribiendo cada 10 minutos desde el worker.

   2. El patrimonio era ingresos - retiros - fichas_de_los_jugadores. No
      descontaba lo que se le paga al proveedor POR LAS FICHAS, o sea que
      contaba como si las fichas fueran gratis. Es plata de verdad: el 20% de
      todo lo que se entrego. */
$pdo->exec("DELETE FROM operaciones_panel WHERE payment_id BETWEEN 970000 AND 979999");

$foto = fn_foto($pdo, 0.20);
chequear('sin libro no se puede descontar el costo, y se dice',
         $foto['patrimonio_exacto'] === false && $foto['costo_historico'] === null,
         json_encode([$foto['patrimonio_exacto'], $foto['costo_historico']]));

/* Con libro: el costo historico se descuenta.
   OJO CON EL ORDEN: la primera fila del libro tambien cambia de donde salen
   los retiros historicos (pasan de `acciones_saldo` al libro). Si se midiera
   el delta contra la foto sin libro, cambiarian DOS cosas a la vez y el
   numero no probaria nada. Por eso primero se ancla el libro con un retiro y
   recien despues se agrega la entrega que se quiere medir. */
$libro(U . 'h0', '2019-05-01 09:00:00', 1.0, 1);        // ancla: ya hay libro
$fotoBase = fn_foto($pdo, 0.20);
$libro(U . 'h1', '2019-05-01 10:00:00', 100000.0, 0);   // fichas entregadas
$foto2 = fn_foto($pdo, 0.20);
chequear('con libro, el patrimonio es exacto', $foto2['patrimonio_exacto'] === true);
chequear('y descuenta el costo de lo entregado',
         abs(($fotoBase['patrimonio_neto'] - $foto2['patrimonio_neto']) - 20000.0) < 0.01,
         'antes=' . $fotoBase['patrimonio_neto'] . ' ahora=' . $foto2['patrimonio_neto']);
chequear('el costo historico es el 20% de lo entregado',
         abs($foto2['costo_historico'] - 20000.0) < 0.01,
         (string)$foto2['costo_historico']);

/* Un RETIRO del libro no es una ficha entregada: no puede subir el costo. */
$libro(U . 'h2', '2019-05-02 10:00:00', 50000.0, 1);
$foto3 = fn_foto($pdo, 0.20);
chequear('los retiros no cuentan como fichas entregadas',
         abs($foto3['costo_historico'] - 20000.0) < 0.01,
         (string)$foto3['costo_historico']);

echo "\n=== 8b. El stock y para cuantos dias alcanza ===\n";
/* `dias` es lo que de verdad sirve: un umbral fijo ("avisame bajo 50.000") no
   sabe si eso son dos dias o dos meses. Es la metrica que habria evitado el
   12/09/2026, cuando la cuenta se quedo sin fichas y la plataforma empezo a
   rechazar depositos en silencio. */
$pdo->exec("DELETE FROM config_crm WHERE clave IN ('stock_fichas','stock_fichas_en')");
$GLOBALS['__cfg_crm_cache'] = null;
$s = fn_stock($pdo, 0.20);
chequear('sin lectura del worker, el stock es null (no cero)', $s['fichas'] === null);
chequear('y los dias tampoco se inventan', $s['dias'] === null);

/* cfg_crm() cachea en $GLOBALS por request -- correcto en produccion, donde
   cada request arranca limpio. Aca hay que vaciarlo a mano o se sigue leyendo
   lo de antes de escribir. */
$pdo->prepare("INSERT INTO config_crm (clave, valor) VALUES ('stock_fichas', '80000')
               ON DUPLICATE KEY UPDATE valor = VALUES(valor)")->execute();
$GLOBALS['__cfg_crm_cache'] = null;
$s = fn_stock($pdo, 0.20);
chequear('con lectura, trae el stock', abs((float)$s['fichas'] - 80000.0) < 0.01,
         json_encode($s['fichas']));
chequear('y lo que costo', abs((float)$s['valor'] - 16000.0) < 0.01, json_encode($s['valor']));

/* El ritmo sale de las ultimas dos semanas del libro. Se cargan entregas
   RECIENTES (no las de 2019, que quedan fuera de la ventana). */
$pdo->exec("DELETE FROM operaciones_panel WHERE payment_id BETWEEN 970000 AND 979999");
$s = fn_stock($pdo, 0.20);
chequear('sin entregas recientes no hay ritmo, y los dias son null',
         $s['dias'] === null, json_encode($s));

$libro(U . 'r1', date('Y-m-d H:i:s', strtotime('-2 days')), 70000.0, 0);
$s = fn_stock($pdo, 0.20);
chequear('con entregas, calcula el consumo diario',
         $s['consumo_dia'] !== null && abs((float)$s['consumo_dia'] - 5000.0) < 0.01,
         json_encode($s['consumo_dia']));
chequear('y cuantos dias aguanta el stock',
         $s['dias'] !== null && abs((float)$s['dias'] - 16.0) < 0.1,
         json_encode($s['dias']));

/* Dos semanas de ventana y no dos dias: el consumo es irregular y un fin de
   semana fuerte haria parecer que el stock se acaba mañana. */
$libro(U . 'r2', date('Y-m-d H:i:s', strtotime('-1 day')), 70000.0, 0);
$s = fn_stock($pdo, 0.20);
chequear('la ventana promedia, no toma el ultimo dia',
         abs((float)$s['consumo_dia'] - 10000.0) < 0.01, json_encode($s['consumo_dia']));

/* Una lectura que no es un numero NO se toma como cero: un stock de cero
   inventado es una alarma falsa, y una alarma falsa quema a las que vengan. */
$pdo->prepare("UPDATE config_crm SET valor = 'sin datos' WHERE clave = 'stock_fichas'")->execute();
$GLOBALS['__cfg_crm_cache'] = null;
$s = fn_stock($pdo, 0.20);
chequear('una lectura ilegible da null, no cero', $s['fichas'] === null, json_encode($s['fichas']));

$pdo->exec("DELETE FROM config_crm WHERE clave IN ('stock_fichas','stock_fichas_en')");
$pdo->exec("DELETE FROM operaciones_panel WHERE payment_id BETWEEN 970000 AND 979999");

// ===========================================================================
echo "\n=== 9. Los retiros salen del LIBRO del panel, no de nuestra cola ===\n";
/* POR QUE (14/09/2026). `acciones_saldo` es NUESTRA cola: solo tiene los
   retiros que el jugador pide por el chat. El que pide con el boton de adentro
   del juego, y el que el operador hace directo desde el panel, no pasan por
   ahi. Medido contra el panel sobre 60 dias: el libro tenia 44 retiros por
   $157.630 y Finanzas veia 5 por $692. O sea que la ganancia venia
   sobrestimada en casi todo lo que sale. */
$pdo->exec("DELETE FROM operaciones_panel WHERE payment_id BETWEEN 970000 AND 979999");

/* Sin libro: se sigue usando la cola, como toda la vida. Es el degradado
   correcto mientras el backfill no corrio. */
$r = fn_retiros($pdo, $D, $H);
chequear('sin libro usa la cola', ($r['fuente'] ?? '') === 'cola', json_encode($r));
chequear('y suma lo de la cola', abs($r['monto'] - 49000.0) < 0.01, 'monto=' . $r['monto']);

/* Con libro que CUBRE el periodo: manda el libro. */
$libro(U . 'juegoret', '2019-05-18 10:00:00', 80000.0);
$libro(U . 'viejo',    '2019-04-02 10:00:00',     1.0);   // ancla el inicio del libro
$r = fn_retiros($pdo, $D, $H);
chequear('con libro que cubre, manda el libro', ($r['fuente'] ?? '') === 'panel', json_encode($r));
chequear('suma SOLO lo del libro, no las dos fuentes',
         abs($r['monto'] - 80000.0) < 0.01, 'monto=' . $r['monto']);

/* EL PUNTO MAS IMPORTANTE: los retiros que ejecuta nuestro worker TAMBIEN
   quedan en el libro (verificado en produccion: las acciones 98, 39 y 29
   aparecen con el mismo minuto y monto). Si Finanzas sumara las dos fuentes
   los contaria dos veces, que es peor que subcontar. */
chequear('no suma la cola encima del libro',
         abs($r['monto'] - 80000.0) < 0.01 && $r['cantidad'] === 1,
         'cant=' . $r['cantidad'] . ' monto=' . $r['monto']);

/* Un DEPOSITO en el libro no es un retiro. */
$libro(U . 'dep', '2019-05-19 10:00:00', 5000.0, 0);
$r = fn_retiros($pdo, $D, $H);
chequear('los depositos del libro no cuentan como retiro',
         abs($r['monto'] - 80000.0) < 0.01, 'monto=' . $r['monto']);

/* Un periodo ANTERIOR a donde llega el libro cae a la cola. Cero no es un
   dato, es una ausencia: darlo por bueno convertiria un mes viejo en un mes
   de ganancia record. */
$r = fn_retiros($pdo, '2019-03-01', '2019-03-31');
chequear('un periodo que el libro no alcanza vuelve a la cola',
         ($r['fuente'] ?? '') === 'cola', json_encode($r));

/* El grafico por dia tiene que mirar lo mismo que el KPI, o la pantalla se
   contradice consigo misma. */
$serie = fn_serie_por_dia($pdo, '2019-05-18', '2019-05-18', 0.20);
chequear('el grafico por dia tambien sale del libro',
         abs($serie[0]['retiros'] - 80000.0) < 0.01, json_encode($serie[0]));
$porHora = array_column(fn_serie_por_hora($pdo, '2019-05-18', '2019-05-18'),
                        'cantidad', 'hora');
chequear('y el de por hora', ($porHora[10] ?? 0) === 1, 'h10=' . ($porHora[10] ?? 0));

$pdo->exec("DELETE FROM operaciones_panel WHERE payment_id BETWEEN 970000 AND 979999");

// ===========================================================================
echo "\n=== 9b. El costo de fichas es lo que REALMENTE salio del stock ===\n";
/* POR QUE (14/09/2026). Se estimaba como (ingresos + bonos) x costo_por_ficha:
   "el jugador pago $100, entonces entregamos 100 fichas". Esa cuenta deja
   afuera todo lo que el operador carga A MANO desde el panel, que no pasa por
   ninguna tabla nuestra.

   Medido ese dia en produccion: la plataforma entrego $53.750 en fichas y
   nuestra cola solo habia mandado $34.250. Los $19.500 de diferencia fueron
   tres cargas a mano, una de ellas al jugador del incidente documentado para
   Fauno -- al que el bot le desconto las fichas sin depositarlas y hubo que
   cargarselas de nuevo. El costo de ese bug lo pagaba el negocio sin aparecer
   en ningun numero. */
$pdo->exec("DELETE FROM operaciones_panel WHERE payment_id BETWEEN 970000 AND 979999");

/* Sin libro: la estimacion de siempre. */
$c = fn_costo_fichas($pdo, $D, $H, 0.20, 10000.0, 5000.0);
chequear('sin libro, estima como antes', abs($c - 3000.0) < 0.01, 'costo=' . $c);

/* Con libro que cubre: manda lo que la plataforma entrego. */
$libro(U . 'viejo',  '2019-04-02 10:00:00',     1.0, 0);   // ancla el inicio
$libro(U . 'carga1', '2019-05-20 10:00:00', 20000.0, 0);
$libro(U . 'carga2', '2019-05-21 10:00:00', 30000.0, 0);
$c = fn_costo_fichas($pdo, $D, $H, 0.20, 10000.0, 5000.0);
chequear('con libro, cuenta lo entregado de verdad', abs($c - 10000.0) < 0.01,
         'costo=' . $c);

/* EL PUNTO: una carga a mano SUBE el costo aunque no haya entrado un peso.
   Con la estimacion vieja era invisible. */
$libro(U . 'amano', '2019-05-22 10:00:00', 14500.0, 0);
$c = fn_costo_fichas($pdo, $D, $H, 0.20, 10000.0, 5000.0);
chequear('una carga a mano se nota en el costo', abs($c - 12900.0) < 0.01,
         'costo=' . $c);

/* Los RETIROS del libro no son fichas entregadas. */
$libro(U . 'ret', '2019-05-23 10:00:00', 99999.0, 1);
$c = fn_costo_fichas($pdo, $D, $H, 0.20, 10000.0, 5000.0);
chequear('los retiros del libro no cuentan como costo', abs($c - 12900.0) < 0.01,
         'costo=' . $c);

/* Un periodo que el libro no alcanza vuelve a estimar, no da cero: un costo
   de cero convertiria un mes viejo en un mes de ganancia perfecta. */
$c = fn_costo_fichas($pdo, '2019-03-01', '2019-03-31', 0.20, 10000.0, 5000.0);
chequear('un periodo fuera del libro vuelve a estimar', abs($c - 3000.0) < 0.01,
         'costo=' . $c);

// ===========================================================================
echo "\n=== 9c. El control de fichas fuera del sistema ===\n";
/* Las dos direcciones son un problema y hasta hoy ninguna se veia. */
$pdo->exec("DELETE FROM acciones_saldo WHERE usuario LIKE '" . U . "%'");
$cargar = function (string $u, string $cuando, float $monto) use ($pdo) {
    $pdo->prepare(
        "INSERT INTO acciones_saldo (usuario, tipo, monto, estado, creada_en, ejecutada_en)
         VALUES (?, 'cargar', ?, 'hecha', ?, ?)"
    )->execute([$u, $monto, $cuando, $cuando]);
};

/* Todo cuadra: la cola mando lo mismo que entrego la plataforma. */
$pdo->exec("DELETE FROM operaciones_panel WHERE payment_id BETWEEN 970000 AND 979999");
$libro(U . 'viejo', '2019-04-02 10:00:00', 1.0, 0);
$libro(U . 'ok',    '2019-05-20 10:00:00', 7500.0, 0);
$cargar(U . 'ok',   '2019-05-20 10:00:00', 7500.0);
$f = fn_fichas_fuera($pdo, $D, $H);
chequear('cuando cuadra, la diferencia es 0', $f && abs($f['diferencia']) < 0.01,
         json_encode($f));

/* De MAS: el operador cargo a mano. Salio del stock y nadie lo pago. */
$libro(U . 'amano', '2019-05-21 10:00:00', 14500.0, 0);
$f = fn_fichas_fuera($pdo, $D, $H);
chequear('una carga a mano da diferencia POSITIVA',
         $f && abs($f['diferencia'] - 14500.0) < 0.01, json_encode($f));

/* De MENOS, que es lo grave: la cola dice "hecha" y la plataforma no tiene
   registro. Es el bug del WAF, y cada peso ahi es un jugador al que le
   descontamos las fichas sin darselas. */
$pdo->exec("DELETE FROM operaciones_panel WHERE payment_id BETWEEN 970000 AND 979999");
$pdo->exec("DELETE FROM acciones_saldo WHERE usuario LIKE '" . U . "%'");
$libro(U . 'viejo',   '2019-04-02 10:00:00', 1.0, 0);
$cargar(U . 'fantasma', '2019-05-20 10:00:00', 5000.0);
$f = fn_fichas_fuera($pdo, $D, $H);
chequear('una entrega fantasma da diferencia NEGATIVA',
         $f && $f['diferencia'] < 0 && abs($f['diferencia'] + 5000.0) < 0.01,
         json_encode($f));

/* Sin libro que cubra, no se afirma nada: la ausencia de filas no prueba un
   descuadre, prueba que no estamos mirando. */
chequear('un periodo fuera del libro devuelve null',
         fn_fichas_fuera($pdo, '2019-03-01', '2019-03-31') === null);

$pdo->exec("DELETE FROM operaciones_panel WHERE payment_id BETWEEN 970000 AND 979999");
$pdo->exec("DELETE FROM acciones_saldo WHERE usuario LIKE '" . U . "%'");

// ===========================================================================
echo "\n=== 9d. La comision de la pasarela ===\n";
/* Quien cobra por transferencia se lleva un % de cada movimiento, y distinto
   segun la direccion: ~4% de lo que entra, ~1% de lo que sale. Con billeteras
   virtuales no se cobra nada, y ese es el default a proposito: cobrar una
   comision que no existe le haria ver a alguien una perdida inventada. */
$pdo->exec("DELETE FROM config_crm WHERE clave LIKE 'fin_comision%'");
$GLOBALS['__cfg_crm_cache'] = null;
$c = fn_comisiones($pdo, $D, $H, 100000.0, 50000.0);
chequear('sin configurar no descuenta nada', $c['total'] === 0.0, json_encode($c));
chequear('y lo dice', $c['fuente'] === 'ninguna', (string)$c['fuente']);

$pdo->exec("INSERT INTO config_crm (clave,valor) VALUES ('fin_comision_entrada','4'),('fin_comision_salida','1')
            ON DUPLICATE KEY UPDATE valor=VALUES(valor)");
$GLOBALS['__cfg_crm_cache'] = null;
$c = fn_comisiones($pdo, $D, $H, 100000.0, 50000.0);
chequear('cobra distinto por entrada y por salida',
         abs($c['entrada'] - 4000.0) < 0.01 && abs($c['salida'] - 500.0) < 0.01, json_encode($c));
chequear('y el total suma las dos', abs($c['total'] - 4500.0) < 0.01, json_encode($c['total']));

/* Un porcentaje negativo no puede REGALAR plata. */
$pdo->exec("UPDATE config_crm SET valor='-5' WHERE clave='fin_comision_entrada'");
$GLOBALS['__cfg_crm_cache'] = null;
$c = fn_comisiones($pdo, $D, $H, 100000.0, 50000.0);
chequear('un porcentaje negativo se ignora', $c['entrada'] === 0.0, json_encode($c));
$pdo->exec("DELETE FROM config_crm WHERE clave LIKE 'fin_comision%'");
$GLOBALS['__cfg_crm_cache'] = null;

// ===========================================================================
echo "\n=== 9e. Lo que deja un jugador en toda su vida ===\n";
/* ES EL NUMERO QUE DECIDE CUANTO SE PUEDE PAGAR POR TRAER UNO. Sin el solo se
   sabe lo que deja en su primera carga, que casi nunca cubre el costo -- y por
   eso mirarlo solo lleva a apagar campañas que funcionaban. */
$limpiar();
$pdo->exec("DELETE FROM operaciones_panel WHERE payment_id BETWEEN 970000 AND 979999");

/* Uno que deja plata: pago 10.000, se llevo 2.000, recibio 10.000 en fichas. */
$recarga(U . 'bueno', '2019-05-01 10:00:00', 10000.0);
$libro(U . 'bueno',   '2019-05-01 10:05:00', 10000.0, 0);
$libro(U . 'bueno',   '2019-05-02 10:00:00',  2000.0, 1);
/* Uno que gana: pago 1.000 y se llevo 9.000. */
$recarga(U . 'gana',  '2019-05-01 11:00:00',  1000.0);
$libro(U . 'gana',    '2019-05-01 11:05:00',  1000.0, 0);
$libro(U . 'gana',    '2019-05-03 10:00:00',  9000.0, 1);

$pj = fn_por_jugador($pdo, 0.20, 0.0, 0.0);
$mios = array_values(array_filter($pj['top'], fn($x) => str_starts_with($x['usuario'], U)));
chequear('cuenta los dos jugadores', $pj['jugadores'] >= 2, (string)$pj['jugadores']);

$porU = array_column(array_merge($pj['top'], $pj['peores']), 'ganancia', 'usuario');
chequear('el que deja plata da ganancia positiva',
         ($porU[U . 'bueno'] ?? 0) > 0, json_encode($porU[U . 'bueno'] ?? null));
chequear('y descuenta el costo de sus fichas',
         abs(($porU[U . 'bueno'] ?? 0) - 6000.0) < 0.01, json_encode($porU[U . 'bueno'] ?? null));
chequear('el que gana da NEGATIVO', ($porU[U . 'gana'] ?? 0) < 0,
         json_encode($porU[U . 'gana'] ?? null));

/* La comision baja la ganancia de cada jugador. */
$pj2 = fn_por_jugador($pdo, 0.20, 4.0, 1.0);
$porU2 = array_column(array_merge($pj2['top'], $pj2['peores']), 'ganancia', 'usuario');
chequear('con comision, el jugador deja menos',
         ($porU2[U . 'bueno'] ?? 0) < ($porU[U . 'bueno'] ?? 0),
         json_encode([$porU[U . 'bueno'] ?? null, $porU2[U . 'bueno'] ?? null]));

/* Quien nunca pago no es un jugador del negocio: una carga a mano no lo
   convierte en cliente. */
$libro(U . 'regalado', '2019-05-01 12:00:00', 5000.0, 0);
$pj3 = fn_por_jugador($pdo, 0.20, 0.0, 0.0);
$nombres = array_column(array_merge($pj3['top'], $pj3['peores']), 'usuario');
chequear('el que nunca pago no cuenta como jugador',
         !in_array(U . 'regalado', $nombres, true), json_encode($nombres));

echo "\n=== 9f. Concentracion: de cuantos jugadores depende el resultado ===\n";
/* El 13/09/2026 un solo retiro de $34.580 dio vuelta el dia entero. Si el
   resultado depende de tres jugadores, un mal dia de ellos borra el mes. */
chequear('mide la concentracion de los 5 mejores',
         $pj['concentracion_top5'] !== null && $pj['concentracion_top5'] > 0
         && $pj['concentracion_top5'] <= 100, json_encode($pj['concentracion_top5']));
chequear('separa los que dejan de los que ganan',
         $pj['dan_ganancia'] >= 1 && $pj['dan_perdida'] >= 1,
         json_encode([$pj['dan_ganancia'], $pj['dan_perdida']]));

echo "\n=== 9g. A los cuantos dias se paga solo un jugador ===\n";
$r = fn_recupero($pdo, 0.20, 0.0, 0.0, 30);
chequear('arma la curva dia por dia', count($r['dias']) > 0, json_encode(count($r['dias'])));
chequear('el dia 0 existe', ($r['dias'][0]['dia'] ?? -1) === 0, json_encode($r['dias'][0] ?? null));

/* LA CURVA ES ACUMULADA: nunca puede "olvidarse" de lo que ya paso. Con los
   datos de arriba, el dia 0 tiene solo las cargas y despues bajan por los
   retiros: lo que importa es que sea coherente, no que suba siempre. */
$g0 = $r['dias'][0]['ganancia'];
$ult = end($r['dias'])['ganancia'];
chequear('el acumulado del final incluye los retiros posteriores', $ult <= $g0,
         'dia0=' . $g0 . ' final=' . $ult);

/* Sin pauta cargada no hay con que comparar: el recupero es null, no cero. */
$pdo->exec("DELETE FROM gasto_diario WHERE landing_slug LIKE 't_fin%'");
$r2 = fn_recupero($pdo, 0.20, 0.0, 0.0, 30);
if ($r2['cpa'] === null) {
    chequear('sin pauta no se inventa un dia de recupero', $r2['dia_recupero'] === null);
} else {
    chequear('con pauta, el CPA es un numero', $r2['cpa'] > 0, json_encode($r2['cpa']));
}

echo "\n=== 9h. La bola de nieve ===\n";
/* Dos lineas por dia: lo que se gasto en pauta y lo que dejaron los jugadores
   que YA estaban. Cuando la segunda supera a la primera, la publicidad se
   financia sola. */
$serie = fn_bola_nieve($pdo, '2019-05-01', '2019-05-05', 0.20, 0.0, 0.0);
chequear('trae un punto por dia del rango', count($serie) === 5, (string)count($serie));
chequear('los acumulados solo crecen o se mantienen',
         $serie[4]['acum_pauta'] >= $serie[0]['acum_pauta'], json_encode(array_column($serie, 'acum_pauta')));

/* La primera carga de un jugador NO es base: no puede contarse como plata que
   llego sin gastar publicidad hoy. */
$dia1 = $serie[0];
chequear('el dia de las primeras cargas no suma a la base',
         abs($dia1['base']) < 0.01, json_encode($dia1));

/* Un dia sin nada no rompe ni deja huecos. */
chequear('un dia sin movimiento sale en cero, no falta',
         $serie[3]['fecha'] === '2019-05-04' && $serie[3]['ganancia'] == 0,
         json_encode($serie[3]));

$pdo->exec("DELETE FROM operaciones_panel WHERE payment_id BETWEEN 970000 AND 979999");
$limpiar();

// ===========================================================================
echo "\n=== 9i. Los ENDPOINTS contestan de verdad ===\n";
/* Cada accion se ejecuta completa, con sus variables y su armado de respuesta.
   Es lo unico que agarra un error del despacho -- una variable que no existe,
   una clave mal escrita, un tipo que no cierra. */
$hoyStr = date('Y-m-d');
$ayer   = date('Y-m-d', strtotime('-7 days'));

foreach ([
    ['foto',        []],
    ['hoy',         []],
    ['rango',       ['desde' => $ayer, 'hasta' => $hoyStr]],
    ['rango',       ['desde' => $ayer, 'hasta' => $hoyStr, 'filtro' => '7d']],
    ['graficos',    ['desde' => $ayer, 'hasta' => $hoyStr]],
    ['salud_pauta', ['desde' => $ayer, 'hasta' => $hoyStr]],
    ['evolucion',   ['desde' => $ayer, 'hasta' => $hoyStr]],
    ['export_json', ['desde' => $ayer, 'hasta' => $hoyStr]],
] as [$accion, $params]) {
    $r = pedir($accion, $params);
    $etiqueta = $accion . ($params['filtro'] ?? '' ? ' (con comparacion)' : '');
    chequear($etiqueta . ' contesta ok',
             ($r['body']['ok'] ?? false) === true,
             'code=' . $r['code'] . ' ' . substr((string)($r['body']['error'] ?? ''), 0, 120));
}

/* EL CASO QUE SE ESCAPO: `rango` con filtro pide ademas el periodo anterior, y
   ahi vivia el TypeError. Se prueba aparte y con los dos filtros que activan
   esa rama. */
foreach (['7d', '30d', 'mes'] as $f) {
    $r = pedir('rango', ['desde' => $ayer, 'hasta' => $hoyStr, 'filtro' => $f]);
    chequear("rango filtro=$f trae la comparacion",
             ($r['body']['ok'] ?? false) === true
             && array_key_exists('variacion', $r['body']),
             substr((string)($r['body']['error'] ?? ''), 0, 120));
}

/* Y que la respuesta traiga lo que la pantalla lee: si falta una clave, el
   front muestra "undefined" y nadie se entera hasta que alguien lo mira. */
$r = pedir('rango', ['desde' => $ayer, 'hasta' => $hoyStr]);
foreach (['ingresos','retiros','bonos','ganancia_bruta','costo_fichas','comisiones',
          'jugadores_activos','jugadores_nuevos','retencion','fichas_fuera'] as $k) {
    chequear("rango devuelve `$k`", array_key_exists($k, $r['body']['rango'] ?? []));
}

$r = pedir('evolucion', ['desde' => $ayer, 'hasta' => $hoyStr]);
foreach (['jugadores','recupero','serie'] as $k) {
    chequear("evolucion devuelve `$k`", array_key_exists($k, $r['body'] ?? []));
}

/* Una fecha invalida tiene que dar 400 y no un 500 con un stack adentro. */
$r = pedir('rango', ['desde' => 'ayer', 'hasta' => $hoyStr]);
chequear('una fecha invalida da 400, no un error interno',
         $r['code'] === 400 && ($r['body']['ok'] ?? true) === false, 'code=' . $r['code']);

// ===========================================================================
echo "\n=== 10. Un rango vacio da cero en todo y no rompe ===\n";
$v = '2019-02-01';
chequear('ingresos 0',  fn_ingresos($pdo, $v, $v)['monto'] === 0.0);
chequear('activos 0',   fn_activos($pdo, $v, $v) === 0);
chequear('alertas []',  fn_alertas($pdo, $v, $v, 50000.0, 200000.0, 1000.0) === []);
chequear('top []',      fn_top_jugadores($pdo, $v, $v) === []);
chequear('detalle []',  fn_detalle_periodo($pdo, $v, $v) === []);

$limpiar();
echo "\n---------------------------------------\n$ok OK, $fail fallas\n";
exit($fail > 0 ? 1 : 0);
